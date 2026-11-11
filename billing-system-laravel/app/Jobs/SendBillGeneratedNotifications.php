<?php

namespace App\Jobs;

use App\Services\FirebaseNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/** Bill-generated side effects run after the response/commit and fail independently. */
class SendBillGeneratedNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $userId, public readonly int $billId) {}

    public function handle(FirebaseNotificationService $notifications): void
    {
        $data = DB::table('bills as b')->join('clients as c', 'c.id', '=', 'b.client_id')
            ->where('b.id', $this->billId)->where('b.user_id', $this->userId)
            ->first(['b.id', 'b.total_amount', 'c.clientName', 'c.registered_client_id']);
        if (!$data) return;
        $name = $data->clientName ?: 'Client';
        $amount = number_format((float) $data->total_amount, 2, '.', ',');
        try { $notifications->sendToUser($this->userId, null, "Bill #{$data->id} generated for {$name}. Amount: {$amount}."); } catch (\Throwable) {}
        if ($data->registered_client_id !== null) {
            try { $notifications->sendToRegisteredClient((int) $data->registered_client_id, null, "Hi {$name}, your bill #{$data->id} for {$amount} has been generated."); } catch (\Throwable) {}
        }
    }
}
