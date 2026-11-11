<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Atomic draft and confirmed billing operations; all money math stays decimal. */
class BillingService
{
    public function __construct(private readonly OrdersCounterService $counters, private readonly BillingProductionStageService $productionStages) {}

    public function create(array $ids, int $clientId, int $userId, string $discount, ?string $description): object
    {
        if (!$ids) throw new \InvalidArgumentException('Order list cannot be empty');
        $discount = $this->money($discount);
        $createdPath = null;
        try { return DB::transaction(function () use ($ids, $clientId, $userId, $discount, $description, &$createdPath) {
            $client = DB::table('clients')->where('id', $clientId)->lockForUpdate()->first();
            if (!$client) throw new \InvalidArgumentException('Client not found');
            if ((int) $client->user_id !== $userId) throw new \InvalidArgumentException('Client does not belong to this user');
            $orders = DB::table('orders')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($orders->count() !== count($ids)) throw new \InvalidArgumentException('One or more orders not found');
            $errors = [];
            $clients = $orders->pluck('client_id')->unique();
            if ($clients->count() > 1) $errors[] = 'Orders belong to multiple clients. All orders must belong to the same client';
            elseif ((int) $clients->first() !== $clientId) $errors[] = 'Not all orders belong to the specified client';
            $users = $orders->pluck('user_id')->unique();
            if ($users->count() > 1) $errors[] = 'Orders belong to multiple users. All orders must belong to the same user';
            elseif ((int) $users->first() !== $userId) $errors[] = 'Not all orders belong to the specified user';
            $billed = $orders->filter(fn($o) => (bool) $o->billed)->pluck('id')->all();
            if ($billed) $errors[] = 'The following orders are already billed and cannot be included in a new bill: '.implode(', ', $billed);
            $draftIds = DB::table('orders')->join('bills', 'bills.id', '=', 'orders.bill_id')->whereIn('orders.id', $ids)
                ->where('bills.status', 'DRAFT')->pluck('orders.id')->all();
            if ($draftIds) $errors[] = 'The following orders are already included in a draft bill: '.implode(', ', $draftIds);
            $notDelivered = $orders->filter(fn($o) => $o->currentStage !== 'Delivered')->pluck('id')->all();
            if ($notDelivered) $errors[] = 'The following orders are not in Delivered stage and cannot be billed: '.implode(', ', $notDelivered);
            if ($errors) throw new \InvalidArgumentException("Bill creation failed. Validation errors:\n".implode("\n", $errors));
            $subtotal = BigDecimal::zero();
            foreach ($orders as $order) $subtotal = $subtotal->plus((string) ($order->amount ?? '0'));
            $subtotal = $subtotal->toScale(2, RoundingMode::HALF_UP);
            if ($discount->isNegative()) throw new \InvalidArgumentException('Discount amount cannot be negative');
            if ($discount->isGreaterThan($subtotal)) throw new \InvalidArgumentException('Discount amount cannot exceed bill subtotal');
            $description = $description === null || trim($description) === '' ? null : trim($description);
            if ($description !== null && mb_strlen($description) > 1000) throw new \InvalidArgumentException('Bill description cannot exceed 1000 characters');
            $now = now();
            $id = DB::table('bills')->insertGetId(['date'=>$now, 'subtotal_amount'=>(string)$subtotal,
                'discount_amount'=>(string)$discount, 'total_amount'=>(string)$subtotal->minus($discount), 'description'=>$description,
                'status'=>'DRAFT', 'version'=>0, 'client_id'=>$clientId, 'user_id'=>$userId,
                'qr_code_path'=>null]);
            $filename = 'qr_'.Str::uuid().'.png';
            $bytes = (new PngWriter())->write(QrCode::create((string)$id)->setSize(300)->setMargin(10))->getString();
            $createdPath = 'uploads/'.$filename;
            if (!Storage::disk('local')->put($createdPath, $bytes)) throw new \RuntimeException('Failed to generate QR Code');
            $url = rtrim((string) config('app.url'), '/').'/files/'.$filename;
            DB::table('bills')->where('id',$id)->update(['qr_code_path'=>$url]);
            DB::table('orders')->whereIn('id',$ids)->update(['bill_id'=>$id, 'updated_at'=>$now]);
            return $this->bill($id);
        }); } catch (\Throwable $e) { if ($createdPath !== null) Storage::disk('local')->delete($createdPath); throw $e; }
    }

