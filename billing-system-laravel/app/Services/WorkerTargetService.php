<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class WorkerTargetService
{
    private function add(string $a, string $b): string { return bcadd($a, $b, 4); }
    private function sub(string $a, string $b): string { return bcsub($a, $b, 4); }
    private function maxZero(string $value): string { return bccomp($value, '0', 4) > 0 ? $value : '0.0000'; }
    private function number(string $value): float { return (float) $value; }

    private function worker(int $uid, int $id): object
    {
        return DB::table('workers')->where('user_id', $uid)->where('id', $id)->where('active', true)->first() ?? throw new \InvalidArgumentException('Worker not found');
    }

    private function workers(int $uid, ?int $id): array
    {
        return $id === null ? DB::table('workers')->where('user_id', $uid)->where('active', true)->orderBy('name')->get()->all() : [$this->worker($uid, $id)];
    }

    private function future(string $date, string $field): void
    {
        if ($date < now()->toDateString()) throw new \InvalidArgumentException("$field cannot be before today; previous targets are locked");
    }

    private function maskDays(int $mask): array
    {
        return array_values(array_filter(range(1, 7), fn ($d) => ($mask & (1 << ($d - 1))) !== 0));
    }

    public function workingDays(int $uid): array
    {
        $global = (int) (DB::table('user_working_day_settings')->where('user_id', $uid)->value('working_day_mask') ?? 127);
        $settings = DB::table('worker_working_day_settings')->where('user_id', $uid)->pluck('working_day_mask', 'worker_id');
        return ['global' => ['workingDays' => $this->maskDays($global), 'usesGlobal' => false], 'workers' => array_map(function ($w) use ($settings, $global) {
            $custom = isset($settings[$w->id]);
            return ['workerId' => $w->id, 'workerName' => $w->name, 'workingDays' => $this->maskDays($custom ? (int) $settings[$w->id] : $global), 'usesGlobal' => !$custom];
        }, $this->workers($uid, null))];
    }

    public function setWorkingDays(int $uid, ?int $workerId, array $days): array
    {
        $mask = 0;
        foreach ($days as $d) {
            if ($d < 1 || $d > 7) throw new \InvalidArgumentException('Working days must be between 1 and 7');
            $mask |= 1 << ($d - 1);
        }
        if ($workerId === null) DB::table('user_working_day_settings')->updateOrInsert(['user_id' => $uid], ['working_day_mask' => $mask, 'updated_at' => now(), 'created_at' => now()]);
        else {
            $this->worker($uid, $workerId);
            DB::table('worker_working_day_settings')->updateOrInsert(['worker_id' => $workerId], ['user_id' => $uid, 'working_day_mask' => $mask, 'updated_at' => now(), 'created_at' => now()]);
        }
        return ['workingDays' => $this->maskDays($mask), 'usesGlobal' => false];
    }

    public function clearWorkingDays(int $uid, int $workerId): void
    {
        $this->worker($uid, $workerId);
        DB::table('worker_working_day_settings')->where('user_id', $uid)->where('worker_id', $workerId)->delete();
    }

    public function reset(int $uid, ?int $workerId, string $date): array
    {
        $this->future($date, 'Reset date'); $count = 0;
        foreach ($this->workers($uid, $workerId) as $w) {
            $count += DB::table('worker_target_resets')->insertOrIgnore(['user_id' => $uid, 'worker_id' => $w->id, 'reset_date' => $date, 'created_at' => now()]);
        }
        return ['resetDate' => $date, 'workerCount' => $count];
    }

    public function setTarget(int $uid, int $workerId, string $date, string $amount): array
    {
        $this->worker($uid, $workerId); $this->future($date, 'Target effective date');
        DB::table('worker_daily_targets')->updateOrInsert(['worker_id' => $workerId, 'effective_date' => $date], ['user_id' => $uid, 'target_square_feet' => $amount, 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('worker_daily_targets')->where('worker_id', $workerId)->where('effective_date', $date)->first();
        return ['id' => $row->id, 'effectiveDate' => $date, 'targetSquareFeet' => (float) $row->target_square_feet];
    }

    public function setOverride(int $uid, int $workerId, string $date, string $amount): array
    {
        $this->worker($uid, $workerId); $this->future($date, 'Override date');
        DB::table('worker_daily_target_overrides')->updateOrInsert(['worker_id' => $workerId, 'target_date' => $date], ['user_id' => $uid, 'target_square_feet' => $amount, 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('worker_daily_target_overrides')->where('worker_id', $workerId)->where('target_date', $date)->first();
        return ['id' => $row->id, 'date' => $date, 'targetSquareFeet' => (float) $row->target_square_feet];
    }

    public function deleteOverride(int $uid, int $workerId, string $date): void
    {
        $this->worker($uid, $workerId); $this->future($date, 'Override date');
        DB::table('worker_daily_target_overrides')->where('user_id', $uid)->where('worker_id', $workerId)->where('target_date', $date)->delete();
    }

    public function history(int $uid, int $workerId): array
    {
        $w = $this->worker($uid, $workerId);
        return ['workerId' => $w->id, 'workerName' => $w->name,
            'targets' => DB::table('worker_daily_targets')->where('user_id', $uid)->where('worker_id', $workerId)->orderByDesc('effective_date')->get()->map(fn ($r) => ['id' => $r->id, 'effectiveDate' => $r->effective_date, 'targetSquareFeet' => (float) $r->target_square_feet])->all(),
            'overrides' => DB::table('worker_daily_target_overrides')->where('user_id', $uid)->where('worker_id', $workerId)->orderByDesc('target_date')->get()->map(fn ($r) => ['id' => $r->id, 'date' => $r->target_date, 'targetSquareFeet' => (float) $r->target_square_feet])->all()];
    }

    public function progress(int $uid, ?int $workerId, string $start, string $end): array
    {
        if ($end < $start) throw new \InvalidArgumentException('End date cannot be before start date');
        if (CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)) >= 366) throw new \InvalidArgumentException('Date range cannot exceed 366 days');
        $workers = $this->workers($uid, $workerId); $result = ['startDate' => $start, 'endDate' => $end, 'days' => []];
        if (!$workers) return $result;
        $ids = array_map(fn ($w) => $w->id, $workers);
        $targets = DB::table('worker_daily_targets')->where('user_id', $uid)->whereIn('worker_id', $ids)->where('effective_date', '<=', $end)->orderBy('effective_date')->get();
        $overrides = DB::table('worker_daily_target_overrides')->where('user_id', $uid)->whereIn('worker_id', $ids)->where('target_date', '<=', $end)->get();
        $resets = DB::table('worker_target_resets')->where('user_id', $uid)->whereIn('worker_id', $ids)->where('reset_date', '<=', $end)->get();
        $globalMask = (int) (DB::table('user_working_day_settings')->where('user_id', $uid)->value('working_day_mask') ?? 127);
        $workerMasks = DB::table('worker_working_day_settings')->where('user_id', $uid)->whereIn('worker_id', $ids)->pluck('working_day_mask', 'worker_id');
        $calculationStart = $start;
        foreach ($targets as $r) $calculationStart = min($calculationStart, $r->effective_date);
        foreach ($overrides as $r) $calculationStart = min($calculationStart, $r->target_date);
        foreach ($resets as $r) $calculationStart = min($calculationStart, $r->reset_date);
        $completed = DB::table('worker_ledger as l')->where('l.user_id', $uid)->whereIn('l.worker_id', $ids)->where('l.entry_type', 'ORDER_EARNING')->where('l.completed_at', '>=', $calculationStart.' 00:00:00')->where('l.completed_at', '<', CarbonImmutable::parse($end)->addDay()->toDateString().' 00:00:00')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('worker_ledger as r')->whereColumn('r.user_id', 'l.user_id')->whereColumn('r.worker_id', 'l.worker_id')->whereColumn('r.reference_type', 'l.reference_type')->whereColumn('r.reference_id', 'l.reference_id')->where('r.entry_type', 'ORDER_EARNING_REVERSAL'))
            ->orderBy('l.completed_at')->orderBy('l.id')->select('l.*')->get()->groupBy(fn ($r) => $r->worker_id.':'.substr($r->completed_at, 0, 10));
        $pending = [];
        for ($day = CarbonImmutable::parse($calculationStart); $day->toDateString() <= $end; $day = $day->addDay()) {
            $date = $day->toDateString(); $rows = []; $totals = array_fill_keys(['target', 'pending', 'required', 'completed', 'carry', 'excess'], '0.0000');
            foreach ($workers as $w) {
                if ($resets->contains(fn ($r) => $r->worker_id == $w->id && $r->reset_date === $date)) $pending[$w->id] = '0.0000';
                $choice = $targets->filter(fn ($r) => $r->worker_id == $w->id && $r->effective_date <= $date)->last();
                $override = $overrides->first(fn ($r) => $r->worker_id == $w->id && $r->target_date === $date);
                $scheduled = $override ? (string) $override->target_square_feet : ($choice ? (string) $choice->target_square_feet : null);
                $source = $override ? 'OVERRIDE' : ($choice ? 'DEFAULT' : 'NONE'); $effective = $override?->target_date ?? $choice?->effective_date;
                $custom = isset($workerMasks[$w->id]); $mask = $custom ? (int) $workerMasks[$w->id] : $globalMask;
                $working = ($mask & (1 << ($day->dayOfWeekIso - 1))) !== 0;
                $entries = $completed[$w->id.':'.$date] ?? collect();
                $done = '0.0000';
                foreach ($entries as $entry) $done = $this->add($done, (string) ($entry->square_feet_snapshot ?? '0'));
                $previous = $working ? ($pending[$w->id] ?? '0.0000') : '0.0000'; $today = $working ? ($scheduled ?? '0.0000') : '0.0000';
                $required = $this->add($previous, $today); $carry = $working ? $this->maxZero($this->sub($required, $done)) : '0.0000'; $excess = $this->maxZero($this->sub($done, $required));
                if ($working) $pending[$w->id] = $carry;
                $status = !$working ? 'NON_WORKING_DAY' : (bccomp($required, '0', 4) === 0 ? 'NO_TARGET' : (bccomp($today, '0', 4) === 0 && bccomp($carry, '0', 4) > 0 ? 'CARRY_FORWARD' : (bccomp($done, '0', 4) === 0 ? 'NOT_STARTED' : (bccomp($done, $required, 4) > 0 ? 'TARGET_EXCEEDED' : (bccomp($done, $required, 4) === 0 ? 'TARGET_ACHIEVED' : 'IN_PROGRESS')))));
                if ($date >= $start) {
                    $rows[] = ['date' => $date, 'workerId' => $w->id, 'workerName' => $w->name, 'workerCode' => $w->code,
                        'targetSquareFeet' => $this->number($today), 'todayTargetSquareFeet' => $this->number($today), 'scheduledTargetSquareFeet' => $scheduled === null ? null : $this->number($scheduled),
                        'previousPendingSquareFeet' => $this->number($previous), 'totalRequiredSquareFeet' => $this->number($required), 'targetSource' => $source, 'targetEffectiveDate' => $effective,
                        'completedSquareFeet' => $this->number($done), 'remainingSquareFeet' => $this->number($carry), 'carryForwardSquareFeet' => $this->number($carry), 'excessSquareFeet' => $this->number($excess),
                        'workingDay' => $working, 'workingDaySource' => $custom ? 'WORKER' : 'GLOBAL', 'achievementPercentage' => bccomp($required, '0', 4) === 0 ? 0.0 : $this->number(bcdiv(bcadd(bcmul(bcdiv($done, $required, 12), '100', 12), '0.05', 12), '1', 1)),
                        'completedOrderCount' => $entries->pluck('order_id_snapshot')->unique()->count(), 'completedStageCount' => $entries->where('reference_type', 'STAGE_ASSIGNMENT')->count(), 'status' => $status,
                        'orders' => $entries->map(fn ($r) => ['assignmentId' => $r->reference_id, 'referenceType' => $r->reference_type, 'orderId' => $r->order_id_snapshot, 'orderDisplayId' => $r->order_display_id, 'description' => $r->order_description_snapshot, 'clientName' => $r->client_name_snapshot, 'stageId' => $r->stage_id_snapshot, 'stageName' => $r->stage_name_snapshot, 'earnedAmount' => (float) $r->amount, 'appliedRate' => $r->rate_snapshot === null ? null : (float) $r->rate_snapshot, 'rateSource' => $r->rate_source, 'squareFeet' => (float) ($r->square_feet_snapshot ?? 0), 'completedAt' => str_replace(' ', 'T', $r->completed_at)])->values()->all()];
                    foreach (['target' => $today, 'pending' => $previous, 'required' => $required, 'completed' => $done, 'carry' => $carry, 'excess' => $excess] as $key => $value) $totals[$key] = $this->add($totals[$key], $value);
                }
            }
            if ($date >= $start) $result['days'][] = ['date' => $date, 'totalTargetSquareFeet' => $this->number($totals['target']), 'totalTodayTargetSquareFeet' => $this->number($totals['target']), 'totalPreviousPendingSquareFeet' => $this->number($totals['pending']), 'totalRequiredSquareFeet' => $this->number($totals['required']), 'totalCompletedSquareFeet' => $this->number($totals['completed']), 'totalCarryForwardSquareFeet' => $this->number($totals['carry']), 'totalExcessSquareFeet' => $this->number($totals['excess']), 'workers' => $rows];
        }
        return $result;
    }
}
