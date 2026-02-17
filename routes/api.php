<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\{CameraFormController, CameraReportController, AuditController, EntityController, CategoryController};
use App\Http\Middleware\AuthTokenStoreScopeMiddleware;





Route::middleware([
    AuthTokenStoreScopeMiddleware::class,
])->group(function () {

    Route::get('/audits/ratings-summary/{store_id}/{date_start}/{date_end}', [AuditController::class, 'ratingsSummary']);

    Route::apiResource('camera-forms', CameraFormController::class)
        ->except(['update']);  // Exclude the default `update` route

    // Define custom `update` route with POST method
    Route::post('camera-forms/{camera_form}', [CameraFormController::class, 'update']);

    Route::get('camera-reports', [CameraReportController::class, 'index']);
    Route::get('camera-reports/export', [CameraReportController::class, 'export']);


    Route::apiResource('audits', AuditController::class)
        ->only(['index', 'show']);
    // Route::get('audits/summary/{store_code}/{date}', [AuditController::class, 'summary']);


    Route::apiResource('entities', EntityController::class)
        ->except(['show', 'create', 'edit']);

    Route::apiResource('categories', CategoryController::class)
        ->only(['store', 'update', 'destroy']);
});
