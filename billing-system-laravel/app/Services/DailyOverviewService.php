<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class DailyOverviewService
{
    public function __construct(private WorkerTargetService $targets) {}

    private function base(int $uid, string $table, string $alias): Builder
    {
        return DB::table("$table as $alias")->where("$alias.user_id", $uid);
    }

    private function range(Builder $query, string $column, string $start, string $end): Builder
    {
        return $query->where($column, '>=', "$start 00:00:00")
            ->where($column, '<', CarbonImmutable::parse($end)->addDay()->toDateString().' 00:00:00');
    }

    private function flow(Builder $query, array $flows, string $column = 'o.production_stage_flow_id'): Builder
    {
        return $flows ? $query->whereIn($column, $flows) : $query;
    }

    private function rows(Builder $query, string $type, ?string $link = null): array
    {
        return $query->get()->map(function ($source) use ($type, $link): array {
            $row = [];
            foreach ((array) $source as $key => $value) {
                $key = lcfirst(str_replace(' ', '', ucwords($key, '_')));
                $row[$key] = in_array($key, ['amount', 'squareFeet', 'appliedRate'], true) && $value !== null ? (float) $value : $value;
            }
            foreach (['occurredAt', 'confirmedAt'] as $key) if (isset($row[$key])) $row[$key] = str_replace(' ', 'T', $row[$key]);
            $row['type'] = $type;
            $row['href'] = $link === null ? null : $link.$row['id'];
            if ($type === 'STAGE' && $row['liveOrderId'] !== null) $row['href'] = '/orders/'.$row['liveOrderId'];
            if ($type === 'PAYOUT') $row['href'] = '/workers/'.$row['workerId'].'/account';
            return $row;
        })->all();
    }

    private function stages(int $uid, string $start, string $end, array $flows): array
    {
        $q = $this->range($this->base($uid, 'worker_ledger', 'l'), 'l.completed_at', $start, $end)
            ->join('workers as w', 'w.id', '=', 'l.worker_id')
            ->leftJoin('orders as o', fn ($j) => $j->on('o.id', '=', 'l.order_id_snapshot')->on('o.user_id', '=', 'l.user_id'))
            ->leftJoin('production_stage_flows as f', 'f.id', '=', 'o.production_stage_flow_id')
            ->where('l.entry_type', 'ORDER_EARNING')->where('l.reference_type', 'STAGE_ASSIGNMENT')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('worker_ledger as r')
                ->whereColumn('r.user_id', 'l.user_id')->whereColumn('r.worker_id', 'l.worker_id')
                ->whereColumn('r.reference_type', 'l.reference_type')->whereColumn('r.reference_id', 'l.reference_id')
                ->where('r.entry_type', 'ORDER_EARNING_REVERSAL'));
        return $this->rows($this->flow($q, $flows)->selectRaw('l.id, l.order_display_id as reference, l.order_description_snapshot as description, l.client_name_snapshot as party, l.stage_name_snapshot as status, l.completed_at as occurred_at, l.square_feet_snapshot as square_feet, l.amount, l.rate_snapshot as applied_rate, l.worker_id, w.name as worker_name, o.id as live_order_id, o.production_stage_flow_id, f.name as production_stage_flow_name')->orderByDesc('l.completed_at')->orderByDesc('l.id'), 'STAGE');
    }

    private function sum(array $rows, string $key): float
    {
        return array_reduce($rows, fn ($total, $row) => $total + (float) ($row[$key] ?? 0), 0.0);
    }

    public function overview(int $uid, string $start, string $end, array $flows = []): array
    {
        $orders = $this->rows($this->flow($this->range($this->base($uid, 'orders', 'o'), 'o.date', $start, $end)
            ->join('clients as c', 'c.id', '=', 'o.client_id')->leftJoin('production_stage_flows as f', 'f.id', '=', 'o.production_stage_flow_id'), $flows)
            ->selectRaw('o.id, o.orderId as reference, o.description, c.clientName as party, o.date as occurred_at, o.currentStage as status, o.production_stage_flow_id, f.name as production_stage_flow_name, coalesce(o.amount, coalesce(o.totalSquareFeet, o.height * o.width * o.quantity / 144.0, 0) * o.rate, 0) as amount, coalesce(o.totalSquareFeet, o.height * o.width * o.quantity / 144.0, 0) as square_feet')
            ->orderByDesc('o.date')->orderByDesc('o.id'), 'ORDER', '/orders/');
        $billQuery = fn () => $this->base($uid, 'bills', 'b')->join('clients as c', 'c.id', '=', 'b.client_id')
            ->selectRaw('b.id, b.id as reference, b.description, c.clientName as party, b.date as occurred_at, b.status, b.total_amount as amount, b.confirmed_at');
        $bills = $this->rows($this->range($billQuery(), 'b.date', $start, $end)->orderByDesc('b.date')->orderByDesc('b.id'), 'BILL', '/bills/');
        $confirmed = $this->rows($this->range($billQuery(), 'b.confirmed_at', $start, $end)->where('b.status', 'CONFIRMED')->orderByDesc('b.confirmed_at')->orderByDesc('b.id'), 'CONFIRMED_BILL', '/bills/');
        foreach ($confirmed as &$row) $row['occurredAt'] = $row['confirmedAt'];
        unset($row);
        $payments = $this->rows($this->base($uid, 'payments', 'p')->join('clients as c', 'c.id', '=', 'p.client_id')
            ->where('p.deleted', false)->whereBetween('p.payment_date', [$start, $end])
            ->selectRaw('p.id, p.reference_no as reference, p.note as description, c.clientName as party, p.payment_date as activity_date, p.payment_mode as status, p.amount')
            ->orderByDesc('p.created_at')->orderByDesc('p.id'), 'PAYMENT');
        $expenses = $this->rows($this->base($uid, 'expenses', 'e')->where('e.archived', false)->whereBetween('e.expense_date', [$start, $end])
            ->selectRaw('e.id, e.reference_no as reference, e.category_name as description, e.vendor_name as party, e.expense_date as activity_date, e.payment_status_name as status, e.amount')
            ->orderByDesc('e.created_at')->orderByDesc('e.id'), 'EXPENSE');
        $stages = $this->stages($uid, $start, $end, $flows);
        $today = now(config('app.business_time_zone', 'Asia/Kolkata'))->toDateString();
        $must = $this->rows($this->flow($this->base($uid, 'dashboard_order_refs', 'd')->join('orders as o', 'o.id', '=', 'd.order_id')
            ->join('clients as c', 'c.id', '=', 'o.client_id')->where('o.user_id', $uid)->where('d.dashboard_date', $today)
            ->where('d.must_complete', true)->where('o.billed', false)
            ->whereNotIn(DB::raw("lower(coalesce(o.currentStage, ''))"), ['completed', 'delivered', 'delivered via porter', 'client picked up', 'billed']), $flows)
            ->selectRaw('o.id, o.orderId as reference, o.description, c.clientName as party, o.currentStage as status')
            ->orderBy('d.priority')->orderBy('d.id'), 'ORDER', '/orders/');
        $awaiting = $this->rows($this->flow($this->base($uid, 'orders', 'o')->join('clients as c', 'c.id', '=', 'o.client_id')
            ->where('o.billed', false)->whereIn(DB::raw('lower(o.currentStage)'), ['delivered', 'delivered via porter', 'client picked up']), $flows)
            ->selectRaw('o.id, o.orderId as reference, o.description, c.clientName as party, o.currentStage as status, o.amount')
            ->orderBy('o.date')->orderBy('o.id'), 'ORDER', '/orders/');
        $drafts = $this->rows($this->base($uid, 'bills', 'b')->join('clients as c', 'c.id', '=', 'b.client_id')->where('b.status', 'DRAFT')
            ->selectRaw('b.id, b.id as reference, b.description, c.clientName as party, b.status, b.total_amount as amount')
            ->orderBy('b.date')->orderBy('b.id'), 'BILL', '/bills/');
        $payouts = $this->rows($this->base($uid, 'worker_payout_requests', 'p')->join('workers as w', 'w.id', '=', 'p.worker_id')->where('p.status', 'PENDING')
            ->selectRaw('p.id, p.id as reference, p.worker_note as description, w.name as party, p.worker_id, p.status, p.requested_amount as amount')
            ->orderBy('p.requested_at')->orderBy('p.id'), 'PAYOUT');
        return ['date' => $start === $end ? $start : null, 'startDate' => $start, 'endDate' => $end,
            'timeZone' => config('app.business_time_zone', 'Asia/Kolkata'), 'updatedAt' => now(config('app.business_time_zone', 'Asia/Kolkata'))->toIso8601String(),
            'productionStageFlowIds' => $flows, 'orders' => $orders, 'bills' => $bills, 'confirmedBills' => $confirmed,
            'payments' => $payments, 'expenses' => $expenses, 'stages' => $stages,
            'totals' => ['orders' => count($orders), 'orderValue' => $this->sum($orders, 'amount'), 'orderSquareFeet' => $this->sum($orders, 'squareFeet'),
                'bills' => count($bills), 'draftBills' => count(array_filter($bills, fn ($r) => $r['status'] === 'DRAFT')),
                'confirmedBills' => count($confirmed), 'billedValue' => $this->sum($confirmed, 'amount'),
                'payments' => count($payments), 'collected' => $this->sum($payments, 'amount'),
                'expenses' => count($expenses), 'expenseValue' => $this->sum($expenses, 'amount'),
                'stages' => count($stages), 'stageSquareFeet' => $this->sum($stages, 'squareFeet'),
                'workers' => count(array_unique(array_column($stages, 'workerId')))],
            'attention' => ['mustComplete' => $must, 'awaitingBill' => $awaiting, 'drafts' => $drafts, 'payouts' => $payouts]];
    }

    public function workers(int $uid, string $start, string $end, array $flows = []): array
    {
        $stages = $this->stages($uid, $start, $end, $flows);
        $paid = $this->range($this->base($uid, 'worker_ledger', 'l'), 'l.entry_date', $start, $end)
            ->where('l.entry_type', 'PAYOUT')->selectRaw('l.worker_id, -sum(l.amount) as paid')->groupBy('l.worker_id')->pluck('paid', 'worker_id');
        $progress = $this->targets->progress($uid, null, $start, $end);
        $opening = []; $targets = []; $closing = [];
        foreach ($progress['days'] as $day) foreach ($day['workers'] as $target) {
            $id = $target['workerId'];
            $opening[$id] ??= (float) $target['previousPendingSquareFeet'];
            $targets[$id] = ($targets[$id] ?? 0) + (float) $target['todayTargetSquareFeet'];
            $closing[$id] = (float) $target['carryForwardSquareFeet'];
        }
        $rows = [];
        foreach ($this->base($uid, 'workers', 'w')->select('w.id', 'w.name', 'w.active')->orderBy('w.name')->orderBy('w.id')->get() as $worker) {
            $completed = array_values(array_filter($stages, fn ($stage) => $stage['workerId'] == $worker->id));
            $payout = (float) ($paid[$worker->id] ?? 0);
            if ($flows && !$completed) continue;
            if (!$worker->active && !$completed && $payout == 0) continue;
            $pending = $opening[$worker->id] ?? 0; $target = $targets[$worker->id] ?? 0;
            $rows[] = ['id' => $worker->id, 'name' => $worker->name, 'stageCount' => count($completed),
                'squareFeet' => $this->sum($completed, 'squareFeet'), 'requiredSquareFeet' => $pending + $target,
                'targetSquareFeet' => $target, 'previousPendingSquareFeet' => $pending,
                'remainingSquareFeet' => $closing[$worker->id] ?? 0, 'earned' => $this->sum($completed, 'amount'),
                'paid' => $payout, 'stages' => $completed];
        }
        return ['date' => $start === $end ? $start : null, 'startDate' => $start, 'endDate' => $end,
            'timeZone' => config('app.business_time_zone', 'Asia/Kolkata'), 'productionStageFlowIds' => $flows, 'workers' => $rows];
    }
}
