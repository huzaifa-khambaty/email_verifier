<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmailController;
use App\Http\Controllers\Api\ImportBatchController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Single-tenant SPA auth (Sanctum, cookie-based) — see DECISIONS.md
// "Auth & users". No public registration endpoint.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

// Session probe, deliberately NOT behind auth:sanctum. The SPA's router
// guard calls this on first navigation to answer "is there an existing
// session?", and "no" is a normal answer to that question, not an error.
// Behind auth:sanctum it answered 401, which browsers log to the console
// as a failed request even though the app handles it correctly — noise
// that reads like a real bug on the login page. It only ever exposes the
// caller's own record.
//
// The {"user": ...} envelope is deliberate: `response()->json(null)`
// serializes to `{}`, which is TRUTHY in JS, so a bare body would make
// the frontend read "no session" as "logged in" and wave guests past the
// router guard. An explicit null-able key can't be misread that way.
Route::get('/user', fn (Request $request) => response()->json(['user' => $request->user()]));

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Export before the paginated list is irrelevant to routing order
    // here (distinct paths), but keep /stats and /export above any
    // future /emails/{email} route so they aren't captured as an id.
    Route::get('/emails', [EmailController::class, 'index']);
    Route::get('/emails/stats', [EmailController::class, 'stats']);
    Route::get('/emails/export', [EmailController::class, 'export']);

    Route::get('/import-batches', [ImportBatchController::class, 'index']);
    Route::post('/import-batches', [ImportBatchController::class, 'store']);
    Route::get('/import-batches/{importBatch}', [ImportBatchController::class, 'show']);
    Route::get('/import-batches/{importBatch}/errors', [ImportBatchController::class, 'errors']);
});
