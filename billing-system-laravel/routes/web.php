<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

use App\Http\Controllers\OrderMediaController;

// Merge into routes/web.php so this is the exact root URL /files/{filename}.
Route::get('/files/{filename}', [OrderMediaController::class, 'publicFile'])->where('filename', '[^/]+');

Route::middleware('user.jwt')->get('/test/api/hello', function (\Illuminate\Http\Request $request) {
    return response()->json([
        'message' => 'Hello, '.$request->attributes->get('jwt_subject'),
        'status' => 'Authenticated',
    ]);
});

require __DIR__.'/storefront.php';
