<?php

use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\CameraFormController;
use App\Http\Controllers\Api\CameraReportController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CustomReportController;
use App\Http\Controllers\Api\EntityController;
use App\Http\Middleware\AuthTokenStoreScopeMiddleware;
use Illuminate\Support\Facades\Route;

Route::middleware([
    AuthTokenStoreScopeMiddleware::class,
])->group(function () {

    Route::get('audits/summary/{store_code}/{date}', [AuditController::class, 'summary']);

    Route::apiResource('camera-forms', CameraFormController::class);

    Route::get('camera-reports', [CameraReportController::class, 'index']);
    Route::get('camera-reports/export', [CameraReportController::class, 'export']);
    // ================================= export functions ==============================
    Route::get('camera-reports/exportExcel', [CameraReportController::class, 'exportExcel'])->name('camera-reports.exportExcel');
    Route::get('camera-reports/exportImages', [CameraReportController::class, 'exportImages'])->name('camera-reports.exportImages');

    // ================================= Custom Reports ==============================
    Route::apiResource('custom-reports', CustomReportController::class);
    // ==================================

    Route::apiResource('audits', AuditController::class)
        ->only(['index', 'show']);
    Route::get('audits/summary/{store_code}/{date}', [AuditController::class, 'summary']);

    Route::apiResource('entities', EntityController::class)
        ->except(['show', 'create', 'edit']);

    Route::apiResource('categories', CategoryController::class)
        ->only(['store', 'update', 'destroy']);
});
