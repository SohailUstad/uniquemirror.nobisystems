<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class WorkerCompensationService
{
    private function fail(string $message): never { throw new InvalidArgumentException($message); }
    private function money(mixed $value): string { return $this->round((string) ($value ?? '0'), 2); }
    private function rate(mixed $value, string $label): string
    {
        if ($value === null || !is_numeric($value) || bccomp((string)$value, '0', 4) < 0) $this->fail("$label cannot be negative");
        return $this->round((string)$value, 4);
    }
    private function positive(mixed $value, string $label): string
    {
        if ($value === null || !is_numeric($value) || bccomp((string)$value, '0', 2) <= 0) $this->fail("$label must be greater than zero");
        return $this->money($value);
    }
    private function clean(?string $value): ?string { $value = trim($value ?? ''); return $value === '' ? null : $value; }
    private function worker(int $userId, int $workerId, bool $active = true): object
    {
        $q = DB::table('workers')->where('id', $workerId)->where('user_id', $userId);
        if ($active) $q->where('active', true);
        return $q->first() ?? $this->fail('Worker not found');
    }
    private function accountLock(int $userId, int $workerId): object
    {
        // The worker row serializes first account creation as well as all balance changes.
        DB::table('workers')->where('id', $workerId)->where('user_id', $userId)->lockForUpdate()->first() ?? $this->fail('Worker not found');
        $account = DB::table('worker_accounts')->where('worker_id', $workerId)->where('user_id', $userId)->lockForUpdate()->first();
        if ($account) return $account;
        $id = DB::table('worker_accounts')->insertGetId(['user_id'=>$userId, 'worker_id'=>$workerId, 'current_balance'=>'0.00', 'created_at'=>now(), 'updated_at'=>now()]);
        return DB::table('worker_accounts')->where('id', $id)->lockForUpdate()->first();
    }
    private function changeBalance(int $userId, int $workerId, string $amount, array $entry): void
    {
        $account = $this->accountLock($userId, $workerId);
        if (isset($entry['reference_type'], $entry['reference_id']) && in_array($entry['entry_type'], ['ORDER_EARNING','ORDER_EARNING_REVERSAL'], true)) {
            if (DB::table('worker_ledger')->where('reference_type', $entry['reference_type'])->where('reference_id', $entry['reference_id'])->where('entry_type', $entry['entry_type'])->exists()) return;
        }
        $balance = bcadd($this->money($account->current_balance), $amount, 2);
        DB::table('worker_accounts')->where('id', $account->id)->update(['current_balance'=>$balance, 'updated_at'=>now()]);
        DB::table('worker_ledger')->insert(array_merge(['user_id'=>$userId, 'worker_id'=>$workerId, 'amount'=>$amount, 'balance_after'=>$balance, 'entry_date'=>now(), 'created_at'=>now()], $entry));
    }
    public function setGlobalRate(int $userId, mixed $value): string
    {
        return DB::transaction(function () use ($userId, $value) {
            $rate = $this->rate($value, 'Global worker rate');
            DB::table('users')->where('id', $userId)->update(['global_worker_rate_per_square_foot'=>$rate]);
            $ids = DB::table('worker_order_assignments')->where('user_id', $userId)->where('current_assignment', true)->whereNull('expected_amount')->pluck('id');
            foreach ($ids as $id) $this->snapshotAssignment($userId, (int)$id);
            return $rate;
        });
    }
    public function snapshotAssignment(int $userId, int $assignmentId): object
    {
        return DB::transaction(function () use ($userId, $assignmentId) {
            $a = DB::table('worker_order_assignments')->where('id', $assignmentId)->where('user_id', $userId)->lockForUpdate()->first() ?? $this->fail('Assignment not found');
            if ($a->expected_amount !== null) return $a;
            $w = $this->worker($userId, (int)$a->worker_id, false);
            $o = DB::table('orders')->where('id', $a->order_id)->where('user_id', $userId)->first() ?? $this->fail('Order not found');
            $u = DB::table('users')->where('id', $userId)->first();
            if ($o->workerRatePerSquareFoot !== null) { $rate = $this->rate($o->workerRatePerSquareFoot, 'Order worker rate'); $source = 'ORDER'; }
            elseif ($w->rate_per_square_foot !== null) { $rate = $this->rate($w->rate_per_square_foot, 'Worker rate'); $source = 'WORKER'; }
            elseif ($u->global_worker_rate_per_square_foot !== null) { $rate = $this->rate($u->global_worker_rate_per_square_foot, 'Global worker rate'); $source = 'GLOBAL'; }
            else $this->fail('Set a global, worker, or order worker rate before assigning this order');
            $feet = bcadd($o->totalSquareFeet !== null
                ? (string)$o->totalSquareFeet
                : (string)(((float)$o->height * (float)$o->width * (int)$o->quantity) / 144.0), '0', 4);
            $expected = $this->round(bcmul($feet, $rate, 6), 2);
            DB::table('worker_order_assignments')->where('id', $assignmentId)->update(['applied_worker_rate'=>$rate, 'rate_source'=>$source, 'square_feet_snapshot'=>$feet, 'expected_amount'=>$expected]);
            return DB::table('worker_order_assignments')->where('id', $assignmentId)->first();
        });
    }
    private function round(string $value, int $scale): string
    {
        $increment = '0.'.str_repeat('0', $scale).'5';
        return bcadd($value, str_starts_with($value, '-') ? '-'.$increment : $increment, $scale);
    }
    public function recalculateExpected(int $userId, int $assignmentId): object
    {
        return DB::transaction(function () use ($userId, $assignmentId) {
            $a = DB::table('worker_order_assignments')->where('id', $assignmentId)->where('user_id', $userId)->lockForUpdate()->first() ?? $this->fail('Assignment not found');
            if ($a->earned_at !== null) $this->fail('Earned compensation cannot be recalculated');
            DB::table('worker_order_assignments')->where('id', $assignmentId)->update(['applied_worker_rate'=>null, 'rate_source'=>null, 'square_feet_snapshot'=>null, 'expected_amount'=>null]);
            return $this->snapshotAssignment($userId, $assignmentId);
        });
    }
    public function recordStageCompletion(int $userId, int $stageAssignmentId): void
    {
        DB::transaction(function () use ($userId, $stageAssignmentId) {
            $a = DB::table('order_stage_assignments')->where('id', $stageAssignmentId)->where('user_id', $userId)->first();
            if (!$a || $a->status !== 'COMPLETED' || $a->completed_at === null || $a->earned_amount === null) $this->fail('A completed stage assignment is required');
            $o = DB::table('orders')->where('id', $a->order_id)->where('user_id', $userId)->first() ?? $this->fail('Order not found');
            $client = $o->client_id ? DB::table('clients')->where('id', $o->client_id)->value('clientName') : null;
            $this->changeBalance($userId, (int)$a->worker_id, $this->money($a->earned_amount), [
                'entry_type'=>'ORDER_EARNING', 'reference_type'=>'STAGE_ASSIGNMENT', 'reference_id'=>$stageAssignmentId,
                'order_id_snapshot'=>$o->id, 'order_display_id'=>$o->orderId, 'order_description_snapshot'=>$o->description,
                'client_name_snapshot'=>$client, 'stage_id_snapshot'=>$a->stage_id, 'stage_name_snapshot'=>$a->stage_name_snapshot,
                'square_feet_snapshot'=>$a->square_feet_snapshot, 'rate_snapshot'=>$a->rate_snapshot, 'rate_source'=>$a->rate_source,
                'completed_at'=>$a->completed_at, 'entry_date'=>$a->completed_at,
                'description'=>'Completed '.$a->stage_name_snapshot.' for order '.$o->orderId,
            ]);
        });
    }
    public function assertCanLeaveCompleted(int $userId, int $orderId): void
    {
        if (DB::table('worker_order_assignments')->where('user_id', $userId)->where('order_id', $orderId)->where('current_assignment', true)->whereNotNull('earned_at')->whereNull('earning_reversed_at')->exists()) $this->fail('Reverse the worker earning before moving this order out of Completed');
    }
    public function reverseEarning(int $userId, int $assignmentId, ?string $reason): void
    {
        DB::transaction(function () use ($userId, $assignmentId, $reason) {
            $a = DB::table('worker_order_assignments')->where('id', $assignmentId)->where('user_id', $userId)->lockForUpdate()->first() ?? $this->fail('Assignment not found');
            if ($a->earned_at === null || $a->earning_reversed_at !== null) $this->fail('This earning is not available to reverse');
            $o = DB::table('orders')->where('id', $a->order_id)->first();
            $this->changeBalance($userId, (int)$a->worker_id, bcmul($this->money($a->earned_amount), '-1', 2), ['entry_type'=>'ORDER_EARNING_REVERSAL', 'reference_type'=>'ASSIGNMENT', 'reference_id'=>$assignmentId, 'order_display_id'=>$o?->orderId, 'description'=>$this->clean($reason)]);
            DB::table('worker_order_assignments')->where('id', $assignmentId)->update(['earning_reversed_at'=>now()]);
        });
    }
    public function reverseOrderEarningsForReset(int $userId, int $orderId): void
    {
        DB::transaction(function () use ($userId, $orderId) {
            foreach (DB::table('worker_order_assignments')->where('user_id', $userId)->where('order_id', $orderId)->whereNotNull('earned_at')->whereNull('earning_reversed_at')->pluck('id') as $id) $this->reverseEarning($userId, (int)$id, 'Order reset');
            $entries = DB::table('worker_ledger')->where('user_id', $userId)->where('order_id_snapshot', $orderId)->where('reference_type', 'STAGE_ASSIGNMENT')->where('entry_type', 'ORDER_EARNING')->get();
            foreach ($entries as $entry) $this->changeBalance($userId, (int)$entry->worker_id, bcmul($this->money($entry->amount), '-1', 2), ['entry_type'=>'ORDER_EARNING_REVERSAL', 'reference_type'=>'STAGE_ASSIGNMENT', 'reference_id'=>$entry->reference_id, 'order_display_id'=>$entry->order_display_id, 'description'=>'Order reset']);
        });
    }
    public function requestPayout(int $userId, int $workerId, mixed $requested, ?string $note): object
    {
        return DB::transaction(function () use ($userId, $workerId, $requested, $note) {
            $this->worker($userId, $workerId);
            $amount = $this->positive($requested, 'Requested amount');
            $account = $this->accountLock($userId, $workerId);
            if (bccomp($this->money($account->current_balance), $amount, 2) < 0) $this->fail('Requested amount cannot exceed your available balance');
            if (DB::table('worker_payout_requests')->where('user_id', $userId)->where('worker_id', $workerId)->where('status', 'PENDING')->exists()) $this->fail('You already have a pending payout request');
            $id = DB::table('worker_payout_requests')->insertGetId(['user_id'=>$userId, 'worker_id'=>$workerId, 'requested_amount'=>$amount, 'worker_note'=>$this->clean($note), 'status'=>'PENDING', 'requested_at'=>now(), 'updated_at'=>now()]);
            return DB::table('worker_payout_requests')->where('id', $id)->first();
        });
    }
    private function pending(int $userId, int $requestId): object
    {
        $r = DB::table('worker_payout_requests')->where('id', $requestId)->where('user_id', $userId)->lockForUpdate()->first() ?? $this->fail('Payout request not found');
        if ($r->status !== 'PENDING') $this->fail('Payout request is already closed');
        return $r;
    }
    public function settlePayout(int $userId, int $requestId, mixed $actual, ?string $mode, ?string $reference, ?string $note): object
    {
        return DB::transaction(function () use ($userId, $requestId, $actual, $mode, $reference, $note) {
            $r = $this->pending($userId, $requestId);
            $amount = $this->positive($actual, 'Actual amount');
            $this->changeBalance($userId, (int)$r->worker_id, bcmul($amount, '-1', 2), ['entry_type'=>'PAYOUT', 'reference_type'=>'PAYOUT_REQUEST', 'reference_id'=>$requestId, 'description'=>'Payout request #'.$requestId]);
            DB::table('worker_payout_requests')->where('id', $requestId)->update(['status'=>'SETTLED', 'actual_amount'=>$amount, 'payment_mode'=>$this->clean($mode), 'reference_number'=>$this->clean($reference), 'admin_note'=>$this->clean($note), 'settled_at'=>now(), 'updated_at'=>now()]);
            return DB::table('worker_payout_requests')->where('id', $requestId)->first();
        });
    }
    public function rejectPayout(int $userId, int $requestId, ?string $note): object
    {
        return DB::transaction(function () use ($userId, $requestId, $note) {
            $this->pending($userId, $requestId);
            DB::table('worker_payout_requests')->where('id', $requestId)->update(['status'=>'REJECTED', 'admin_note'=>$this->clean($note), 'updated_at'=>now()]);
            return DB::table('worker_payout_requests')->where('id', $requestId)->first();
        });
    }
    public function cancelPayout(int $userId, int $workerId, int $requestId): object
    {
        return DB::transaction(function () use ($userId, $workerId, $requestId) {
            $r = $this->pending($userId, $requestId);
            if ((int)$r->worker_id !== $workerId) $this->fail('Payout request not found');
            DB::table('worker_payout_requests')->where('id', $requestId)->update(['status'=>'CANCELLED', 'updated_at'=>now()]);
            return DB::table('worker_payout_requests')->where('id', $requestId)->first();
        });
    }
    public function adjustBalance(int $userId, int $workerId, mixed $value, ?string $note): array
    {
        return DB::transaction(function () use ($userId, $workerId, $value, $note) {
            $this->worker($userId, $workerId);
            if ($value === null || !is_numeric($value)) $this->fail('Amount is required');
            $amount = $this->money($value);
            if (bccomp($amount, '0', 2) === 0) $this->fail('Adjustment amount cannot be zero');
            if ($this->clean($note) === null) $this->fail('Adjustment note is required');
            $this->changeBalance($userId, $workerId, $amount, ['entry_type'=>'MANUAL_ADJUSTMENT', 'reference_type'=>'MANUAL_ADJUSTMENT', 'description'=>$this->clean($note)]);
            return $this->summary($userId, $workerId);
        });
    }
    public function summary(int $userId, int $workerId): array
    {
        $w = $this->worker($userId, $workerId);
        $u = DB::table('users')->where('id', $userId)->first();
        $balance = $this->money(DB::table('worker_accounts')->where('user_id', $userId)->where('worker_id', $workerId)->value('current_balance'));
        $sums = DB::table('worker_ledger')->where('user_id', $userId)->where('worker_id', $workerId)->selectRaw('entry_type, SUM(amount) amount')->groupBy('entry_type')->pluck('amount', 'entry_type');
        $expected = DB::table('order_stage_assignments')->where('user_id', $userId)->where('worker_id', $workerId)->whereIn('status', ['PICKED','IN_PROGRESS'])->sum('expected_amount');
        return ['workerId'=>$workerId, 'workerName'=>$w->name, 'workerRatePerSquareFoot'=>$w->rate_per_square_foot, 'globalRatePerSquareFoot'=>$u->global_worker_rate_per_square_foot, 'expectedAmount'=>$this->money($expected), 'earnedAmount'=>bcadd($this->money($sums['ORDER_EARNING'] ?? null), $this->money($sums['ORDER_EARNING_REVERSAL'] ?? null), 2), 'paidAmount'=>ltrim($this->money($sums['PAYOUT'] ?? null), '-'), 'currentBalance'=>$balance, 'isAdvance'=>bccomp($balance, '0', 2) < 0];
    }
    public function account(int $userId, int $workerId): array
    {
        $response = $this->summary($userId, $workerId);
        $ledger = DB::table('worker_ledger')->where('user_id', $userId)->where('worker_id', $workerId)->orderByDesc('entry_date')->orderByDesc('id')->get();
        $assignments = DB::table('order_stage_assignments as a')->join('orders as o', 'o.id', '=', 'a.order_id')->leftJoin('clients as c', 'c.id', '=', 'o.client_id')->where('a.user_id', $userId)->where('a.worker_id', $workerId)->whereIn('a.status', ['PICKED','IN_PROGRESS'])->orderByDesc('a.picked_at')->orderByDesc('a.id')->get(['a.*','o.orderId as order_display_id','o.description','c.clientName as client_name'])->map(fn($a)=>['assignmentId'=>$a->id,'referenceType'=>'STAGE_ASSIGNMENT','orderId'=>$a->order_id,'orderDisplayId'=>$a->order_display_id,'description'=>$a->description,'clientName'=>$a->client_name,'stageId'=>$a->stage_id,'stageName'=>$a->stage_name_snapshot,'squareFeet'=>$a->square_feet_snapshot,'appliedRate'=>$a->rate_snapshot,'rateSource'=>$a->rate_source,'expectedAmount'=>$a->expected_amount,'earnedAmount'=>null,'assignedAt'=>$a->picked_at,'earnedAt'=>null,'completedAt'=>null,'current'=>true,'status'=>'EXPECTED'])->all();
        foreach ($ledger as $entry) if ($entry->entry_type === 'ORDER_EARNING') {
            $reversal = $ledger->first(fn($r)=>$r->entry_type === 'ORDER_EARNING_REVERSAL' && $r->reference_type === $entry->reference_type && $r->reference_id === $entry->reference_id);
            $row = $this->ledgerMap($entry); $row['description']=$entry->order_description_snapshot; $row['expectedAmount']=$entry->amount; $row['status']=$reversal ? 'REVERSED':'EARNED'; $row['reversedAt']=$reversal?->entry_date; $row['current']=false; $assignments[]=$row;
        }
        $response['assignments']=$assignments;
        $response['ledger']=$ledger->map(fn($e)=>$this->ledgerMap($e))->all();
        $response['payoutRequests']=$this->payoutQuery($userId)->where('p.worker_id', $workerId)->get()->map(fn($r)=>$this->payoutMap($r))->all();
        return $response;
    }
    private function ledgerMap(object $e): array
    {
        return ['id'=>$e->id,'entryType'=>$e->entry_type,'amount'=>$e->amount,'balanceAfter'=>$e->balance_after,'referenceType'=>$e->reference_type,'referenceId'=>$e->reference_id,'orderDisplayId'=>$e->order_display_id,'description'=>$e->description,'entryDate'=>$e->entry_date,'assignmentId'=>$e->reference_id,'orderId'=>$e->order_id_snapshot,'orderDescription'=>$e->order_description_snapshot,'clientName'=>$e->client_name_snapshot,'stageId'=>$e->stage_id_snapshot,'stageName'=>$e->stage_name_snapshot,'squareFeet'=>$e->square_feet_snapshot,'appliedRate'=>$e->rate_snapshot,'rateSource'=>$e->rate_source,'completedAt'=>$e->completed_at,'earnedAt'=>$e->completed_at,'earnedAmount'=>$e->entry_type === 'ORDER_EARNING' ? $e->amount : null];
    }
    private function payoutQuery(int $userId): \Illuminate\Database\Query\Builder
    { return DB::table('worker_payout_requests as p')->join('workers as w','w.id','=','p.worker_id')->where('p.user_id',$userId)->orderByDesc('p.requested_at')->select('p.*','w.name as worker_name'); }
    public function payoutRequests(int $userId): array { return $this->payoutQuery($userId)->get()->map(fn($r)=>$this->payoutMap($r))->all(); }
    public function payoutMap(object $r): array
    {
        $name = $r->worker_name ?? DB::table('workers')->where('id', $r->worker_id)->value('name');
        return ['id'=>$r->id,'workerId'=>$r->worker_id,'workerName'=>$name,'requestedAmount'=>$r->requested_amount,'actualAmount'=>$r->actual_amount,'status'=>$r->status,'workerNote'=>$r->worker_note,'adminNote'=>$r->admin_note,'paymentMode'=>$r->payment_mode,'referenceNumber'=>$r->reference_number,'requestedAt'=>$r->requested_at,'settledAt'=>$r->settled_at];
    }
    public function assignmentCompensation(object $a): array
    {
        $o = DB::table('orders')->where('id',$a->order_id)->first();
        return ['assignmentId'=>$a->id,'orderId'=>$a->order_id,'orderDisplayId'=>$o?->orderId,'description'=>$o?->description,'squareFeet'=>$a->square_feet_snapshot,'appliedRate'=>$a->applied_worker_rate,'rateSource'=>$a->rate_source,'expectedAmount'=>$a->expected_amount,'earnedAmount'=>$a->earned_amount,'assignedAt'=>$a->assigned_at,'earnedAt'=>$a->earned_at,'reversedAt'=>$a->earning_reversed_at,'current'=>(bool)$a->current_assignment,'status'=>$a->earning_reversed_at ? 'REVERSED':($a->earned_at ? 'EARNED':'EXPECTED')];
    }
}
