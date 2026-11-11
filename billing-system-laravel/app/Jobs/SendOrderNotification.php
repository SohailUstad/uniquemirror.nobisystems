<?php

namespace App\Jobs;

use App\Services\FirebaseNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Delivers each order-notification audience independently after the response. */
class SendOrderNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly ?string $userTitle,
        public readonly string $userBody,
        public readonly ?int $registeredClientId,
        public readonly ?string $clientBody,
    ) {}

    public function handle(FirebaseNotificationService $notifications): void
    {
        try { $notifications->sendToUser($this->userId, $this->userTitle, $this->userBody); } catch (\Throwable) {}
        if ($this->registeredClientId !== null && $this->clientBody !== null) {
            try { $notifications->sendToRegisteredClient($this->registeredClientId, $this->userTitle, $this->clientBody); } catch (\Throwable) {}
        }
    }
}
