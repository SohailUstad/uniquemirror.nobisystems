<?php

use App\Http\Controllers\RegisteredClientAuthController;
use App\Http\Controllers\RegisteredClientPortalController;
use App\Http\Middleware\ClientJwtAuthentication;
use Illuminate\Support\Facades\Route;

Route::prefix('client-auth')->group(function (): void {
    Route::post('register/request-otp', [RegisteredClientAuthController::class, 'requestRegistrationOtp']);
    Route::post('register/verify', [RegisteredClientAuthController::class, 'verifyRegistration']);
    Route::post('login', [RegisteredClientAuthController::class, 'login']);
    Route::post('forgot-password/request-otp', [RegisteredClientAuthController::class, 'requestPasswordResetOtp']);
    Route::post('forgot-password/reset', [RegisteredClientAuthController::class, 'resetPassword']);
});
Route::prefix('client')->middleware(ClientJwtAuthentication::class)->group(function (): void {
    Route::get('orders', [RegisteredClientPortalController::class, 'orders']);
    Route::get('orders/{orderId}', [RegisteredClientPortalController::class, 'order']);
    Route::get('bills', [RegisteredClientPortalController::class, 'bills']);
    Route::get('bills/{billId}', [RegisteredClientPortalController::class, 'bill']);
    Route::get('payments', [RegisteredClientPortalController::class, 'payments']);
    Route::get('receivables', [RegisteredClientPortalController::class, 'receivables']);
    Route::get('ledger', [RegisteredClientPortalController::class, 'ledger']);
    Route::post('fcm-tokens', [RegisteredClientPortalController::class, 'registerFcmToken']);
    Route::delete('fcm-tokens', [RegisteredClientPortalController::class, 'deactivateFcmToken']);
});
