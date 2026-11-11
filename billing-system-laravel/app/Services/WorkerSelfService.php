<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class WorkerSelfService
{
    public function __construct(private readonly WorkerCompensationService $compensation, private readonly OrdersCounterService $counters) {}

    private function fail(string $message): never { throw new InvalidArgumentException($message); }
    public function worker(int $userId, int $workerId, bool $lock = false): object
    {
        $q = DB::table('workers')->where('user_id', $userId)->where('id', $workerId)->where('active', true);
        return ($lock ? $q->lockForUpdate() : $q)->first() ?? $this->fail('Worker not found');
    }
    public function ownedOrder(int $userId, int $orderId, bool $lock = false): object
    {
        $q = DB::table('orders')->where('user_id', $userId)->where('id', $orderId);
        return ($lock ? $q->lockForUpdate() : $q)->first() ?? $this->fail('Order not found');
    }
    public function assignedOrder(int $userId, int $workerId, int $orderId): object
    {
        $this->worker($userId, $workerId);
        $order = $this->ownedOrder($userId, $orderId);
        if ((int) $order->worker_id !== $workerId) $this->fail('This order is not assigned to you');
        return $order;
    }
    public function profile(int $userId, int $workerId): array
    {
        $w = $this->worker($userId, $workerId);
        return ['id'=>$w->id,'name'=>$w->name,'code'=>$w->code,'phoneNumber'=>$w->phone_number,'mustChangePassword'=>(bool)$w->must_change_password,'allowMultipleActiveOrders'=>(bool)$w->allow_multiple_active_orders];
    }
    public function orderMap(int $userId, object $o, bool $assigned = false): array
    {
        $client = DB::table('clients')->where('id', $o->client_id)->value('clientName');
        $category = $o->category_id ? DB::table('categories')->where('id', $o->category_id)->value('name') : null;
        $images = DB::table('order_images')->where('order_id', $o->id)->orderBy('display_order')->orderBy('id')->pluck('image_url')->all();
        $voice = DB::table('order_voice_notes')->where('order_id', $o->id)->get()->map(fn($n)=>['id'=>$n->id,'label'=>$n->label,'mimeType'=>$n->mime_type,'durationSeconds'=>$n->duration_seconds,'sizeBytes'=>$n->size_bytes,'createdAt'=>$n->created_at])->all();
        $w = $o->worker_id ? DB::table('workers')->where('id', $o->worker_id)->first() : null;
        $map = ['id'=>$o->id,'orderId'=>$o->orderId,'description'=>$o->description,'clientName'=>$client,'categoryId'=>$o->category_id,'categoryName'=>$category,'height'=>$o->height,'width'=>$o->width,'quantity'=>$o->quantity,'totalSquareFeet'=>(float)$this->squareFeet($o),'currentStage'=>$o->currentStage,'note'=>$o->note,'deadline'=>$o->deadline,'imagePath'=>$o->imagePath,'images'=>$images,'voiceNotes'=>$voice,'voiceNoteCount'=>count($voice),'sequence'=>$o->sequence,'workerId'=>$o->worker_id,'workerName'=>$w?->name,'assignedAt'=>$o->assigned_at,'date'=>$o->date];
        if ($assigned) {
            $a = DB::table('worker_order_assignments')->where('order_id', $o->id)->where('current_assignment', true)->first();
            if ($a) $map['compensation'] = $this->compensation->assignmentCompensation($a);
            $map['stageOptions'] = $this->stageOptions($userId, (int)$o->worker_id, (int)$o->id);
        }
        return $map;
    }
    public function claim(int $userId, int $workerId, int $orderId, bool $snapshot = true): object
    {
        return DB::transaction(function () use ($userId, $workerId, $orderId, $snapshot) {
            $w = $this->worker($userId, $workerId, true);
            $o = $this->ownedOrder($userId, $orderId, true);
            if ($o->billed) $this->fail('This order is already billed and cannot be picked up');
            if (strcasecmp((string)$o->currentStage, 'Completed') === 0) $this->fail('Completed orders cannot be picked up');
            $a = DB::table('worker_order_assignments')->where('order_id', $orderId)->where('current_assignment', true)->lockForUpdate()->first();
            if ($a) {
                if ((int)$a->worker_id === $workerId) return $snapshot ? $this->compensation->snapshotAssignment($userId, (int)$a->id) : $a;
                $holder = DB::table('workers')->where('id', $a->worker_id)->value('name');
                $this->fail('Order is already assigned to '.$holder.'. Ask the owner to reassign it.');
            }
            if (!$w->allow_multiple_active_orders && DB::table('worker_order_assignments')->where('user_id', $userId)->where('worker_id', $workerId)->where('current_assignment', true)->exists()) $this->fail('Complete your current work before picking up new work');
            $now = now();
            DB::table('orders')->where('id', $orderId)->update(['worker_id'=>$workerId,'assigned_at'=>$now]);
            $id = DB::table('worker_order_assignments')->insertGetId(['user_id'=>$userId,'worker_id'=>$workerId,'order_id'=>$orderId,'assigned_at'=>$now,'current_assignment'=>true]);
            return $snapshot ? $this->compensation->snapshotAssignment($userId, $id) : DB::table('worker_order_assignments')->where('id', $id)->first();
        });
    }
    private function steps(int $userId, object $o): array
    {
        $flowId = $o->production_stage_flow_id ?: DB::table('users')->where('id', $userId)->value('default_production_stage_flow_id');
        if (!$flowId) $flowId = DB::table('production_stage_flows')->where('user_id',$userId)->where('name','Default')->value('id');
        if (!$flowId) return [];
        return DB::table('production_stage_flow_steps as s')->join('production_stages as p','p.id','=','s.stage_id')->where('s.flow_id',$flowId)->where('p.user_id',$userId)->orderBy('s.sequence')->orderBy('s.id')->get(['p.id','p.name','s.sequence'])->all();
    }
    private function nextStage(int $userId, object $o): ?object
    {
        $steps = $this->steps($userId, $o);
        if (!$steps) return null;
        $lastId = $o->last_completed_production_stage_id;
        if ($lastId) {
            $flowId = $o->production_stage_flow_id ?: DB::table('users')->where('id',$userId)->value('default_production_stage_flow_id');
            if (!$flowId) $flowId=DB::table('production_stage_flows')->where('user_id',$userId)->where('name','Default')->value('id');
            if ((int)$o->flow_progress_flow_id !== (int)$flowId) return null;
            foreach ($steps as $i=>$s) if ((int)$s->id === (int)$lastId) return $steps[$i+1] ?? null;
            return null;
        }
        return $steps[0];
    }
    public function stageOptions(int $userId, int $workerId, int $orderId): array
    {
        $this->worker($userId, $workerId); $o = $this->ownedOrder($userId, $orderId);
        $next = $this->nextStage($userId, $o); if (!$next) return [];
        $mapped = DB::table('worker_stage_mappings')->where('user_id',$userId)->where('worker_id',$workerId)->pluck('stage_id')->map(fn($n)=>(int)$n)->all();
        $out = []; $collect = false;
        foreach ($this->steps($userId, $o) as $s) {
            if ((int)$s->id === (int)$next->id) $collect = true;
            if (!$collect) continue;
            if (!in_array((int)$s->id, $mapped, true)) break;
            $out[] = ['id'=>$s->id,'name'=>$s->name,'sequence'=>$s->sequence];
        }
        return $out;
    }
    private function queue(int $userId, int $workerId): array
    {
        $mapped = DB::table('worker_stage_mappings')->where('user_id',$userId)->where('worker_id',$workerId)->get()->keyBy('stage_id');
        $entries = [];
        foreach (DB::table('orders')->where('user_id',$userId)->where('billed',false)->get() as $o) {
            $s = $this->nextStage($userId,$o); if (!$s || !isset($mapped[$s->id])) continue;
            if (DB::table('order_stage_assignments')->where('order_id',$o->id)->where('stage_id',$s->id)->whereIn('status',['PICKED','IN_PROGRESS'])->exists()) continue;
            $queued = DB::table('order_tracking')->where('order_id',$o->id)->max('tracking_time') ?: $o->date;
            $entries[] = ['order'=>$o,'stage'=>$s,'mapping'=>$mapped[$s->id],'queuedAt'=>$queued];
        }
        usort($entries, fn($a,$b)=>strcmp((string)$a['queuedAt'],(string)$b['queuedAt']) ?: ((int)$a['order']->id <=> (int)$b['order']->id));
        return $entries;
    }
    private function pay(int $userId, object $w, object $o, object $mapping): array
    {
        $global = DB::table('users')->where('id',$userId)->value('global_worker_rate_per_square_foot');
        $rate = $mapping->rate_per_square_foot ?? $w->rate_per_square_foot ?? $global;
        if ($rate === null) $this->fail('Set a stage, worker, or global worker rate before picking this order');
        $source = $mapping->rate_per_square_foot !== null ? 'WORKER_STAGE' : ($w->rate_per_square_foot !== null ? 'WORKER' : 'GLOBAL');
        $rate = $this->round((string)$rate,4);
        return [$rate,$source,$this->round(bcmul($this->squareFeet($o),$rate,8),2)];
    }
    private function squareFeet(object $order): string
    {
        return $order->totalSquareFeet !== null
            ? (string)$order->totalSquareFeet
            : (string)(((float)$order->height * (float)$order->width * (int)$order->quantity) / 144.0);
    }
    private function round(string $value, int $scale): string { return bcadd($value, '0.'.str_repeat('0',$scale).'5', $scale); }
    public function available(int $userId, int $workerId): array
    {
        $w=$this->worker($userId,$workerId); $this->reconcile($userId,$workerId);
        $blocked = !$w->allow_multiple_active_orders && (DB::table('worker_order_assignments')->where('user_id',$userId)->where('worker_id',$workerId)->where('current_assignment',true)->exists() || DB::table('order_stage_assignments')->where('user_id',$userId)->where('worker_id',$workerId)->whereIn('status',['PICKED','IN_PROGRESS'])->exists());
        $out=[];
        foreach ($this->queue($userId,$workerId) as $i=>$e) {
            $row=$this->orderMap($userId,$e['order']);
            [,,$amount]=$this->pay($userId,$w,$e['order'],$e['mapping']);
            $row += ['nextStageId'=>$e['stage']->id,'nextStageName'=>$e['stage']->name,'expectedPay'=>$amount,'queuedAt'=>$e['queuedAt'],'queuePosition'=>$i+1,'canPickup'=>!$blocked && ($w->allow_multiple_active_orders || $i===0)];
            if ($blocked) $row['pickupBlockedReason']='Complete your current work before picking up new work';
            $out[]=$row;
        }
        return $out;
    }
    private function release(int $userId, int $workerId, int $orderId): void
    {
        DB::table('worker_order_assignments')->where('user_id',$userId)->where('worker_id',$workerId)->where('order_id',$orderId)->where('current_assignment',true)->update(['current_assignment'=>false,'unassigned_at'=>now()]);
        DB::table('orders')->where('user_id',$userId)->where('id',$orderId)->where('worker_id',$workerId)->update(['worker_id'=>null,'assigned_at'=>null]);
    }
    private function reconcile(int $userId, int $workerId): void
    {
        foreach (DB::table('order_stage_assignments')->where('user_id',$userId)->where('worker_id',$workerId)->whereIn('status',['PICKED','IN_PROGRESS'])->get() as $a) {
            $o=$this->ownedOrder($userId,(int)$a->order_id); $next=$this->nextStage($userId,$o);
            if (!$o->billed && (int)$o->worker_id===$workerId && $next && (int)$next->id===(int)$a->stage_id) continue;
            DB::table('order_stage_assignments')->where('id',$a->id)->update(['status'=>'CANCELLED','updated_at'=>now()]);
            $this->release($userId,$workerId,(int)$o->id);
        }
    }
    public function myWork(int $userId, int $workerId): array
    {
        return DB::transaction(function () use ($userId,$workerId) {
            $this->worker($userId,$workerId); $this->reconcile($userId,$workerId);
            return DB::table('order_stage_assignments')->where('user_id',$userId)->where('worker_id',$workerId)->whereIn('status',['PICKED','IN_PROGRESS'])->orderByDesc('picked_at')->orderByDesc('id')->get()->map(fn($a)=>$this->assignmentMap($userId,$a))->all();
        });
    }
    public function pickup(int $userId, int $workerId, int $orderId): object
    {
        return DB::transaction(function () use ($userId,$workerId,$orderId) {
            $w=$this->worker($userId,$workerId,true); $o=$this->ownedOrder($userId,$orderId,true);
            if (!$w->allow_multiple_active_orders && DB::table('order_stage_assignments')->where('user_id',$userId)->where('worker_id',$workerId)->whereIn('status',['PICKED','IN_PROGRESS'])->exists()) $this->fail('Complete your current work before picking up new work');
            if ($o->billed) $this->fail('This order is already billed and cannot be picked up');
            $s=$this->nextStage($userId,$o) ?? $this->fail('No next stage is available for this order');
            $m=DB::table('worker_stage_mappings')->where('user_id',$userId)->where('worker_id',$workerId)->where('stage_id',$s->id)->first() ?? $this->fail('This stage is not assigned to you');
            if (!$w->allow_multiple_active_orders) { $queue=$this->queue($userId,$workerId); if ($queue && (int)$queue[0]['order']->id!==$orderId) $this->fail('Pick up order #'.$queue[0]['order']->orderId.' first. Orders must be picked in the order they arrive.'); }
            $this->claim($userId,$workerId,$orderId,false);
            if (DB::table('order_stage_assignments')->where('order_id',$orderId)->where('stage_id',$s->id)->whereIn('status',['PICKED','IN_PROGRESS'])->exists()) $this->fail('This order was already picked up');
            [$rate,$source,$amount]=$this->pay($userId,$w,$o,$m);
            $id=DB::table('order_stage_assignments')->insertGetId(['user_id'=>$userId,'order_id'=>$orderId,'stage_id'=>$s->id,'stage_name_snapshot'=>$s->name,'worker_id'=>$workerId,'square_feet_snapshot'=>$this->round($this->squareFeet($o),4),'rate_snapshot'=>$rate,'rate_source'=>$source,'expected_amount'=>$amount,'status'=>'PICKED','picked_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
            return DB::table('order_stage_assignments')->where('id',$id)->first();
        });
    }
    private function assignment(int $userId,int $workerId,int $id): object
    {
        return DB::table('order_stage_assignments')->where('id',$id)->where('user_id',$userId)->where('worker_id',$workerId)->lockForUpdate()->first() ?? $this->fail('Stage assignment not found');
    }
    public function start(int $userId,int $workerId,int $id): object
    {
        return DB::transaction(function () use ($userId,$workerId,$id) {
            $a=$this->assignment($userId,$workerId,$id);
            if ($a->status==='COMPLETED') $this->fail('This work is already completed');
            if ($a->status==='CANCELLED') $this->fail('This work was cancelled');
            DB::table('order_stage_assignments')->where('id',$id)->update(['status'=>'IN_PROGRESS','started_at'=>$a->started_at ?: now(),'updated_at'=>now()]);
            return DB::table('order_stage_assignments')->where('id',$id)->first();
        });
    }
    public function complete(int $userId,int $workerId,int $id): object
    {
        return DB::transaction(function () use ($userId,$workerId,$id) {
            $a=$this->assignment($userId,$workerId,$id);
            if ($a->status==='COMPLETED') return $a;
            if ($a->status==='CANCELLED') $this->fail('This work was cancelled');
            $o=$this->ownedOrder($userId,(int)$a->order_id,true);
            $next=$this->nextStage($userId,$o);
            if ($o->billed || (int)$o->worker_id!==$workerId || !$next || (int)$next->id!==(int)$a->stage_id) $this->fail('This work was cancelled');
            $now=now();
            DB::table('order_stage_assignments')->where('id',$id)->update(['status'=>'COMPLETED','completed_at'=>$now,'earned_amount'=>$a->expected_amount,'updated_at'=>$now]);
            $this->compensation->recordStageCompletion($userId,$id);
            $flowId=$o->production_stage_flow_id ?: DB::table('users')->where('id',$userId)->value('default_production_stage_flow_id');
            if (!$flowId) $flowId=DB::table('production_stage_flows')->where('user_id',$userId)->where('name','Default')->value('id');
            DB::table('orders')->where('id',$o->id)->update(['currentStage'=>$a->stage_name_snapshot,'last_completed_production_stage_id'=>$a->stage_id,'flow_progress_flow_id'=>$flowId,'billed'=>$a->stage_name_snapshot==='Billed' ? true : (bool)$o->billed,'updated_at'=>$now]);
            if ($a->stage_name_snapshot==='Billed' && $o->currentStage!=='Billed') {
                DB::table('client_balances')->insertOrIgnore(['user_id'=>$userId,'client_id'=>$o->client_id,'currentBalance'=>'0.00','currentOrdersTotalAmount'=>'0.00','createdAt'=>$now,'updatedAt'=>$now]);
                $balance=DB::table('client_balances')->where('user_id',$userId)->where('client_id',$o->client_id)->lockForUpdate()->first();
                $remaining=bcsub((string)$balance->currentOrdersTotalAmount,(string)($o->amount ?? $o->baseAmount ?? 0),2);
                DB::table('client_balances')->where('user_id',$userId)->where('client_id',$o->client_id)->update(['currentOrdersTotalAmount'=>bccomp($remaining,'0',2)<0 ? '0.00' : $remaining,'updatedAt'=>$now]);
            }
            if ($o->currentStage) $this->counters->decrement($o->currentStage,$userId);
            $this->counters->increment($a->stage_name_snapshot,$userId);
            DB::table('order_tracking')->insert(['order_id'=>$o->id,'user_id'=>$userId,'status'=>$a->stage_name_snapshot,'tracking_time'=>$now]);
            $this->release($userId,$workerId,(int)$o->id);
            return DB::table('order_stage_assignments')->where('id',$id)->first();
        });
    }
    public function completeByStage(int $userId,int $workerId,int $orderId,string $stage): void
    {
        $this->assignedOrder($userId,$workerId,$orderId);
        $a=DB::table('order_stage_assignments')->where('user_id',$userId)->where('worker_id',$workerId)->where('order_id',$orderId)->where('stage_name_snapshot',$stage)->whereIn('status',['PICKED','IN_PROGRESS'])->first() ?? $this->fail('Pick up this assigned stage before completing it');
        $this->complete($userId,$workerId,(int)$a->id);
    }
    public function assignmentMap(int $userId,object $a): array
    {
        $map=$this->orderMap($userId,$this->ownedOrder($userId,(int)$a->order_id));
        return $map+['assignmentId'=>$a->id,'stageId'=>$a->stage_id,'stageName'=>$a->stage_name_snapshot,'status'=>$a->status,'pickedAt'=>$a->picked_at,'startedAt'=>$a->started_at,'completedAt'=>$a->completed_at,'squareFeet'=>$a->square_feet_snapshot,'appliedRate'=>$a->rate_snapshot,'rateSource'=>$a->rate_source,'expectedAmount'=>$a->expected_amount,'earnedAmount'=>$a->earned_amount];
    }
}
