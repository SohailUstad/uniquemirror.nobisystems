<?php

namespace App\Http\Controllers;

use App\Services\WorkerCompensationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class WorkerCompensationController extends Controller
{
    public function __construct(private WorkerCompensationService $service) {}
    private function userId(Request $request): int
    {
        return (int)(DB::table('users')->where('email', $request->attributes->get('jwt_subject'))->value('id') ?? throw new InvalidArgumentException('User not found'));
    }
    private function run(callable $action): \Illuminate\Http\JsonResponse
    {
        try { return response()->json($action()); }
        catch (InvalidArgumentException $e) { return response()->json(['error'=>$e->getMessage()], 400); }
    }
    private function decimal(mixed $value): string
    {
        if ($value === null || trim((string)$value) === '' || !is_numeric($value)) throw new InvalidArgumentException('Amount is required');
        return (string)$value;
    }
    public function settings(Request $r): \Illuminate\Http\JsonResponse
    { return $this->run(fn()=>['globalRatePerSquareFoot'=>DB::table('users')->where('id',$this->userId($r))->value('global_worker_rate_per_square_foot') ?? '']); }
    public function updateSettings(Request $r): \Illuminate\Http\JsonResponse
    { return $this->run(fn()=>['globalRatePerSquareFoot'=>$this->service->setGlobalRate($this->userId($r),$this->decimal($r->input('globalRatePerSquareFoot')))]); }
    public function account(Request $r, int $workerId): \Illuminate\Http\JsonResponse
    { return $this->run(fn()=>$this->service->account($this->userId($r),$workerId)); }
    public function payoutRequests(Request $r): \Illuminate\Http\JsonResponse
    { return $this->run(fn()=>['requests'=>$this->service->payoutRequests($this->userId($r))]); }
    public function settle(Request $r, int $requestId): \Illuminate\Http\JsonResponse
    { return $this->run(fn()=>['request'=>$this->service->payoutMap($this->service->settlePayout($this->userId($r),$requestId,$this->decimal($r->input('actualAmount')),$r->input('paymentMode'),$r->input('referenceNumber'),$r->input('note')))]); }
    public function reject(Request $r, int $requestId): \Illuminate\Http\JsonResponse
    { return $this->run(fn()=>['request'=>$this->service->payoutMap($this->service->rejectPayout($this->userId($r),$requestId,$r->input('note')))]); }
    public function adjustment(Request $r, int $workerId): \Illuminate\Http\JsonResponse
    { return $this->run(fn()=>$this->service->adjustBalance($this->userId($r),$workerId,$this->decimal($r->input('amount')),$r->input('note'))); }
    public function recalculate(Request $r, int $assignmentId): \Illuminate\Http\JsonResponse
    { return $this->run(fn()=>['compensation'=>$this->service->assignmentCompensation($this->service->recalculateExpected($this->userId($r),$assignmentId))]); }
    public function reverse(Request $r, int $assignmentId): \Illuminate\Http\JsonResponse
    { return $this->run(function () use ($r,$assignmentId) { $this->service->reverseEarning($this->userId($r),$assignmentId,$r->input('reason')); return ['message'=>'Earning reversed']; }); }
}