    public function updateDraft(int $id, int $userId, string $discount, ?string $description): object
    {
        return DB::transaction(function () use ($id,$userId,$discount,$description) {
            $bill = DB::table('bills')->where('id',$id)->where('user_id',$userId)->lockForUpdate()->first();
            if (!$bill) throw new \InvalidArgumentException('Bill not found');
            $this->draft($bill);
            $discount = $this->money($discount); $orders = DB::table('orders')->where('bill_id',$id)->get();
            $subtotal = BigDecimal::zero(); foreach ($orders as $o) $subtotal = $subtotal->plus((string)($o->amount ?? '0'));
            $subtotal = $subtotal->toScale(2,RoundingMode::HALF_UP);
            if ($discount->isNegative()) throw new \InvalidArgumentException('Discount amount cannot be negative');
            if ($discount->isGreaterThan($subtotal)) throw new \InvalidArgumentException('Discount amount cannot exceed bill subtotal');
            $description = $description === null || trim($description)==='' ? null : trim($description);
            if ($description !== null && mb_strlen($description)>1000) throw new \InvalidArgumentException('Bill description cannot exceed 1000 characters');
            DB::table('bills')->where('id',$id)->update(['subtotal_amount'=>(string)$subtotal,'discount_amount'=>(string)$discount,
                'total_amount'=>(string)$subtotal->minus($discount),'description'=>$description,'version'=>DB::raw('version + 1')]);
            return $this->bill($id);
        });
    }

    public function confirm(int $id, int $userId): object
    {
        return DB::transaction(function () use ($id,$userId) {
            $bill = DB::table('bills')->where('id',$id)->where('user_id',$userId)->lockForUpdate()->first();
            if (!$bill) throw new \InvalidArgumentException('Bill not found'); $this->draft($bill);
            if (BigDecimal::of((string)$bill->total_amount)->isLessThanOrEqualTo('0')) throw new \InvalidArgumentException('Bill final amount must be greater than zero');
            $orders = DB::table('orders')->where('bill_id',$id)->orderBy('id')->lockForUpdate()->get();
            $notDelivered=$orders->filter(fn($o)=>$o->currentStage!=='Delivered')->pluck('id')->all();
            if ($notDelivered) throw new \InvalidArgumentException('The following orders are not in Delivered stage and cannot be confirmed: '.implode(', ',$notDelivered));
            $already=$orders->filter(fn($o)=>(bool)$o->billed)->pluck('id')->all();
            if ($already) throw new \InvalidArgumentException('The following orders are already billed and cannot be confirmed again: '.implode(', ',$already));
            $now=now();
            foreach ($orders as $o) {
                $this->productionStages->transitionToBilled($userId, $o, $now);
                DB::table('orders')->where('id',$o->id)->update(['currentStage'=>'Billed','billed'=>true,'updated_at'=>$now]);
                if ($o->currentStage) $this->counters->decrement((string)$o->currentStage,$userId);
                $this->counters->increment('Billed',$userId);
                $this->balanceOrdersTotal($userId,(int)$o->client_id,(string)BigDecimal::of((string)($o->amount ?? '0'))->negated());
                if (\Illuminate\Support\Facades\Schema::hasTable('order_tracking')) DB::table('order_tracking')->insert(['order_id'=>$o->id,'user_id'=>$userId,'status'=>'Billed','tracking_time'=>$now]);
            }
            DB::table('bills')->where('id',$id)->update(['status'=>'CONFIRMED','confirmed_at'=>$now,'version'=>DB::raw('version + 1')]);
            app(\App\Services\ClientBalanceService::class)->recordBillReceivable($userId,(int)$bill->client_id,(string)$bill->total_amount,$id,$bill->description);
            DB::afterCommit(fn () => \App\Jobs\SendBillGeneratedNotifications::dispatch($userId, $id)->afterResponse());
            return $this->bill($id);
        });
    }

