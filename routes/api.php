<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\{CameraFormController, CameraReportController, AuditController, EntityController, CategoryController};
use App\Http\Middleware\AuthTokenStoreScopeMiddleware;





Route::middleware([
    AuthTokenStoreScopeMiddleware::class,
])->group(function () {
    

    Route::apiResource('camera-forms', CameraFormController::class);

    Route::get('camera-reports', [CameraReportController::class, 'index']);
    Route::get('camera-reports/export', [CameraReportController::class, 'export']);


    Route::apiResource('audits', AuditController::class)
        ->only(['index', 'show']);
    Route::get('audits/summary/{store_code}/{date}', [AuditController::class, 'summary']);


    Route::apiResource('entities', EntityController::class)
        ->except(['show', 'create', 'edit']);

    Route::apiResource('categories', CategoryController::class)
        ->only(['store', 'update', 'destroy']);
});
