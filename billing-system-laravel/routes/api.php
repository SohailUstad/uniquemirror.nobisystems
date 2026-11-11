<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\FcmDeviceTokenController;
use App\Http\Controllers\ExpenseController;
use Illuminate\Support\Facades\Route;

Route::post('auth/register', [AuthController::class, 'register']);
Route::post('auth/login', [AuthController::class, 'login']);

Route::middleware('user.jwt')->group(function (): void {
    Route::get('categories', [CategoryController::class, 'index']);
    Route::post('categories', [CategoryController::class, 'store']);
    Route::get('categories/{categoryId}', [CategoryController::class, 'show']);
    Route::put('categories/{categoryId}', [CategoryController::class, 'update']);
    Route::delete('categories/{categoryId}', [CategoryController::class, 'destroy']);

    Route::get('clients', [ClientController::class, 'index']);
    Route::post('clients', [ClientController::class, 'store']);
    Route::get('clients/{clientId}', [ClientController::class, 'show']);
    Route::put('clients/{clientId}', [ClientController::class, 'update']);
    Route::delete('clients/{clientId}', [ClientController::class, 'destroy']);

    Route::post('fcm-tokens', [FcmDeviceTokenController::class, 'register']);
    Route::delete('fcm-tokens', [FcmDeviceTokenController::class, 'deactivate']);
    Route::post('auth/logout', [FcmDeviceTokenController::class, 'logout']);

    foreach ([
        'expense-categories' => 'categories',
        'expense-vendors' => 'vendors',
        'expense-payment-modes' => 'paymentModes',
        'expense-payment-statuses' => 'paymentStatuses',
    ] as $path => $resource) {
        Route::get($path, [ExpenseController::class, 'masterIndex'])->defaults('resource', $resource);
        Route::post($path, [ExpenseController::class, 'masterCreate'])->defaults('resource', $resource);
        Route::put($path.'/{id}', [ExpenseController::class, 'masterUpdate'])->defaults('resource', $resource);
        Route::delete($path.'/{id}', [ExpenseController::class, 'masterArchive'])->defaults('resource', $resource);
    }

    Route::get('expenses/summary', [ExpenseController::class, 'summary']);
    Route::get('expenses', [ExpenseController::class, 'index']);
    Route::post('expenses', [ExpenseController::class, 'store']);
    Route::get('expenses/{id}', [ExpenseController::class, 'show']);
    Route::put('expenses/{id}', [ExpenseController::class, 'update']);
    Route::delete('expenses/{id}', [ExpenseController::class, 'destroy']);
});

use App\Http\Controllers\OrderConfigurationController;

Route::middleware('user.jwt')->group(function (): void {
    Route::post('/order-config/groups',[OrderConfigurationController::class,'createGroup']);
    Route::get('/order-config/groups',[OrderConfigurationController::class,'groups']);
    Route::put('/order-config/groups/{id}',[OrderConfigurationController::class,'updateGroup']);
    Route::delete('/order-config/groups/{id}',[OrderConfigurationController::class,'deleteGroup']);
    Route::post('/order-config/groups/{id}/values',[OrderConfigurationController::class,'createValue']);
    Route::get('/order-config/groups/{id}/values',[OrderConfigurationController::class,'values']);
    Route::put('/order-config/values/{id}',[OrderConfigurationController::class,'updateValue']);
    Route::delete('/order-config/values/{id}',[OrderConfigurationController::class,'deleteValue']);
    Route::post('/order-flows',[OrderConfigurationController::class,'createFlow']);
    Route::get('/order-flows',[OrderConfigurationController::class,'flows']);
    Route::get('/order-flows/{id}',[OrderConfigurationController::class,'showFlow']);
    Route::put('/order-flows/{id}',[OrderConfigurationController::class,'updateFlow']);
    Route::delete('/order-flows/{id}',[OrderConfigurationController::class,'deleteFlow']);
    Route::post('/order-flows/{id}/edges',[OrderConfigurationController::class,'createEdge']);
    Route::get('/order-flows/{id}/edges',[OrderConfigurationController::class,'edges']);
    Route::delete('/order-flows/{id}/edges/{eid}',[OrderConfigurationController::class,'deleteEdge']);
    Route::get('/order-flows/{id}/start',[OrderConfigurationController::class,'start']);
    Route::post('/order-flows/{id}/next',[OrderConfigurationController::class,'next']);
    Route::get('/order-counts',[OrderConfigurationController::class,'counts']);
});

use App\Http\Controllers\OrderController;

Route::middleware('user.jwt')->prefix('orders')->group(function () {
    Route::post('/', [OrderController::class, 'store']);
    Route::get('/', [OrderController::class, 'index']);
    Route::get('/stage', [OrderController::class, 'byStage']);
    Route::get('/client/{clientId}', [OrderController::class, 'byClient']);
    Route::get('/{orderId}', [OrderController::class, 'show']);
    Route::put('/{orderId}', [OrderController::class, 'update']);
    Route::delete('/{orderId}', [OrderController::class, 'destroy']);
    Route::post('/{orderId}/reset', [OrderController::class, 'reset']);
    Route::put('/{orderId}/stage', [OrderController::class, 'updateStage']);
});

