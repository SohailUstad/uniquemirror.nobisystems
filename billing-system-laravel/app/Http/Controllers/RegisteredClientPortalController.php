<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Throwable;

class RegisteredClientPortalController extends Controller
{
    private function clientId(Request $request): int { return (int)$request->attributes->get('registered_client_id'); }
    private function money(mixed $v): float { return (float)($v ?? 0); }
    private function isoDate(mixed $v): ?string { return $v ? Carbon::parse($v)->format('Y-m-d\\TH:i:s') : null; }
    private function billRow(object $b): array
    {
        $orders=DB::table('orders')->where('bill_id',$b->id)->orderBy('id')->get()->map(fn($o)=>['id'=>$o->id,'orderId'=>$o->orderId,'description'=>$o->description,'height'=>$o->height,'width'=>$o->width,'quantity'=>$o->quantity,'rate'=>$this->money($o->rate),'totalSquareFeet'=>$o->totalSquareFeet,'amount'=>$this->money($o->amount),'currentStage'=>$o->currentStage])->all();
        return ['id'=>$b->id,'date'=>$this->isoDate($b->date),'formattedDate'=>$b->date?date('d-m-Y H:i:s',strtotime($b->date)):'','subtotalAmount'=>$this->money($b->subtotal_amount),'discountAmount'=>$this->money($b->discount_amount),'totalAmount'=>$this->money($b->total_amount),'description'=>$b->description,'status'=>$b->status,'confirmedAt'=>$this->isoDate($b->confirmed_at),'clientId'=>$b->client_id,'clientName'=>$b->clientName,'businessUserId'=>$b->user_id,'orderCount'=>count($orders),'orders'=>$orders,'qrCodePath'=>$b->qr_code_path];
    }
    public function orders(Request $r): JsonResponse
    {
        $id=$this->clientId($r); $q=DB::table('orders as o')->join('clients as c','c.id','=','o.client_id')->leftJoin('categories as g','g.id','=','o.category_id')->where('c.registered_client_id',$id);
        $stageInputs=array_merge((array)$r->query('stage',[]),(array)$r->query('stages',[]));
        $stages=[]; foreach($stageInputs as $input) foreach(explode(',',(string)$input) as $value) { $value=trim($value); if($value!=='') $stages[]=$value; }
        $stages=array_values(array_unique($stages));
        $cats=array_values(array_unique(array_filter(array_map('intval',array_merge((array)$r->query('categoryId',[]),(array)$r->query('categoryIds',[]))))));
        if($stages)$q->whereIn('o.currentStage',$stages); if($cats)$q->whereIn('o.category_id',$cats);
        $rows=$q->orderByDesc('o.id')->get(['o.*','c.clientName','c.id as portal_client_id','g.name as categoryName']);
        $imageRows=DB::table('order_images')->whereIn('order_id',$rows->pluck('id'))->orderBy('display_order')->orderBy('id')->get()->groupBy('order_id');
        $orders=$rows->map(fn($o)=>['id'=>$o->id,'description'=>$o->description,'height'=>$o->height,'width'=>$o->width,'quantity'=>$o->quantity,'rate'=>$this->money($o->rate),'totalSquareFeet'=>$o->totalSquareFeet,'amount'=>$this->money($o->amount),'billed'=>(bool)$o->billed,'clientName'=>$o->clientName,'clientId'=>$o->portal_client_id,'businessUserId'=>$o->user_id,'categoryId'=>$o->category_id,'categoryName'=>$o->categoryName,'orderId'=>$o->orderId,'sequence'=>$o->sequence,'imagePath'=>$o->imagePath,'images'=>($imageRows[$o->id]??collect())->map(fn($image)=>['id'=>$image->id,'imageUrl'=>$image->image_url,'label'=>$image->label,'displayOrder'=>$image->display_order])->all(),'currentStage'=>$o->currentStage,'qrCodePath'=>$o->qrCodePath,'mode'=>$o->deleiverdThorugh,'date'=>$o->date?date('d M Y h:i A',strtotime($o->date)):null,'note'=>$o->note])->all();
        return response()->json(['registeredClientId'=>$id,'orderCount'=>count($orders),'orders'=>$orders]);
    }
    public function order(Request $r,int $orderId): JsonResponse
    {
        $copy=Request::create('/api/client/orders','GET'); $copy->attributes->set('registered_client_id',$this->clientId($r));
        $all=$this->orders($copy)->getData(true); foreach($all['orders'] as $order) if((int)$order['id']===$orderId)return response()->json(['order'=>$order]); return response()->json([],404);
    }
    private function billsFor(int $id) { return DB::table('bills as b')->join('clients as c','c.id','=','b.client_id')->where('c.registered_client_id',$id)->where('b.status','CONFIRMED'); }
    public function bills(Request $r): JsonResponse { $rows=$this->billsFor($this->clientId($r))->orderByDesc('b.date')->orderByDesc('b.id')->get(['b.*','c.clientName']);$items=$rows->map(fn($b)=>$this->billRow($b))->all();return response()->json(['registeredClientId'=>$this->clientId($r),'count'=>count($items),'bills'=>$items]); }
    public function bill(Request $r,int $billId): JsonResponse { $b=$this->billsFor($this->clientId($r))->where('b.id',$billId)->first(['b.*','c.clientName']);return $b?response()->json(['bill'=>$this->billRow($b)]):response()->json([],404); }
    public function payments(Request $r): JsonResponse { $rows=DB::table('payments as p')->join('clients as c','c.id','=','p.client_id')->where('c.registered_client_id',$this->clientId($r))->where('p.deleted',false)->orderByDesc('p.payment_date')->orderByDesc('p.created_at')->get(['p.*','c.clientName']);$items=$rows->map(fn($p)=>['id'=>$p->id,'clientId'=>$p->client_id,'clientName'=>$p->clientName,'businessUserId'=>$p->user_id,'amount'=>$this->money($p->amount),'paymentDate'=>$p->payment_date,'paymentMode'=>$p->payment_mode,'referenceNo'=>$p->reference_no,'note'=>$p->note,'createdAt'=>$this->isoDate($p->created_at)])->all();return response()->json(['registeredClientId'=>$this->clientId($r),'count'=>count($items),'payments'=>$items]); }
    public function receivables(Request $r): JsonResponse { $rows=DB::table('client_balances as b')->join('clients as c','c.id','=','b.client_id')->where('c.registered_client_id',$this->clientId($r))->orderBy('c.id')->get(['b.*','c.id as cid','c.clientName']);$items=$rows->map(fn($b)=>['clientId'=>$b->cid,'clientName'=>$b->clientName,'businessUserId'=>$b->user_id,'currentBalance'=>$this->money($b->currentBalance),'currentOrdersTotalAmount'=>max(0,$this->money($b->currentOrdersTotalAmount))])->all();return response()->json(['registeredClientId'=>$this->clientId($r),'count'=>count($items),'totalReceivable'=>array_sum(array_column($items,'currentBalance')),'currentOrdersTotalAmount'=>array_sum(array_column($items,'currentOrdersTotalAmount')),'receivables'=>$items]); }
    public function ledger(Request $r): JsonResponse { $rows=DB::table('client_balance_ledger as l')->join('clients as c','c.id','=','l.client_id')->where('c.registered_client_id',$this->clientId($r))->orderByDesc('l.entry_date')->orderByDesc('l.id')->get(['l.*','c.id as cid','c.clientName']);$items=$rows->map(fn($x)=>['id'=>$x->id,'clientId'=>$x->cid,'clientName'=>$x->clientName,'businessUserId'=>$x->user_id,'entryDate'=>$this->isoDate($x->entry_date),'entryType'=>$x->entry_type,'amount'=>$this->money($x->amount),'balanceAfter'=>$this->money($x->balance_after),'referenceType'=>$x->reference_type,'referenceId'=>$x->reference_id,'note'=>$x->note,'reversedAt'=>$this->isoDate($x->reversed_at),'reversalEntryId'=>$x->reversal_entry_id])->all();return response()->json(['registeredClientId'=>$this->clientId($r),'count'=>count($items),'ledger'=>$items]); }
    public function registerFcmToken(Request $r): JsonResponse { try { $token=trim((string)$r->input('token'));if($token==='')throw new \InvalidArgumentException('Token is required');$id=$this->clientId($r);if(!DB::table('registered_clients')->where('id',$id)->where('active',true)->exists())throw new \InvalidArgumentException('Client not found');$now=now();$hash=hash('sha256',$token);$existing=DB::table('registered_client_fcm_device_tokens')->where('token_hash',$hash)->first();$values=['registered_client_id'=>$id,'token'=>$token,'platform'=>trim((string)$r->input('platform'))?:null,'device_id'=>trim((string)$r->input('deviceId'))?:null,'user_agent'=>trim((string)($r->input('userAgent')??$r->userAgent()))?:null,'active'=>true,'last_seen_at'=>$now,'logged_out_at'=>null,'updated_at'=>$now];if($existing){DB::table('registered_client_fcm_device_tokens')->where('id',$existing->id)->update($values);$tokenId=$existing->id;}else{$values['token_hash']=$hash;$values['created_at']=$now;$tokenId=DB::table('registered_client_fcm_device_tokens')->insertGetId($values);}return response()->json(['message'=>'FCM token registered successfully','tokenId'=>$tokenId,'active'=>true]);}catch(Throwable $e){return response()->json(['error'=>$e->getMessage()],400);} }
    public function deactivateFcmToken(Request $r): JsonResponse { try {$token=trim((string)$r->input('token'));if($token==='')throw new \InvalidArgumentException('Token is required');$row=DB::table('registered_client_fcm_device_tokens')->where('token_hash',hash('sha256',$token))->first();if($row){if((int)$row->registered_client_id!==$this->clientId($r))throw new \InvalidArgumentException('Unauthorized: Token does not belong to this client');DB::table('registered_client_fcm_device_tokens')->where('id',$row->id)->update(['active'=>false,'logged_out_at'=>now(),'updated_at'=>now()]);}return response()->json(['message'=>'FCM token deactivated successfully']);}catch(Throwable $e){return response()->json(['error'=>$e->getMessage()],400);} }
}
