<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Auth throttles: login is 5/min, register is 10/min. Hammering
 * the endpoint must eventually return 429.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_is_throttled_after_5_attempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'nobody@example.com',
                'password' => 'wrong',
            ])->assertUnauthorized();
        }

        // 6th attempt inside the same minute: throttled.
        $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong',
        ])->assertStatus(429);
    }
}
