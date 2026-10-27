<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sanctum token auth. This is the ONLY token auth in the starter.
 *
 * NEVER add Laravel's `token` auth guard: CVE-2026-114509 (CVSS
 * 9.2) lets an unauthenticated attacker log in as another user on
 * MySQL/MariaDB apps using that guard. Sanctum and Passport are
 * NOT affected. Tokens are stored hashed (sha256) by Sanctum and
 * revoked on logout.
 */
class AuthController extends Controller
{
    /** POST /api/auth/register — validated; returns a Sanctum token. */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => Hash::make($request->validated('password')),
        ]);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'user' => $user->only(['id', 'name', 'email']),
            'token' => $token,
            'token_type' => 'Bearer',
        ], 201);
    }

    /** POST /api/auth/login — validated; returns a Sanctum token. */
    public function login(LoginRequest $request): JsonResponse
    {
        // Verify credentials WITHOUT logging into the web guard:
        // Auth::attempt() would start a session, and Sanctum's guard
        // prefers a session-authenticated user (TransientToken) over
        // the Bearer token. This is a token-only API — no session.
        $user = User::where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            // Generic message on purpose: never reveal whether the
            // email exists or the password was wrong.
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'user' => $user->only(['id', 'name', 'email']),
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /** POST /api/auth/logout — revokes ONLY the token used. */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            // Token auth (the normal API case): delete this token only.
            $token->delete();
        } elseif ($request->hasSession()) {
            // Session auth (Sanctum stateful / TransientToken): there is
            // no token row to delete, so end the session instead.
            // Without this branch logout would 500 with
            // "Call to undefined method TransientToken::delete()".
            // hasSession() guards this: stateless API requests have no
            // session store, and must never reach for one.
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Logged out.']);
    }

    /** GET /api/me — the authenticated user. */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user()->only(['id', 'name', 'email']),
        ]);
    }
}
