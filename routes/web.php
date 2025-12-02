<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use App\Http\Controllers\CameraFormController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\EntityController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CameraReportController;
use App\Http\Controllers\UserController;

Route::get('/', function () {
    return Inertia::render('welcome', [
        // 'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    Route::resource('camera-forms', CameraFormController::class);
    Route::resource('stores', StoreController::class)->except(['show', 'create', 'edit']);
    Route::resource('entities', EntityController::class)->except(['show', 'create', 'edit']);
    Route::resource('categories', CategoryController::class)->only(['store', 'update', 'destroy']);
    Route::resource('users', UserController::class);
    // Camera Reports
    Route::get('camera-reports', [CameraReportController::class, 'index'])->name('camera-reports.index');
    Route::get('camera-reports/export', [CameraReportController::class, 'export'])->name('camera-reports.export');
});

require __DIR__.'/settings.php';
