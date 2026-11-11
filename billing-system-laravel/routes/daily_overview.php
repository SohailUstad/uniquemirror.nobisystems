<?php

use App\Http\Controllers\DailyOverviewController;
use Illuminate\Support\Facades\Route;

Route::middleware('user.jwt')->group(function (): void {
    Route::get('dashboard/daily', [DailyOverviewController::class, 'overview']);
    Route::get('dashboard/daily/workers', [DailyOverviewController::class, 'workers']);
});
