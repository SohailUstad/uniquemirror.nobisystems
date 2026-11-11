<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Resolves an order's fixed production flow and stable completed position. */
class ProductionFlowProgressService
{
    public function resolveFlowId(int $userId, ?int $orderFlowId = null): ?int
    {
        if ($orderFlowId !== null) return $orderFlowId;
        $default = DB::table('users')->where('id', $userId)->value('default_production_stage_flow_id');
        if ($default !== null) return (int) $default;
        $id = DB::table('production_stage_flows')->where('user_id', $userId)->where('name', 'Default')->value('id');
        return $id === null ? null : (int) $id;
    }

    public function nextStageId(object $order): ?int
    {
        if (!$order->last_completed_production_stage_id) {
            return DB::table('production_stage_flow_steps')->where('flow_id', $order->production_stage_flow_id)->orderBy('sequence')->orderBy('id')->value('stage_id');
        }
        if (!$order->flow_progress_flow_id || (int)$order->flow_progress_flow_id !== (int)$order->production_stage_flow_id) return null;
        $completed = DB::table('production_stage_flow_steps')->where('flow_id', $order->production_stage_flow_id)
            ->where('stage_id', $order->last_completed_production_stage_id)->first();
        if (!$completed) return null;
        return DB::table('production_stage_flow_steps')->where('flow_id', $order->production_stage_flow_id)
            ->where('sequence', '>', $completed->sequence)->orderBy('sequence')->orderBy('id')->value('stage_id');
    }

    public function recordCompletion(int $userId, int $orderId, string|int|null $stage): void
    {
        if ($stage === null) return;
        $order = DB::table('orders')->where('id', $orderId)->where('user_id', $userId)->first();
        if (!$order) return;
        $flowId = $this->resolveFlowId($userId, $order->production_stage_flow_id ? (int)$order->production_stage_flow_id : null);
        if (!$flowId) return;
        $step = DB::table('production_stage_flow_steps as fs')->join('production_stages as s', 's.id', '=', 'fs.stage_id')
            ->where('fs.flow_id', $flowId)->where('s.user_id', $userId)
            ->when(is_int($stage) || ctype_digit((string)$stage), fn($q) => $q->where('s.id', (int)$stage), fn($q) => $q->whereRaw('LOWER(TRIM(s.name)) = LOWER(TRIM(?))', [(string)$stage]))
            ->orderBy('fs.sequence')->orderBy('fs.id')->select('fs.stage_id')->first();
        if ($step) DB::table('orders')->where('id', $orderId)->update(['flow_progress_flow_id'=>$flowId,'last_completed_production_stage_id'=>$step->stage_id]);
    }
}
