<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Billing-specific equivalent of OrderService's admin-driven Billed transition. */
class BillingProductionStageService
{
    public function __construct(private readonly ProductionFlowProgressService $progress) {}

    public function transitionToBilled(int $userId, object $order, $at): void
    {
        // Spring calls recordCompletion(user, order, "Billed") before changing status.
        $this->progress->recordCompletion($userId, (int) $order->id, 'Billed');

        // Spring cancels PICKED/IN_PROGRESS assignments and releases each worker.
        if (\Illuminate\Support\Facades\Schema::hasTable('order_stage_assignments')) {
            $open = DB::table('order_stage_assignments')->where('user_id', $userId)->where('order_id', $order->id)
                ->whereIn('status', ['PICKED', 'IN_PROGRESS'])->get();
            foreach ($open as $assignment) {
                DB::table('order_stage_assignments')->where('id', $assignment->id)->update(['status' => 'CANCELLED', 'updated_at' => $at]);
                DB::table('worker_order_assignments')->where('user_id', $userId)->where('worker_id', $assignment->worker_id)
                    ->where('order_id', $order->id)->where('current_assignment', true)
                    ->update(['current_assignment' => false, 'unassigned_at' => $at]);
                DB::table('orders')->where('id', $order->id)->where('worker_id', $assignment->worker_id)
                    ->update(['worker_id' => null, 'assigned_at' => null, 'updated_at' => $at]);
            }
        }
    }
}
