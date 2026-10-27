<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Health endpoint: must report the REAL database status, and the
 * full response body is asserted — a 200 with a degraded DB is a
 * failure, not a pass.
 */
class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_returns_200_with_real_db_status(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk();
        $response->assertJson([
            'status' => 'ok',
            'db' => 'ok',
            'db_error' => null,
            'php_version' => PHP_VERSION,
            'sanctum' => 'installed',
        ]);
        $this->assertSame('8.5.12', $response->json('php_version'));
        $this->assertNotEmpty($response->json('laravel_version'));
        $this->assertNotEmpty($response->json('timestamp'));
    }

    public function test_health_reports_degraded_db_as_503(): void
    {
        // Roll the RefreshDatabase transaction back FIRST, while the
        // original connection is still intact: the purge below
        // discards that connection object, and the transaction it
        // holds would otherwise stay open on the cached in-memory
        // PDO — the next test's BEGIN would then fail with "cannot
        // start a transaction within a transaction".
        DB::rollBack();

        // Point the default connection at a database file that
        // cannot be opened, then health must say so — loudly.
        config(['database.connections.sqlite.database' => '/nonexistent-dir/nope.sqlite']);
        // The test already opened the :memory: connection during
        // RefreshDatabase setup — purge it so the next query really
        // opens the (broken) configured database.
        DB::purge('sqlite');

        $response = $this->getJson('/api/health');

        $response->assertStatus(503);
        $response->assertJson([
            'status' => 'degraded',
            'db' => 'error',
        ]);
        $this->assertNotEmpty($response->json('db_error'));

        // Restore the healthy config BEFORE teardown: the
        // RefreshDatabase teardown callback resolves the default
        // connection to roll back, and resolving it against the
        // broken path throws SQLiteDatabaseDoesNotExistException out
        // of tearDown — which fails this test AND leaves the shared
        // in-memory PDO poisoned for every test after it.
        config(['database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
    }
}
