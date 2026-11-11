<?php

namespace App\Http\Controllers;

use App\Services\NotesService;
use App\Services\WorkerCompensationService;
use App\Services\WorkerSelfService;
use App\Services\WorkerTargetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class WorkerSelfController
{
    public function __construct(private readonly WorkerSelfService $self, private readonly WorkerCompensationService $pay, private readonly WorkerTargetService $targets, private readonly NotesService $notes) {}
    private function ids(Request $request): array
    {
        $workerId=$request->attributes->get('worker_id');
        $email=$request->attributes->get('jwt_subject');
        if (!is_numeric($workerId) || !is_string($email)) throw new \InvalidArgumentException('Worker authentication is required');
        $userId=DB::table('users')->where('email',$email)->value('id');
        if (!$userId) throw new \InvalidArgumentException('User not found');
        return [(int)$userId,(int)$workerId];
    }
    private function respond(callable $action, int $status=200): JsonResponse
    {
        try { return response()->json($action(),$status); }
        catch (Throwable $e) { return response()->json(['error'=>$e->getMessage() ?: 'Request failed'],400); }
    }
    public function me(Request $r): JsonResponse { return $this->respond(function () use ($r) { [$u,$w]=$this->ids($r); return ['worker'=>$this->self->profile($u,$w)]; }); }
    public function myOrders(Request $r): JsonResponse
    {
        return $this->respond(function () use ($r) {
            [$u,$w]=$this->ids($r); $this->self->worker($u,$w);
            $q=DB::table('orders')->where('user_id',$u)->where('worker_id',$w);
            if ($r->filled('categoryId')) $q->where('category_id',(int)$r->input('categoryId'));
            $stages=[]; foreach (['stages','stage'] as $key) foreach ((array)$r->input($key,[]) as $value) foreach (explode(',',(string)$value) as $stage) if (trim($stage)!=='') $stages[]=trim($stage);
            if ($stages) $q->whereIn('currentStage',array_unique($stages));
            $orders=$q->orderByDesc('assigned_at')->orderByDesc('id')->get()->map(fn($o)=>$this->self->orderMap($u,$o,true))->all();
            return ['count'=>count($orders),'orders'=>$orders];
        });
    }
    public function order(Request $r,int $orderId): JsonResponse { return $this->respond(function () use ($r,$orderId) { [$u,$w]=$this->ids($r); return ['order'=>$this->self->orderMap($u,$this->self->assignedOrder($u,$w,$orderId),true)]; }); }
    public function preview(Request $r,int $orderId): JsonResponse
    {
        return $this->respond(function () use ($r,$orderId) { [$u,$w]=$this->ids($r); $this->self->worker($u,$w); $o=$this->self->ownedOrder($u,$orderId); $map=$this->self->orderMap($u,$o,(int)$o->worker_id===$w); $map['assignedToMe']=(int)$o->worker_id===$w; $map['claimable']=$o->worker_id===null && !$o->billed; return ['order'=>$map]; });
    }
    public function claim(Request $r,int $orderId): JsonResponse { return $this->respond(function () use ($r,$orderId) { [$u,$w]=$this->ids($r); $this->self->claim($u,$w,$orderId); return ['message'=>'Order picked up successfully','order'=>$this->self->orderMap($u,$this->self->ownedOrder($u,$orderId),true)]; }); }
    public function available(Request $r): JsonResponse { return $this->respond(function () use ($r) { [$u,$w]=$this->ids($r); $orders=$this->self->available($u,$w); return ['count'=>count($orders),'orders'=>$orders]; }); }
    public function myWork(Request $r): JsonResponse { return $this->respond(function () use ($r) { [$u,$w]=$this->ids($r); $a=$this->self->myWork($u,$w); return ['count'=>count($a),'assignments'=>$a]; }); }
    public function stageOptions(Request $r,int $orderId): JsonResponse { return $this->respond(function () use ($r,$orderId) { [$u,$w]=$this->ids($r); $s=$this->self->stageOptions($u,$w,$orderId); return ['count'=>count($s),'stages'=>$s]; }); }
    public function pickup(Request $r,int $orderId): JsonResponse { return $this->respond(function () use ($r,$orderId) { [$u,$w]=$this->ids($r); return ['assignment'=>$this->self->assignmentMap($u,$this->self->pickup($u,$w,$orderId))]; }); }
    public function start(Request $r,int $assignmentId): JsonResponse { return $this->respond(function () use ($r,$assignmentId) { [$u,$w]=$this->ids($r); return ['assignment'=>$this->self->assignmentMap($u,$this->self->start($u,$w,$assignmentId))]; }); }
    public function complete(Request $r,int $assignmentId): JsonResponse { return $this->respond(function () use ($r,$assignmentId) { [$u,$w]=$this->ids($r); return ['assignment'=>$this->self->assignmentMap($u,$this->self->complete($u,$w,$assignmentId))]; }); }
    public function updateStage(Request $r,int $orderId): JsonResponse
    {
        return $this->respond(function () use ($r,$orderId) { [$u,$w]=$this->ids($r); $o=$this->self->assignedOrder($u,$w,$orderId); if ($o->billed) throw new \InvalidArgumentException('Cannot update an order that is already billed'); $stage=$r->input('stage'); if (!is_string($stage) || trim($stage)==='') throw new \InvalidArgumentException('Stage name is required'); $name=$stage==='Delivered' ? $r->input('mode') : $stage; $this->self->completeByStage($u,$w,$orderId,(string)$name); return ['message'=>'Stage updated successfully']; });
    }
    public function notes(Request $r,int $orderId): JsonResponse { return $this->respond(function () use ($r,$orderId) { [$u,$w]=$this->ids($r); $this->self->assignedOrder($u,$w,$orderId); return ['data'=>$this->notes->ownedByOrder($u,$orderId)]; }); }
    public function stats(Request $r): JsonResponse
    {
        return $this->respond(function () use ($r) { [$u,$w]=$this->ids($r); $worker=$this->self->worker($u,$w); $counts=DB::table('orders')->where('user_id',$u)->where('worker_id',$w)->selectRaw('COALESCE(currentStage, ?) as stage, COUNT(*) as total',['Unstaged'])->groupBy('currentStage')->pluck('total','stage')->all(); return ['workerId'=>$w,'workerName'=>$worker->name,'code'=>$worker->code,'phoneNumber'=>$worker->phone_number,'mustChangePassword'=>(bool)$worker->must_change_password,'ratePerSquareFoot'=>$worker->rate_per_square_foot,'allowMultipleActiveOrders'=>(bool)$worker->allow_multiple_active_orders,'totalAssigned'=>array_sum($counts),'stageCounts'=>$counts]; });
    }
    public function earnings(Request $r): JsonResponse { return $this->respond(function () use ($r) { [$u,$w]=$this->ids($r); return $this->pay->account($u,$w); }); }
    public function productivity(Request $r): JsonResponse
    {
        return $this->respond(function () use ($r) { [$u,$w]=$this->ids($r); if ($r->filled('date')) $start=$end=$r->input('date'); elseif (!$r->filled('startDate') && !$r->filled('endDate')) $start=$end=now()->toDateString(); else { if (!$r->filled('startDate') || !$r->filled('endDate')) throw new \InvalidArgumentException('Both startDate and endDate are required'); $start=$r->input('startDate'); $end=$r->input('endDate'); } foreach ([$start,$end] as $d) if (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$d) || !checkdate((int)substr($d,5,2),(int)substr($d,8,2),(int)substr($d,0,4))) throw new \InvalidArgumentException('Invalid date'); return $this->targets->progress($u,$w,$start,$end); });
    }
    public function requestPayout(Request $r): JsonResponse { return $this->respond(function () use ($r) { [$u,$w]=$this->ids($r); return ['request'=>$this->pay->payoutMap($this->pay->requestPayout($u,$w,$r->input('requestedAmount'),$r->input('note')))]; },201); }
    public function cancelPayout(Request $r,int $requestId): JsonResponse { return $this->respond(function () use ($r,$requestId) { [$u,$w]=$this->ids($r); return ['request'=>$this->pay->payoutMap($this->pay->cancelPayout($u,$w,$requestId))]; }); }
}