    public function delete(int $id, int $userId): void
    {
        DB::transaction(function () use ($id,$userId) {
            $bill=DB::table('bills')->where('id',$id)->where('user_id',$userId)->lockForUpdate()->first();
            if (!$bill) throw new \InvalidArgumentException('Bill not found');
            if ($bill->status === 'DRAFT') {
                DB::table('orders')->where('bill_id',$id)->update(['bill_id'=>null]); DB::table('bills')->where('id',$id)->delete();
                if ($bill->qr_code_path) DB::afterCommit(fn()=>app(OrderMediaCleanupService::class)->deleteCapturedFiles(['imageUrls'=>[$bill->qr_code_path]]));
                return;
            }
            app(\App\Services\ClientBalanceService::class)->deleteBillLedgerEntry($userId,(int)$bill->client_id,$id);
            $orders=DB::table('orders')->where('bill_id',$id)->lockForUpdate()->get();
            $media=app(OrderMediaCleanupService::class); $captured=[];
            foreach ($orders as $o) {
                if ($o->qrCodePath) $captured['imageUrls'][]=$o->qrCodePath;
                $captured[]=$media->captureOrderFiles((int)$o->id);
                foreach (['order_stage_assignments','worker_order_assignments','order_tracking','dashboard_order_refs','order_component_charges','order_images','order_voice_notes'] as $table)
                    if (\Illuminate\Support\Facades\Schema::hasTable($table)) DB::table($table)->where('order_id',$o->id)->delete();
                DB::table('orders')->where('id',$o->id)->delete();
            }
            if ($orders->count()) DB::table('orders_counter')->where('status','Billed')->where('user_id',$userId)
                ->update(['count'=>DB::raw('CASE WHEN count > '.$orders->count().' THEN count - '.$orders->count().' ELSE 0 END')]);
            DB::table('bills')->where('id',$id)->delete();
            DB::afterCommit(function () use ($media,$captured,$bill) {
                $media->deleteCapturedFiles(['imageUrls'=>array_merge($captured['imageUrls']??[],[$bill->qr_code_path]),'storageKeys'=>array_merge(...array_map(fn($v)=>$v['storageKeys']??[],$captured))]);
            });
        });
    }

    public function bill(int $id): ?object { return DB::table('bills')->where('id',$id)->first(); }
    public function orders(int $id): array { return DB::table('orders')->where('bill_id',$id)->orderBy('id')->get()->map(fn($o)=>$this->rawOrder($o))->all(); }
    public function listByUser(int $uid) { return DB::table('bills')->where('user_id',$uid)->orderBy('id')->get(); }
    public function listByClient(int $cid) { return DB::table('bills')->where('client_id',$cid)->orderBy('id')->get(); }
    public function generated(int $uid): array { $ids=DB::table('orders')->where('user_id',$uid)->where('currentStage','Delivered')->where(fn($q)=>$q->whereNull('billed')->orWhere('billed',false))->orderBy('id')->get()->groupBy('client_id'); $out=[]; foreach($ids as $client=>$rows) $out[]=$this->create($rows->pluck('id')->map(fn($x)=>(int)$x)->all(),(int)$client,$uid,'0',null); return $out; }

