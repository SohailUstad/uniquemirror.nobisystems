<?php

namespace App\Http\Controllers;

use App\Services\WorkerIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class WorkerController
{
    public function __construct(private readonly WorkerIdentityService $service) {}

    private function uid(Request $r): int { return $this->service->ownerId($r->attributes->get('jwt_subject')); }
    private function wrap(callable $fn): JsonResponse
    {
        try { return $fn(); } catch (\Throwable $e) { return response()->json(['error' => $e->getMessage()], 400); }
    }
    private function view(object $w): array
    {
        return ['id' => $w->id, 'name' => $w->name, 'phoneNumber' => $w->phone_number, 'code' => $w->code,
            'hasPassword' => $w->password_hash !== null, 'mustChangePassword' => (bool) $w->must_change_password,
            'lastLoginAt' => $w->last_login_at, 'active' => (bool) $w->active, 'ratePerSquareFoot' => $w->rate_per_square_foot,
            'allowMultipleActiveOrders' => (bool) $w->allow_multiple_active_orders,
            'createdAt' => $w->created_at, 'updatedAt' => $w->updated_at];
    }
    public function store(Request $r): JsonResponse { return $this->wrap(fn () => response()->json(['message' => 'Worker created successfully', 'worker' => $this->view($this->service->create($this->uid($r), $r->all()))], 201)); }
    public function index(Request $r): JsonResponse { return $this->wrap(function () use ($r) { $workers = DB::table('workers')->where('user_id', $this->uid($r))->where('active', true)->orderBy('name')->get()->map(fn ($w) => $this->view($w)); return response()->json(['count' => $workers->count(), 'workers' => $workers]); }); }
    public function show(Request $r, int $workerId): JsonResponse { return $this->wrap(fn () => response()->json(['worker' => $this->view($this->service->worker($this->uid($r), $workerId))])); }
    public function update(Request $r, int $workerId): JsonResponse { return $this->wrap(fn () => response()->json(['message' => 'Worker updated successfully', 'worker' => $this->view($this->service->update($this->uid($r), $workerId, $r->all()))])); }
    public function destroy(Request $r, int $workerId): JsonResponse { return $this->wrap(function () use ($r, $workerId) { $this->service->worker($this->uid($r), $workerId); DB::table('workers')->where('id', $workerId)->update(['active' => false, 'updated_at' => now()]); return response()->json(['message' => 'Worker deleted successfully']); }); }
    public function password(Request $r, int $workerId): JsonResponse { return $this->wrap(function () use ($r, $workerId) { $this->service->setPassword($this->uid($r), $workerId, $r->input('password')); return response()->json(['message' => 'Worker password updated successfully']); }); }
    public function assignments(Request $r, int $workerId): JsonResponse
    {
        return $this->wrap(function () use ($r, $workerId) {
            [$worker, $rows] = $this->service->assign($this->uid($r), $workerId, $r->input('orderIds'));
            $items = array_map(function ($a) use ($worker) { $order = DB::table('orders')->where('id', $a->order_id)->first(); return [
                'id' => $a->id, 'workerId' => $worker->id, 'workerName' => $worker->name, 'orderDbId' => $order->id,
                'formattedOrderId' => $order->orderId, 'assignedAt' => $a->assigned_at, 'current' => (bool) $a->current_assignment,
                'appliedRate' => $a->applied_worker_rate, 'rateSource' => $a->rate_source,
                'squareFeet' => $a->square_feet_snapshot, 'expectedAmount' => $a->expected_amount,
            ]; }, $rows);
            return response()->json(['message' => 'Orders assigned successfully', 'count' => count($items), 'assignments' => $items]);
        });
    }
    public function orders(Request $r, int $workerId): JsonResponse
    {
        return $this->wrap(function () use ($r, $workerId) {
            $uid = $this->uid($r); $this->service->worker($uid, $workerId);
            $q = DB::table('orders as o')->leftJoin('clients as c', 'c.id', '=', 'o.client_id')->leftJoin('categories as cat', 'cat.id', '=', 'o.category_id')
                ->where('o.user_id', $uid)->where('o.worker_id', $workerId)->select('o.*', 'c.clientName', 'cat.name as categoryName');
            if ($r->filled('categoryId')) {
                $id = (int) $r->input('categoryId');
                if (! DB::table('categories')->where('id', $id)->where('user_id', $uid)->exists()) throw new InvalidArgumentException('Category not found');
                $q->where('o.category_id', $id);
            }
            $stages = [];
            foreach (['stages', 'stage'] as $key) foreach ((array) $r->query($key, []) as $v) foreach (explode(',', (string) $v) as $part) if (trim($part) !== '') $stages[] = trim($part);
            if ($stages) $q->whereIn('o.currentStage', array_unique($stages));
            $items = $q->orderByDesc('o.assigned_at')->orderByDesc('o.id')->get()->map(fn ($o) => [
                'id' => $o->id, 'description' => $o->description, 'height' => $o->height, 'width' => $o->width,
                'quantity' => $o->quantity, 'rate' => $o->rate, 'workerRatePerSquareFoot' => $o->workerRatePerSquareFoot,
                'totalSquareFeet' => $o->totalSquareFeet, 'amount' => $o->amount, 'billed' => (bool) $o->billed,
                'clientName' => $o->clientName, 'clientId' => $o->client_id, 'categoryId' => $o->category_id,
                'categoryName' => $o->categoryName, 'workerId' => $o->worker_id, 'workerName' => $this->service->worker($uid, $workerId)->name,
                'assignedAt' => $o->assigned_at, 'orderId' => $o->orderId, 'sequence' => $o->sequence,
                'imagePath' => $o->imagePath, 'images' => DB::table('order_images')->where('order_id', $o->id)->orderBy('display_order')->orderBy('id')->get()->map(fn ($i) => ['id' => $i->id, 'imageUrl' => $i->image_url, 'label' => $i->label, 'displayOrder' => $i->display_order])->all(), 'currentStage' => $o->currentStage,
                'qrCodePath' => $o->qrCodePath, 'mode' => $o->deleiverdThorugh,
                'date' => $o->date ? date('d M Y h:i A', strtotime($o->date)) : null, 'note' => $o->note, 'deadline' => $o->deadline,
            ]);
            return response()->json(['message' => 'Worker orders retrieved successfully', 'count' => $items->count(), 'orders' => $items]);
        });
    }
    public function stats(Request $r): JsonResponse
    {
        return $this->wrap(function () use ($r) {
            $uid = $this->uid($r);
            $workers = DB::table('workers')->where('user_id', $uid)->where('active', true)->orderBy('name')->get()->map(function ($w) use ($uid) {
                $counts = DB::table('orders')->where('user_id', $uid)->where('worker_id', $w->id)
                    ->selectRaw('COALESCE(currentStage, ?) as stage, COUNT(*) as n', ['Unstaged'])->groupBy('currentStage')->get();
                $stageCounts = []; foreach ($counts as $row) $stageCounts[$row->stage] = (int) $row->n;
                return ['workerId' => $w->id, 'workerName' => $w->name, 'code' => $w->code, 'phoneNumber' => $w->phone_number,
                    'mustChangePassword' => (bool) $w->must_change_password, 'ratePerSquareFoot' => $w->rate_per_square_foot,
                    'allowMultipleActiveOrders' => (bool) $w->allow_multiple_active_orders, 'totalAssigned' => array_sum($stageCounts), 'stageCounts' => $stageCounts];
            });
            return response()->json(['count' => $workers->count(), 'workers' => $workers]);
        });
    }
    public function completedSummary(Request $r): JsonResponse
    {
        return $this->wrap(function () use ($r) {
            [$start, $end] = $this->range($r);
            $uid = $this->uid($r); $summaries = [];
            foreach (DB::table('workers')->where('user_id', $uid)->where('active', true)->orderBy('name')->get() as $w)
                $summaries[$w->id] = ['workerId' => $w->id, 'workerName' => $w->name, 'phoneNumber' => $w->phone_number, 'completedCount' => 0, 'orders' => []];
            $events = DB::table('worker_order_assignments as a')->join('order_tracking as t', 't.order_id', '=', 'a.order_id')
                ->join('orders as o', 'o.id', '=', 'a.order_id')->leftJoin('clients as c', 'c.id', '=', 'o.client_id')
                ->leftJoin('categories as cat', 'cat.id', '=', 'o.category_id')
                ->where('a.user_id', $uid)->where('t.user_id', $uid)->whereRaw('LOWER(t.status) = ?', ['completed'])
                ->where('t.tracking_time', '>=', $start.' 00:00:00')->where('t.tracking_time', '<', date('Y-m-d', strtotime($end.' +1 day')).' 00:00:00')
                ->whereColumn('t.tracking_time', '>=', 'a.assigned_at')
                ->where(fn ($q) => $q->whereNull('a.unassigned_at')->orWhereColumn('t.tracking_time', '<', 'a.unassigned_at'))
                ->select('a.*', 't.tracking_time', 'o.orderId', 'o.description', 'o.client_id', 'o.category_id', 'o.currentStage', 'o.quantity', 'o.totalSquareFeet', 'o.amount', 'c.clientName', 'cat.name as categoryName')
                ->orderBy('t.tracking_time')->orderByDesc('a.order_id')->get();
            $seen = [];
            foreach ($events as $e) {
                $key = $e->worker_id.':'.$e->order_id;
                if (! isset($summaries[$e->worker_id]) || isset($seen[$key])) continue;
                $seen[$key] = true; $s = &$summaries[$e->worker_id]; $s['completedCount']++;
                $s['orders'][] = ['id' => $e->order_id, 'orderId' => $e->orderId, 'description' => $e->description,
                    'clientId' => $e->client_id, 'clientName' => $e->clientName, 'categoryId' => $e->category_id,
                    'categoryName' => $e->categoryName, 'assignedAt' => $e->assigned_at, 'completedAt' => $e->tracking_time,
                    'currentStage' => $e->currentStage, 'quantity' => $e->quantity, 'totalSquareFeet' => $e->totalSquareFeet,
                    'amount' => $e->amount, 'workerRate' => $e->applied_worker_rate,
                    'workerAmount' => $e->earned_amount ?? $e->expected_amount, 'rateSource' => $e->rate_source];
                unset($s);
            }
            return response()->json(['startDate' => $start, 'endDate' => $end, 'totalCompleted' => count($seen), 'workers' => array_values($summaries)]);
        });
    }
    private function range(Request $r): array
    {
        $date = trim((string) $r->query('date', '')); $start = trim((string) $r->query('startDate', '')); $end = trim((string) $r->query('endDate', ''));
        if ($date !== '' && ($start !== '' || $end !== '')) throw new InvalidArgumentException('Use either date or startDate and endDate, not both');
        if ($date !== '') return [$this->validDate($date, 'date'), $this->validDate($date, 'date')];
        if (($start === '') !== ($end === '')) throw new InvalidArgumentException('Both startDate and endDate are required for a date range');
        if ($start === '') throw new InvalidArgumentException('date or startDate and endDate is required');
        $start = $this->validDate($start, 'startDate'); $end = $this->validDate($end, 'endDate');
        if ($end < $start) throw new InvalidArgumentException('endDate cannot be before startDate');
        return [$start, $end];
    }
    private function validDate(string $value, string $name): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (! $d || $d->format('Y-m-d') !== $value) throw new InvalidArgumentException($name.' must be in YYYY-MM-DD format');
        return $value;
    }
}
