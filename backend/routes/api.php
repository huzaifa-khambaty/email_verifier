<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ImportBatchController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Single-tenant SPA auth (Sanctum, cookie-based) — see DECISIONS.md
// "Auth & users". No public registration endpoint.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/dashboard', [DashboardController::class, 'index']);

    Route::get('/import-batches', [ImportBatchController::class, 'index']);
    Route::post('/import-batches', [ImportBatchController::class, 'store']);
    Route::get('/import-batches/{importBatch}', [ImportBatchController::class, 'show']);
    Route::get('/import-batches/{importBatch}/errors', [ImportBatchController::class, 'errors']);
});
