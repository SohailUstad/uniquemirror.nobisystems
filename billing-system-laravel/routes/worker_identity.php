<?php

use App\Http\Controllers\WorkerAuthController;
use App\Http\Controllers\WorkerController;
use App\Http\Controllers\OrderMediaController;
use Illuminate\Support\Facades\Route;

Route::post('worker-auth/login', [WorkerAuthController::class, 'login']);
Route::middleware('worker.jwt')->post('worker-auth/change-password', [WorkerAuthController::class, 'changePassword']);
Route::middleware('user.jwt')->prefix('workers')->group(function (): void {
    Route::get('stats', [WorkerController::class, 'stats']);
    Route::get('completed-summary', [WorkerController::class, 'completedSummary']);
    Route::get('', [WorkerController::class, 'index']);
    Route::post('', [WorkerController::class, 'store']);
    Route::get('{workerId}', [WorkerController::class, 'show']);
    Route::put('{workerId}', [WorkerController::class, 'update']);
    Route::delete('{workerId}', [WorkerController::class, 'destroy']);
    Route::post('{workerId}/password', [WorkerController::class, 'password']);
    Route::post('{workerId}/assignments', [WorkerController::class, 'assignments']);
    Route::get('{workerId}/orders', [WorkerController::class, 'orders']);
});

// These two existing GET routes must be removed from the user.jwt route group.
Route::middleware('voice.jwt')->get('orders/{orderId}/voice-notes', [OrderMediaController::class, 'voiceNotes']);
Route::middleware('voice.jwt')->get('orders/{orderId}/voice-notes/{noteId}/stream', [OrderMediaController::class, 'streamVoiceNote']);