use App\Http\Controllers\OrderMediaController;
use App\Http\Controllers\NotesController;
use App\Http\Controllers\PublicNotesController;

Route::middleware('user.jwt')->group(function (): void {
    Route::post('/orders/upload', [OrderMediaController::class, 'upload']);
    Route::post('/orders/{orderId}/images', [OrderMediaController::class, 'addImages']);
    Route::put('/orders/{orderId}/images/{imageId}', [OrderMediaController::class, 'updateImage']);
    Route::delete('/orders/{orderId}/images/{imageId}', [OrderMediaController::class, 'deleteImage']);
    Route::post('/orders/{orderId}/voice-notes', [OrderMediaController::class, 'addVoiceNote']);
    Route::delete('/orders/{orderId}/voice-notes/{noteId}', [OrderMediaController::class, 'deleteVoiceNote']);
    Route::get('/orders/{orderId}/tracking', [OrderMediaController::class, 'tracking']);

    Route::post('/notes/create', [NotesController::class, 'create']);
    Route::get('/notes/order/{orderId}', [NotesController::class, 'byOrder']);
});

Route::post('/public/notes/create', [PublicNotesController::class, 'create']);
Route::get('/public/notes/order/{orderId}', [PublicNotesController::class, 'byOrder']);

use App\Http\Controllers\AccountingController;
use App\Http\Controllers\PaymentController;

Route::middleware('user.jwt')->group(function () {
    Route::prefix('accounting')->group(function () {
        Route::get('/customers/{customerId}/summary', [AccountingController::class, 'summary']);
        Route::get('/customers/{customerId}/balance', [AccountingController::class, 'balance']);
        Route::get('/customers/balances', [AccountingController::class, 'balances']);
        Route::get('/ledger', [AccountingController::class, 'ledger']);
        Route::post('/customers/{customerId}/receivables', [AccountingController::class, 'addReceivable']);
        Route::delete('/customers/{customerId}/receivables/{receivableId}', [AccountingController::class, 'deleteReceivable']);
        Route::post('/customers/{customerId}/adjustments', [AccountingController::class, 'adjustment']);
        Route::get('/customers/{customerId}/ledger', [AccountingController::class, 'customerLedger']);
        Route::get('/dashboard/summary', [AccountingController::class, 'dashboard']);
    });
    Route::prefix('payments')->group(function () {
        Route::post('/', [PaymentController::class, 'create']);
        Route::get('/customer/{customerId}', [PaymentController::class, 'byCustomer']);
        Route::get('/', [PaymentController::class, 'index']);
        Route::put('/{paymentId}', [PaymentController::class, 'update']);
        Route::delete('/{paymentId}', [PaymentController::class, 'delete']);
    });
});

use App\Http\Controllers\BillingController;

Route::middleware('user.jwt')->prefix('bills')->group(function (): void {
    Route::post('/', [BillingController::class, 'store']);
    Route::get('/generate-bill', [BillingController::class, 'generated']);
    Route::get('/client/{clientId}', [BillingController::class, 'byClient']);
    Route::get('/', [BillingController::class, 'index']);
    Route::get('/{billId}', [BillingController::class, 'show']);
    Route::put('/{billId}', [BillingController::class, 'update']);
    Route::delete('/{billId}', [BillingController::class, 'destroy']);
    Route::post('/{billId}/confirm', [BillingController::class, 'confirm']);
});

use App\Http\Controllers\DashboardController;

Route::middleware('user.jwt')->group(function (): void {
    Route::get('dashboard/live', [DashboardController::class, 'live']);
    Route::post('dashboard/orders', [DashboardController::class, 'add']);
    Route::put('dashboard/orders/{dashboardRefId}/must-complete', [DashboardController::class, 'update']);
    Route::delete('dashboard/orders/{dashboardRefId}', [DashboardController::class, 'remove']);
    Route::put('dashboard/targets/{date}', [DashboardController::class, 'target']);
});

use App\Http\Controllers\AiCommandController;

Route::middleware('user.jwt')->group(function (): void {
    Route::post('ai/command', [AiCommandController::class, 'command']);
    Route::post('ai/confirmations/{confirmationId}', [AiCommandController::class, 'confirm']);
});
require __DIR__.'/worker_identity.php';
require __DIR__.'/worker_compensation.php';
require __DIR__.'/production_config.php';
require __DIR__.'/worker_targets.php';
require __DIR__.'/worker_self.php';
require __DIR__.'/daily_overview.php';
require __DIR__.'/public_orders.php';
require __DIR__.'/client_portal.php';
require __DIR__.'/admin_catalog.php';
