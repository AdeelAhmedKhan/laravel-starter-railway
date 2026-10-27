<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/health — the Railway deploy healthcheck and the honest
 * status endpoint.
 *
 * Reports the REAL database status. A 200 with a degraded database
 * is a failure, so when the DB is unreachable this returns HTTP 503
 * with db="error" — Railway will not route traffic to a broken app,
 * and no caller can mistake it for healthy.
 *
 * Never invents data: versions come from the running PHP and the
 * installed framework; Sanctum status is a class_exists() check.
 */
class HealthController extends Controller
{
    public function show(): JsonResponse
    {
        $dbOk = false;
        $dbError = null;

        try {
            DB::connection()->getPdo();
            DB::select('select 1');
            $dbOk = true;
        } catch (\Throwable $e) {
            // Message only — never a stack trace, never credentials.
            $dbError = $e->getMessage();
        }

        return response()->json([
            'status' => $dbOk ? 'ok' : 'degraded',
            'db' => $dbOk ? 'ok' : 'error',
            'db_error' => $dbError,
            'db_connection' => config('database.default'),
            'php_version' => PHP_VERSION,
            'laravel_version' => Application::VERSION,
            'sanctum' => class_exists(\Laravel\Sanctum\Sanctum::class)
                ? 'installed'
                : 'missing',
            'timestamp' => now()->toIso8601String(),
        ], $dbOk ? 200 : 503);
    }
}
