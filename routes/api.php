<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — security-hardened Laravel starter
|--------------------------------------------------------------------------
|
| Health is public (Railway healthchecks it). Auth routes are
| rate-limited beyond the default API throttle: login 5/min,
| register 10/min. Everything that reads user data is behind
| Sanctum — NEVER the `token` guard (CVE-2026-114509).
|
*/

// Public: Railway healthcheck + honest status. No auth, no throttle
// beyond the default `api` group limit (60/min).
Route::get('/health', [HealthController::class, 'show']);

// Auth: stricter throttles on top of the `api` group throttle.
Route::post('/auth/register', [AuthController::class, 'register'])
    ->middleware('throttle:10,1');
Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');

// Sanctum-protected.
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
});
