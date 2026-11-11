<?php

use App\Http\Controllers\WebsiteController;
use Illuminate\Support\Facades\Route;

// Merge into routes/web.php: the web middleware is required for the cart session, flash messages, and CSRF.
Route::get('/api', [WebsiteController::class, 'home']);
Route::get('/api/', [WebsiteController::class, 'home']);
Route::get('/api/products', [WebsiteController::class, 'products']);
Route::get('/api/shop/categories', [WebsiteController::class, 'categories']);
Route::get('/api/products/{slug}', [WebsiteController::class, 'productDetail']);
Route::post('/api/cart/add', [WebsiteController::class, 'addToCart']);
Route::post('/api/cart/remove', [WebsiteController::class, 'removeFromCart']);
Route::get('/api/cart', [WebsiteController::class, 'cart']);
Route::get('/api/login', [WebsiteController::class, 'login']);
Route::post('/api/login/send-otp', [WebsiteController::class, 'sendOtp']);
Route::post('/api/login/verify', [WebsiteController::class, 'verifyOtp']);
Route::post('/api/logout', [WebsiteController::class, 'logout']);
Route::get('/api/checkout', [WebsiteController::class, 'checkout']);
Route::post('/api/checkout/address', [WebsiteController::class, 'saveAddress']);
Route::post('/api/checkout/place-order', [WebsiteController::class, 'placeOrder']);
Route::get('/api/account', [WebsiteController::class, 'account']);
Route::post('/api/account/profile', [WebsiteController::class, 'updateProfile']);
Route::get('/api/account/orders', [WebsiteController::class, 'orderHistory']);
Route::get('/api/account/orders/{orderNumber}', [WebsiteController::class, 'orderDetail']);
