<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Sends FCM HTTP v1 notifications; token-level failures are counted and isolated. */
class FirebaseNotificationService
{
    public function sendToUser(int $userId, ?string $title, string $body, ?string $imageUrl = null): array
    {
        return $this->send('fcm_device_tokens', 'user_id', $userId, $title, $body, $imageUrl);
    }

    public function sendToRegisteredClient(int $registeredClientId, ?string $title, string $body, ?string $imageUrl = null): array
    {
        return $this->send('registered_client_fcm_device_tokens', 'registered_client_id', $registeredClientId, $title, $body, $imageUrl);
    }

    private function send(string $table, string $ownerColumn, int $ownerId, ?string $title, string $body, ?string $imageUrl): array
    {
        $body = trim($body);
        if ($body === '') throw new \InvalidArgumentException('Body is required');
        $title = $title !== null && trim($title) !== '' ? trim($title) : null;
        $imageUrl = $imageUrl !== null && trim($imageUrl) !== '' ? trim($imageUrl) : null;
        $tokens = DB::table($table)->where($ownerColumn, $ownerId)->where('active', true)->whereNull('logged_out_at')->pluck('token');
        $sent = 0; $failed = 0;
        foreach ($tokens as $token) {
            try {
                $message = ['message' => ['token' => $token, 'notification' => array_filter(['title' => $title, 'body' => $body, 'image' => $imageUrl], static fn($v) => $v !== null)]];
                Http::withToken($this->accessToken())->acceptJson()->post($this->endpoint(), $message)->throw();
                $sent++;
            } catch (\Throwable) {
                $failed++;
            }
        }
        return ['total' => $tokens->count(), 'sent' => $sent, 'failed' => $failed];
    }

    private function endpoint(): string
    {
        $project = (string) config('services.fcm.project_id');
        if ($project === '') throw new \RuntimeException('FCM project is not configured');
        return 'https://fcm.googleapis.com/v1/projects/'.rawurlencode($project).'/messages:send';
    }

    private function accessToken(): string
    {
        return Cache::remember('fcm.oauth.access_token', now()->addMinutes(50), function (): string {
            $email = (string) config('services.fcm.client_email');
            $privateKey = (string) config('services.fcm.private_key');
            if ($email === '' || $privateKey === '') throw new \RuntimeException('FCM credentials are not configured');
            $now = time();
            $assertion = JWT::encode([
                'iss' => $email, 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
            ], $privateKey, 'RS256');
            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion,
            ])->throw()->json();
            if (!is_string($response['access_token'] ?? null) || $response['access_token'] === '') throw new \RuntimeException('FCM OAuth response omitted access token');
            return $response['access_token'];
        });
    }
}
