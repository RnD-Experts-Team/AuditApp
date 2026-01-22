<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\CameraFormController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\EntityController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CameraReportController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\AuditController;

Route::get('/', function () {
    return redirect('/dashboard');
});

Route::middleware(['auth.token.store'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    Route::resource('camera-forms', CameraFormController::class);

    Route::get('camera-reports', [CameraReportController::class, 'index'])->name('camera-reports.index');
    Route::get('camera-reports/export', [CameraReportController::class, 'export'])->name('camera-reports.export');

    Route::resource('audits', AuditController::class)->only(['index', 'show']);

    Route::resource('entities', EntityController::class)->except(['show', 'create', 'edit']);
    Route::resource('categories', CategoryController::class)->only(['store', 'update', 'destroy']);
});

require __DIR__ . '/settings.php';
