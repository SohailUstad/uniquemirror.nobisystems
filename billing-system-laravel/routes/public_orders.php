<?php

use App\Http\Controllers\PublicOrderController;
use Illuminate\Support\Facades\Route;

// Included by routes/api.php, which supplies the /api prefix. Spring permits all eight routes.
Route::prefix('public')->group(function (): void {
    Route::get('/', [PublicOrderController::class, 'index']);
    Route::get('/share/orders/{id}', [PublicOrderController::class, 'share']);
    Route::post('/tracker', [PublicOrderController::class, 'saveTracking']);
    Route::get('/tracker/{orderId}', [PublicOrderController::class, 'tracking'])->whereNumber('orderId');
    Route::get('/bill/{billId}', [PublicOrderController::class, 'billOrders'])->whereNumber('billId');
    Route::get('/orders/{orderId}/voice-notes/{noteId}/stream', [PublicOrderController::class, 'streamVoiceNote'])->whereNumber('orderId')->whereNumber('noteId');
    Route::get('/{orderId}/voice-notes', [PublicOrderController::class, 'voiceNotes'])->whereNumber('orderId');
    Route::get('/{orderId}', [PublicOrderController::class, 'show'])->whereNumber('orderId');
});
