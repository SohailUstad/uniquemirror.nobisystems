<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Captures the media references before order rows are removed, then deletes only
 * files owned by this application's private local disk after the caller's
 * database transaction has committed successfully.
 */
class OrderMediaCleanupService
{
    /**
     * Capture references before deleting order_images/order_voice_notes/orders.
     * The returned payload can safely be held across the deleting transaction.
     */
    public function captureOrderFiles(int $orderId): array
    {
        $imageUrls = [];
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'imagePath')) {
            $legacyImage = DB::table('orders')->where('id', $orderId)->value('imagePath');
            if (is_string($legacyImage) && trim($legacyImage) !== '') $imageUrls[] = trim($legacyImage);
        }
        if (Schema::hasTable('order_images')) {
            foreach (DB::table('order_images')->where('order_id', $orderId)->pluck('image_url') as $url) {
                if (is_string($url) && trim($url) !== '') $imageUrls[] = trim($url);
            }
        }

        $storageKeys = [];
        if (Schema::hasTable('order_voice_notes')) {
            foreach (DB::table('order_voice_notes')->where('order_id', $orderId)->pluck('storage_key') as $key) {
                if (is_string($key) && trim($key) !== '') $storageKeys[] = trim($key);
            }
        }

        return ['imageUrls' => array_values(array_unique($imageUrls)), 'storageKeys' => array_values(array_unique($storageKeys))];
    }

    /** Call only after the DB::transaction that deletes the order has returned. */
    public function deleteCapturedFiles(array $captured): void
    {
        $disk = Storage::disk('local');
        foreach (($captured['imageUrls'] ?? []) as $url) {
            $key = $this->localUploadKey((string) $url);
            if ($key !== null) {
                try { $disk->delete($key); } catch (\Throwable $ignored) { /* DB deletion has already committed. */ }
            }
        }
        foreach (($captured['storageKeys'] ?? []) as $storageKey) {
            $key = $this->safeVoiceStorageKey((string) $storageKey);
            if ($key !== null) {
                try { $disk->delete($key); } catch (\Throwable $ignored) { /* DB deletion has already committed. */ }
            }
        }
    }

    private function localUploadKey(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['path'])) return null;
        if (isset($parts['host']) && !$this->sameApplicationHost($parts)) return null;
        $path = $parts['path'];
        if (!preg_match('~^/files/([^/]+)$~', $path, $matches)) return null;
        $filename = rawurldecode($matches[1]);
        if ($filename === '' || $filename === '.' || $filename === '..' || basename($filename) !== $filename || str_contains($filename, '/') || str_contains($filename, '\\')) return null;
        return 'uploads/'.$filename;
    }

    private function sameApplicationHost(array $urlParts): bool
    {
        $base = parse_url((string) config('app.url'));
        if (!is_array($base) || empty($base['host']) || strcasecmp((string) $urlParts['host'], (string) $base['host']) !== 0) return false;
        return ($urlParts['port'] ?? null) === ($base['port'] ?? null);
    }

    /** Accept the current voice-notes/{uuid.ext} keys and legacy flat UUID keys only. */
    private function safeVoiceStorageKey(string $storageKey): ?string
    {
        $storageKey = str_replace('\\', '/', trim($storageKey));
        if (str_contains($storageKey, '..') || str_starts_with($storageKey, '/') || str_contains($storageKey, ':')) return null;
        if (preg_match('~^voice-notes/([A-Za-z0-9_.-]+)$~', $storageKey, $matches)) {
            $filename = $matches[1];
            return $filename !== '.' && $filename !== '..' ? 'voice-notes/'.$filename : null;
        }
        if (preg_match('~^([A-Za-z0-9_.-]+)$~', $storageKey) && $storageKey !== '.' && $storageKey !== '..') return $storageKey;
        return null;
    }
}
