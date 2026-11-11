<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DashboardController extends Controller
{
    private function uid(Request $request): int
    {
        $id = DB::table('users')->where('email', $request->attributes->get('jwt_subject'))->value('id');
        if (!$id) throw new \InvalidArgumentException('User not found');
        return (int) $id;
    }
    private function date(?string $date): string { return $date ?: now()->toDateString(); }

    private function flows(Request $request): array
    {
        return array_values(array_unique(array_map('intval', array_filter(array_merge(
            (array) $request->query('productionStageFlowId', []),
            (array) $request->query('productionStageFlowIds', [])
        ), fn ($v) => $v !== null && $v !== ''))));
    }

    private function orderRow(object $o): array
    {
        return [
            'id' => $o->id, 'description' => $o->description, 'height' => $o->height,
            'width' => $o->width, 'quantity' => $o->quantity, 'rate' => (float) $o->rate,
            'totalSquareFeet' => $o->totalSquareFeet === null ? null : (float) $o->totalSquareFeet,
            'amount' => $o->amount === null ? null : (float) $o->amount,
            'billed' => (bool) $o->billed, 'clientName' => $o->clientName, 'clientId' => $o->client_id,
            'orderId' => $o->orderId, 'sequence' => $o->sequence, 'imagePath' => $o->imagePath,
            'images' => DB::table('order_images')->where('order_id', $o->id)->orderBy('display_order')->orderBy('id')->get()->map(fn ($image) => ['id' => $image->id, 'imageUrl' => $image->image_url, 'label' => $image->label, 'displayOrder' => $image->display_order])->values(), 'currentStage' => $o->currentStage,
            'productionStageFlowId' => $o->production_stage_flow_id,
            'productionStageFlowName' => null, 'qrCodePath' => $o->qrCodePath,
            'mode' => $o->deleiverdThorugh,
            'date' => $o->date ? date('d M Y h:i A', strtotime($o->date)) : null,
            'note' => $o->note, 'deadline' => $o->deadline === null ? null : str_replace(' ', 'T', $o->deadline),
        ];
    }

    private function refRow(object $ref): array
    {
        return ['dashboardRefId' => $ref->dashboard_ref_id, 'dashboardDate' => $ref->ref_dashboard_date,
            'mustComplete' => (bool) $ref->ref_must_complete, 'priority' => $ref->ref_priority,
            'addedAt' => str_replace(' ', 'T', $ref->added_at), 'order' => $this->orderRow($ref)];
    }

    public function live(Request $request)
    {
        $uid = $this->uid($request); $date = $this->date($request->query('date')); $flows = $this->flows($request);
        $refs = DB::table('dashboard_order_refs as d')->join('orders as o', function ($j) use ($uid) {
            $j->on('o.id', '=', 'd.order_id')->where('o.user_id', '=', $uid);
        })->join('clients as c', 'c.id', '=', 'o.client_id')
            ->where('d.user_id', $uid)->whereDate('d.dashboard_date', $date)
            ->when($flows, fn ($q) => $q->whereIn('o.production_stage_flow_id', $flows))
            ->select('d.id as dashboard_ref_id', 'd.dashboard_date as ref_dashboard_date', 'd.must_complete as ref_must_complete', 'd.priority as ref_priority', 'd.created_at as added_at', 'o.*', 'c.clientName as clientName')->orderBy('d.created_at')->get();
        $must = $refs->where('ref_must_complete', true)->where('currentStage', '!=', 'Completed')->sortBy([['ref_priority', 'asc'], ['added_at', 'asc']])->take(5)->map(fn ($r) => $this->refRow($r))->values();
        $target = DB::table('dashboard_daily_targets')->where('user_id', $uid)->whereDate('target_date', $date)->value('target_count') ?? 0;
        $completed = DB::table('order_tracking')->where('user_id', $uid)->where('status', 'Completed')
            ->where('tracking_time', '>=', $date.' 00:00:00')->where('tracking_time', '<', date('Y-m-d 00:00:00', strtotime($date.' +1 day')))
            ->distinct('order_id')->count('order_id');
        return response()->json(['currentTime' => now()->format('Y-m-d\TH:i:s'), 'date' => $date,
            'completedToday' => $completed, 'dailyTarget' => (int) $target,
            'statsLabel' => $completed.'/'.$target.' orders completed today', 'mustCompleteOrders' => $must,
            'orders' => $refs->map(fn ($r) => $this->refRow($r))->values(), 'productionStageFlowIds' => $flows]);
    }

    public function add(Request $request)
    {
        $uid = $this->uid($request); $data = $request->validate(['orderId' => 'required|integer', 'dashboardDate' => 'nullable|date', 'mustComplete' => 'nullable|boolean', 'priority' => 'nullable|integer']);
        $date = $this->date($data['dashboardDate'] ?? null);
        if (!DB::table('orders')->where('id', $data['orderId'])->where('user_id', $uid)->exists()) return response()->json(['error' => 'Order not found'], 400);
        if (DB::table('dashboard_order_refs')->where(['user_id' => $uid, 'order_id' => $data['orderId'], 'dashboard_date' => $date])->exists()) return response()->json(['error' => 'Order is already added to this dashboard date'], 400);
        $id = DB::table('dashboard_order_refs')->insertGetId(['user_id' => $uid, 'order_id' => $data['orderId'], 'dashboard_date' => $date, 'must_complete' => $data['mustComplete'] ?? false, 'priority' => $data['priority'] ?? 0, 'created_at' => now()]);
        $ref = DB::table('dashboard_order_refs as d')->join('orders as o', 'o.id', '=', 'd.order_id')->join('clients as c', 'c.id', '=', 'o.client_id')->where('d.id', $id)->select('d.id as dashboard_ref_id','d.dashboard_date as ref_dashboard_date','d.must_complete as ref_must_complete','d.priority as ref_priority','d.created_at as added_at','o.*','c.clientName as clientName')->first();
        return response()->json(['message' => 'Order added to dashboard successfully', 'dashboardOrder' => $this->refRow($ref)], 201);
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate(['mustComplete' => 'nullable|boolean', 'priority' => 'nullable|integer']);
        $query = DB::table('dashboard_order_refs')->where('id', $id)->where('user_id', $this->uid($request));
        if (!$query->exists()) return response()->json(['error' => 'Dashboard order not found'], 400);
        $query->update(array_filter(['must_complete' => $data['mustComplete'] ?? null, 'priority' => $data['priority'] ?? null], fn ($v) => $v !== null));
        $ref = DB::table('dashboard_order_refs as d')->join('orders as o', 'o.id', '=', 'd.order_id')->join('clients as c', 'c.id', '=', 'o.client_id')->where('d.id', $id)->select('d.id as dashboard_ref_id','d.dashboard_date as ref_dashboard_date','d.must_complete as ref_must_complete','d.priority as ref_priority','d.created_at as added_at','o.*','c.clientName as clientName')->first();
        return response()->json(['message' => 'Dashboard order updated successfully', 'dashboardOrder' => $this->refRow($ref)]);
    }

    public function remove(Request $request, int $id)
    {
        $deleted = DB::table('dashboard_order_refs')->where('id', $id)->where('user_id', $this->uid($request))->delete();
        return $deleted ? response()->json(['message' => 'Order removed from dashboard successfully']) : response()->json(['error' => 'Dashboard order not found'], 400);
    }

    public function target(Request $request, string $date)
    {
        $data = $request->validate(['targetCount' => 'nullable|integer|min:0', 'count' => 'nullable|integer|min:0']);
        $count = $data['targetCount'] ?? $data['count'] ?? null;
        if ($count === null) return response()->json(['error' => 'targetCount must be zero or greater'], 400);
        $uid = $this->uid($request); $row = DB::table('dashboard_daily_targets')->where('user_id', $uid)->whereDate('target_date', $date)->first();
        if ($row) DB::table('dashboard_daily_targets')->where('id', $row->id)->update(['target_count' => $count, 'updated_at' => now()]);
        else $id = DB::table('dashboard_daily_targets')->insertGetId(['user_id' => $uid, 'target_date' => $date, 'target_count' => $count, 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('dashboard_daily_targets')->where('user_id', $uid)->whereDate('target_date', $date)->first();
        return response()->json(['message' => 'Dashboard target saved successfully', 'target' => ['id' => $row->id, 'userId' => $row->user_id, 'targetDate' => $row->target_date, 'targetCount' => $row->target_count, 'createdAt' => str_replace(' ', 'T', $row->created_at), 'updatedAt' => str_replace(' ', 'T', $row->updated_at)]]);
    }
}
