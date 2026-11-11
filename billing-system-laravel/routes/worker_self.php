<?php

use App\Http\Controllers\WorkerSelfController;
use Illuminate\Support\Facades\Route;

Route::middleware('worker.jwt')->prefix('worker')->group(function (): void {
    Route::get('me', [WorkerSelfController::class, 'me']);
    Route::get('my-orders', [WorkerSelfController::class, 'myOrders']);
    Route::get('orders/available', [WorkerSelfController::class, 'available']);
    Route::get('stage-assignments/my-work', [WorkerSelfController::class, 'myWork']);
    Route::get('orders/{orderId}', [WorkerSelfController::class, 'order']);
    Route::get('orders/{orderId}/preview', [WorkerSelfController::class, 'preview']);
    Route::post('orders/{orderId}/claim', [WorkerSelfController::class, 'claim']);
    Route::get('orders/{orderId}/stage-options', [WorkerSelfController::class, 'stageOptions']);
    Route::post('orders/{orderId}/pickup', [WorkerSelfController::class, 'pickup']);
    Route::post('stage-assignments/{assignmentId}/start', [WorkerSelfController::class, 'start']);
    Route::post('stage-assignments/{assignmentId}/complete', [WorkerSelfController::class, 'complete']);
    Route::put('orders/{orderId}/stage', [WorkerSelfController::class, 'updateStage']);
    Route::get('orders/{orderId}/notes', [WorkerSelfController::class, 'notes']);
    Route::get('my-stats', [WorkerSelfController::class, 'stats']);
    Route::get('earnings', [WorkerSelfController::class, 'earnings']);
    Route::get('productivity', [WorkerSelfController::class, 'productivity']);
    Route::post('payout-requests', [WorkerSelfController::class, 'requestPayout']);
    Route::post('payout-requests/{requestId}/cancel', [WorkerSelfController::class, 'cancelPayout']);
});
