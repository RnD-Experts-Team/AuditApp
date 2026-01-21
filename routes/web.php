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
    return redirect('/login');
});

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    Route::resource('camera-forms', CameraFormController::class);
    // Camera Reports
    Route::get('camera-reports', [CameraReportController::class, 'index'])->name('camera-reports.index');
    Route::get('camera-reports/export', [CameraReportController::class, 'export'])->name('camera-reports.export');

    Route::middleware('admin.only')->group(function () {
        // Route::resource('stores', StoreController::class)->except(['show', 'create', 'edit']);
        Route::resource('entities', EntityController::class)->except(['show', 'create', 'edit']);
        Route::resource('categories', CategoryController::class)->only(['store', 'update', 'destroy']);
        // Route::resource('users', UserController::class);
        // STORES: READ ONLY
        Route::get('/stores', [StoreController::class, 'index'])->name('stores.index');

        // USERS: INDEX + UPDATE ONLY (role/groups)
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    });
});

require __DIR__ . '/settings.php';