    private function rawOrder(object $o): array
    {
        $category=$o->category_id ? DB::table('categories')->where('id',$o->category_id)->first() : null;
        $images=\Illuminate\Support\Facades\Schema::hasTable('order_images') ? DB::table('order_images')->where('order_id',$o->id)->orderBy('display_order')->orderBy('id')->get()->map(fn($i)=>['id'=>(int)$i->id,'imageUrl'=>$i->image_url,'label'=>$i->label,'displayOrder'=>(int)$i->display_order])->all() : [];
        $voices=\Illuminate\Support\Facades\Schema::hasTable('order_voice_notes') ? DB::table('order_voice_notes')->where('order_id',$o->id)->orderBy('created_at')->orderBy('id')->get()->map(fn($n)=>['id'=>(int)$n->id,'storageKey'=>$n->storage_key,'mimeType'=>$n->mime_type,'label'=>$n->label,'durationSeconds'=>$n->duration_seconds===null?null:(int)$n->duration_seconds,'sizeBytes'=>$n->size_bytes===null?null:(int)$n->size_bytes,'createdAt'=>$n->created_at])->all() : [];
        $components=\Illuminate\Support\Facades\Schema::hasTable('order_component_charges') ? DB::table('order_component_charges')->where('order_id',$o->id)->orderBy('display_order')->orderBy('id')->get()->map(fn($c)=>['id'=>(int)$c->id,'componentNameSnapshot'=>$c->component_name_snapshot,'rateSnapshot'=>(float)$c->rate_snapshot,'quantitySnapshot'=>(int)$c->quantity_snapshot,'amount'=>(float)$c->amount,'displayOrder'=>(int)$c->display_order])->all() : [];
        $sqft=$o->totalSquareFeet===null ? (((float)$o->height*(float)$o->width*(int)$o->quantity)/144.0) : (float)$o->totalSquareFeet;
        $base=$o->baseAmount===null ? (float)BigDecimal::of((string)$sqft)->multipliedBy((string)$o->rate)->toScale(2,RoundingMode::HALF_UP) : (float)$o->baseAmount;
        $amount=$o->amount===null ? (float)BigDecimal::of((string)$base)->plus((string)($o->componentAmount??'0'))->toScale(2,RoundingMode::HALF_UP) : (float)$o->amount;
        return ['id'=>(int)$o->id,'description'=>$o->description,'height'=>(float)$o->height,'width'=>(float)$o->width,'quantity'=>(int)$o->quantity,
            'rate'=>(float)$o->rate,'workerRatePerSquareFoot'=>$o->workerRatePerSquareFoot===null?null:(float)$o->workerRatePerSquareFoot,
            'category'=>$category?['id'=>(int)$category->id,'name'=>$category->name,'active'=>(bool)$category->active,'createdAt'=>$category->created_at,'updatedAt'=>$category->updated_at]:null,
            'sequence'=>(int)$o->sequence,'orderId'=>$o->orderId,'imagePath'=>$o->imagePath,'images'=>$images,'voiceNotes'=>$voices,'componentCharges'=>$components,
            'currentStage'=>$o->currentStage,'qrCodePath'=>$o->qrCodePath,
            'deleiverdThorugh'=>$o->deleiverdThorugh,'amount'=>$amount,'baseAmount'=>$base,
            'componentAmount'=>$o->componentAmount===null?0.0:(float)$o->componentAmount,'totalSquareFeet'=>$sqft,
            'date'=>$o->date ? (new \DateTimeImmutable($o->date))->format('Y-m-d\TH:i:s'):null,'note'=>$o->note,'deadline'=>$o->deadline ? (new \DateTimeImmutable($o->deadline))->format('Y-m-d\TH:i:s'):null,'billed'=>(bool)$o->billed,
            'worker'=>null,'productionStageFlow'=>null,'assignedAt'=>$o->assigned_at ? (new \DateTimeImmutable($o->assigned_at))->format('Y-m-d\TH:i:s'):null];
    }
    private function money(string $v): BigDecimal { try { return BigDecimal::of($v)->toScale(2,RoundingMode::HALF_UP); } catch(\Throwable) { throw new \InvalidArgumentException('Invalid discount amount'); } }
    private function draft(object $bill): void { if ($bill->status!=='DRAFT') throw new \InvalidArgumentException('Only draft bills can be changed or confirmed'); }
    private function balanceOrdersTotal(int $u,int $c,string $delta): void { DB::table('client_balances')->insertOrIgnore(['user_id'=>$u,'client_id'=>$c,'currentBalance'=>0,'currentOrdersTotalAmount'=>0,'createdAt'=>now(),'updatedAt'=>now()]); $b=DB::table('client_balances')->where('user_id',$u)->where('client_id',$c)->lockForUpdate()->first(); $next=BigDecimal::of((string)$b->currentOrdersTotalAmount)->plus($delta)->toScale(2,RoundingMode::HALF_UP); DB::table('client_balances')->where('id',$b->id)->update(['currentOrdersTotalAmount'=>$next->isNegative()?'0.00':(string)$next,'updatedAt'=>now()]); }
}
