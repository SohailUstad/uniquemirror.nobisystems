<?php

use App\Http\Controllers\WorkerTargetController;
use Illuminate\Support\Facades\Route;

Route::middleware('user.jwt')->prefix('worker-targets')->group(function (): void {
    Route::get('progress', [WorkerTargetController::class, 'progress']);
    Route::get('working-days', [WorkerTargetController::class, 'workingDays']);
    Route::put('working-days', [WorkerTargetController::class, 'setGlobalWorkingDays']);
    Route::put('working-days/workers/{workerId}', [WorkerTargetController::class, 'setWorkerWorkingDays']);
    Route::delete('working-days/workers/{workerId}', [WorkerTargetController::class, 'clearWorkerWorkingDays']);
    Route::post('reset-carry-forward', [WorkerTargetController::class, 'resetCarryForward']);
    Route::get('workers/{workerId}', [WorkerTargetController::class, 'history']);
    Route::put('workers/{workerId}', [WorkerTargetController::class, 'setTarget']);
    Route::put('workers/{workerId}/overrides/{date}', [WorkerTargetController::class, 'setOverride']);
    Route::delete('workers/{workerId}/overrides/{date}', [WorkerTargetController::class, 'deleteOverride']);
});
