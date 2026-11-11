<?php

use App\Http\Controllers\WorkerCompensationController;
use Illuminate\Support\Facades\Route;

Route::middleware('user.jwt')->prefix('worker-compensation')->group(function (): void {
    Route::get('settings', [WorkerCompensationController::class, 'settings']);
    Route::put('settings', [WorkerCompensationController::class, 'updateSettings']);
    Route::get('workers/{workerId}', [WorkerCompensationController::class, 'account']);
    Route::get('payout-requests', [WorkerCompensationController::class, 'payoutRequests']);
    Route::post('payout-requests/{requestId}/settle', [WorkerCompensationController::class, 'settle']);
    Route::post('payout-requests/{requestId}/reject', [WorkerCompensationController::class, 'reject']);
    Route::post('workers/{workerId}/adjustments', [WorkerCompensationController::class, 'adjustment']);
    Route::post('assignments/{assignmentId}/recalculate', [WorkerCompensationController::class, 'recalculate']);
    Route::post('assignments/{assignmentId}/reverse', [WorkerCompensationController::class, 'reverse']);
});
