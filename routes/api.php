<?php

use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\CameraFormController;
use App\Http\Controllers\Api\CameraReportController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\Cleaning\CleaningDueController;
use App\Http\Controllers\Api\Cleaning\CleaningPeriodController;
use App\Http\Controllers\Api\Cleaning\CleaningReportController;
use App\Http\Controllers\Api\Cleaning\CleaningSettingController;
use App\Http\Controllers\Api\Cleaning\CleaningTaskController;
use App\Http\Controllers\Api\Cleaning\EvaluationAllocationController;
use App\Http\Controllers\Api\Cleaning\EvaluationController;
use App\Http\Controllers\Api\Cleaning\InspectionItemController;
use App\Http\Controllers\Api\CustomReportController;
use App\Http\Controllers\Api\EntityController;
use App\Http\Middleware\AuthTokenStoreScopeMiddleware;
use Illuminate\Support\Facades\Route;

Route::middleware([
    AuthTokenStoreScopeMiddleware::class,
])->group(function () {

    Route::get('/audits/ratings-summary/{store_id}/{date_start}/{date_end}', [AuditController::class, 'ratingsSummary']);

    Route::apiResource('camera-forms', CameraFormController::class)->except('update');
    Route::post('camera-forms/{camera_form}', [CameraFormController::class, 'update'])->name('camera-forms.update');
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
    // Route::get('audits/summary/{store_code}/{date}', [AuditController::class, 'summary']);

    Route::apiResource('entities', EntityController::class)
        ->except(['show', 'create', 'edit']);

    Route::apiResource('categories', CategoryController::class)
        ->only(['store', 'update', 'destroy']);

    // ================================= Cleaning Chart =============================
    Route::prefix('cleaning')->group(function () {
        // Track 1 — Tasks (auditor/super create; list/show)
        Route::get('tasks', [CleaningTaskController::class, 'index'])->name('cleaning.tasks.index');
        Route::post('tasks', [CleaningTaskController::class, 'store'])->name('cleaning.tasks.store');
        Route::get('tasks/{task}', [CleaningTaskController::class, 'show'])->name('cleaning.tasks.show');
        Route::put('tasks/{task}', [CleaningTaskController::class, 'update'])->name('cleaning.tasks.update');
        Route::delete('tasks/{task}', [CleaningTaskController::class, 'destroy'])->name('cleaning.tasks.destroy');

        // Store-scoped "what's due" — computed on read. Store id in the PATH (must be {store_id}).
        Route::get('stores/{store_id}/dates/{date}/due', [CleaningDueController::class, 'dueOnDate'])->name('cleaning.due.date');
        Route::get('stores/{store_id}/due-range', [CleaningDueController::class, 'dueRange'])->name('cleaning.due.range');

        // Complete / undo a task for a store on a date; per-task history.
        Route::post('stores/{store_id}/tasks/{task}/complete', [CleaningDueController::class, 'complete'])->name('cleaning.tasks.complete');
        Route::post('stores/{store_id}/tasks/{task}/uncomplete', [CleaningDueController::class, 'uncomplete'])->name('cleaning.tasks.uncomplete');
        Route::get('stores/{store_id}/tasks/{task}/history', [CleaningDueController::class, 'history'])->name('cleaning.tasks.history');

        // Scoring settings — runtime-editable, so the 50/50 balance between the
        // two halves of the score can be retuned without a deploy.
        Route::get('settings', [CleaningSettingController::class, 'index'])->name('cleaning.settings.index');
        Route::put('settings', [CleaningSettingController::class, 'update'])->name('cleaning.settings.update');

        // Selectable periods (accounting calendar) — the client must NOT compute
        // week keys itself; ISO week numbers diverge from accounting ones in 2027.
        Route::get('periods', [CleaningPeriodController::class, 'index'])->name('cleaning.periods.index');

        // Track 2 — Evaluation (auditor/super)
        Route::get('evaluations', [EvaluationController::class, 'index'])->name('cleaning.evaluations.index');
        Route::post('evaluations', [EvaluationController::class, 'upsert'])->name('cleaning.evaluations.upsert');
        Route::post('evaluations/finalize', [EvaluationController::class, 'finalize'])->name('cleaning.evaluations.finalize');
        Route::post('evaluations/reopen', [EvaluationController::class, 'reopen'])->name('cleaning.evaluations.reopen');

        // Absent-task weight allocation (auditor chooses which tasks absorb it)
        Route::get('evaluations/allocations', [EvaluationAllocationController::class, 'index'])->name('cleaning.allocations.index');
        Route::post('evaluations/allocations', [EvaluationAllocationController::class, 'store'])->name('cleaning.allocations.store');
        // One button: the split built on one store, copied to the stores chosen.
        // Send dry_run=true first to preview what will be written and skipped.
        Route::post('evaluations/allocations/copy', [EvaluationAllocationController::class, 'copy'])->name('cleaning.allocations.copy');
        Route::delete('evaluations/allocations', [EvaluationAllocationController::class, 'destroy'])->name('cleaning.allocations.destroy');

        Route::get('inspection-items', [InspectionItemController::class, 'index'])->name('cleaning.items.index');
        Route::post('inspection-items', [InspectionItemController::class, 'store'])->name('cleaning.items.store');
        Route::put('inspection-items/{inspection_item}', [InspectionItemController::class, 'update'])->name('cleaning.items.update');
        Route::delete('inspection-items/{inspection_item}', [InspectionItemController::class, 'destroy'])->name('cleaning.items.destroy');

        // Reports
        Route::get('reports/csv', [CleaningReportController::class, 'csv'])->name('cleaning.reports.csv');
        Route::get('reports/data', [CleaningReportController::class, 'data'])->name('cleaning.reports.data');
    });
});
