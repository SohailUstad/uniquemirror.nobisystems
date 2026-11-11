<?php

// Include inside routes/api.php. The controller methods use the existing user.jwt subject.
use App\Http\Controllers\ProductionStageController;
use Illuminate\Support\Facades\Route;

Route::middleware('user.jwt')->prefix('production')->group(function (): void {
    Route::get('stages', [ProductionStageController::class, 'stages']);
    Route::post('stages', [ProductionStageController::class, 'createStage']);
    Route::put('stages/{id}', [ProductionStageController::class, 'updateStage']);
    Route::delete('stages/{id}', [ProductionStageController::class, 'deleteStage']);
    Route::get('stage-flows', [ProductionStageController::class, 'flows']);
    Route::get('stage-flows/{id}', [ProductionStageController::class, 'flow']);
    Route::post('stage-flows', [ProductionStageController::class, 'createFlow']);
    Route::put('stage-flows/{id}', [ProductionStageController::class, 'updateFlow']);
    Route::delete('stage-flows/{id}', [ProductionStageController::class, 'deleteFlow']);
    Route::get('components', [ProductionStageController::class, 'components']);
    Route::post('components', [ProductionStageController::class, 'createComponent']);
    Route::put('components/{id}', [ProductionStageController::class, 'updateComponent']);
    Route::get('stage-flows/{id}/components', [ProductionStageController::class, 'flowComponents']);
    Route::put('stage-flows/{id}/components', [ProductionStageController::class, 'updateFlowComponents']);
    Route::get('settings', [ProductionStageController::class, 'settings']);
    Route::put('settings', [ProductionStageController::class, 'updateSettings']);
    Route::get('workers/{id}/stage-mappings', [ProductionStageController::class, 'workerStageMappings']);
    Route::put('workers/{id}/stage-mappings', [ProductionStageController::class, 'updateWorkerStageMappings']);
});
