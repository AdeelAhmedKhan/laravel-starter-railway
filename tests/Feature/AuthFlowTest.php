<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The main user flow end to end against the real app:
 * register -> login -> protected route -> logout -> token dead.
 * No mocks of the HTTP layer.
 */
class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_login_protected_route_logout_flow(): void
    {
        // Protected route without a token: 401. Checked FIRST, before
        // any login in this test — the auth guard caches the resolved
        // user on the shared app instance, so a post-login check here
        // would pass for the wrong reason. (In production every HTTP
        // request is a fresh process, so this is a test-only caveat.)
        $this->getJson('/api/me')->assertUnauthorized();
        // Same, but WITHOUT an Accept: application/json header (plain
        // curl, a browser, any naive client): must still be a 401.
        // Before the AuthenticationException render in
        // bootstrap/app.php this was a 500 — Laravel tried to
        // redirect to a `login` route that does not exist in this
        // API-only app ("Route [login] not defined").
        $this->get('/api/me')->assertUnauthorized();

        // Register.
        $register = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);
        $register->assertCreated();
        $register->assertJsonStructure(['user' => ['id', 'name', 'email'], 'token', 'token_type']);
        $this->assertSame('test@example.com', $register->json('user.email'));

        // Login with the same credentials returns a fresh token.
        $login = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'Password123!',
        ]);
        $login->assertOk();
        $token = $login->json('token');
        $this->assertNotEmpty($token);

        // Protected route with the real token: 200, correct user.
        $me = $this->getJson('/api/me', ['Authorization' => "Bearer {$token}"]);
        $me->assertOk();
        $me->assertJson(['user' => ['email' => 'test@example.com']]);

        // Logout revokes the token: the token row is gone from the DB.
        $this->postJson('/api/auth/logout', [], ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJson(['message' => 'Logged out.']);
        $this->assertDatabaseCount('personal_access_tokens', 1); // only the register token remains
    }

    public function test_revoked_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api-token')->plainTextToken;

        // Works while valid.
        $this->getJson('/api/me', ['Authorization' => "Bearer {$token}"])
            ->assertOk();

        // Revoke it (what logout does), then it must be rejected.
        $user->tokens()->delete();
        // The auth guard caches the resolved user on the shared app
        // instance — forget it so the next request really re-checks
        // the (now deleted) token. In production every HTTP request
        // is a fresh process, so this is a test-only step.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/me', ['Authorization' => "Bearer {$token}"])
            ->assertUnauthorized();
    }

    public function test_seeded_demo_user_can_log_in(): void
    {
        $this->seed();

        $login = $this->postJson('/api/auth/login', [
            'email' => 'demo@example.com',
            'password' => 'Demo1234!',
        ]);
        $login->assertOk();
        $this->assertNotEmpty($login->json('token'));
    }

    public function test_login_with_wrong_password_is_401_with_generic_message(): void
    {
        User::factory()->create([
            'email' => 'someone@example.com',
            'password' => Hash::make('Correct123!'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'someone@example.com',
            'password' => 'Wrong123!',
        ]);

        $response->assertUnauthorized();
        $response->assertJson(['message' => 'Invalid credentials.']);
    }

    public function test_register_validation_rejects_bad_input(): void
    {
        // Missing password.
        $this->postJson('/api/auth/register', [
            'name' => 'No Password',
            'email' => 'nopass@example.com',
        ])->assertUnprocessable();

        // Short password.
        $this->postJson('/api/auth/register', [
            'name' => 'Short',
            'email' => 'short@example.com',
            'password' => 'abc',
            'password_confirmation' => 'abc',
        ])->assertUnprocessable();

        // Confirmation mismatch.
        $this->postJson('/api/auth/register', [
            'name' => 'Mismatch',
            'email' => 'mismatch@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Different123!',
        ])->assertUnprocessable();

        // Bad email.
        $this->postJson('/api/auth/register', [
            'name' => 'Bad Email',
            'email' => 'not-an-email',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertUnprocessable();

        // Duplicate email.
        User::factory()->create(['email' => 'dup@example.com']);
        $this->postJson('/api/auth/register', [
            'name' => 'Dup',
            'email' => 'dup@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertUnprocessable();
    }
}
