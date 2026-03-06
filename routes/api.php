<?php

use App\Http\Controllers\AuditController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/get-audits', [AuditController::class, 'getDataByDateRange']);