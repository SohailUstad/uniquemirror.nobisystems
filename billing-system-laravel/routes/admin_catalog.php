<?php

use App\Http\Controllers\AdminCatalogController;
use Illuminate\Support\Facades\Route;

// Include from routes/api.php. These are authenticated with the existing USER JWT.
Route::middleware('user.jwt')->prefix('admin')->group(function (): void {
    Route::get('/public-orders', [AdminCatalogController::class, 'orders']);
    Route::put('/public-orders/{id}/status', [AdminCatalogController::class, 'updateOrderStatus'])->whereNumber('id');
    Route::get('/public-customers', [AdminCatalogController::class, 'customers']);

    Route::get('/product-categories', [AdminCatalogController::class, 'categories']);
    Route::post('/product-categories', [AdminCatalogController::class, 'createCategory']);
    Route::put('/product-categories/{id}', [AdminCatalogController::class, 'updateCategory'])->whereNumber('id');
    Route::get('/products', [AdminCatalogController::class, 'products']);
    Route::post('/products', [AdminCatalogController::class, 'createProduct']);
    Route::put('/products/{id}', [AdminCatalogController::class, 'updateProduct'])->whereNumber('id');
    Route::delete('/products/{id}', [AdminCatalogController::class, 'deleteProduct'])->whereNumber('id');
    Route::post('/products/{productId}/images', [AdminCatalogController::class, 'addImage'])->whereNumber('productId');
    Route::post('/products/{productId}/sizes', [AdminCatalogController::class, 'addSize'])->whereNumber('productId');
});
