<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Security headers on every response (CSP, HSTS, nosniff...).
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        // Railway terminates TLS at its proxy: trust X-Forwarded-*
        // so URL generation, secure cookies and HSTS see https.
        $middleware->trustProxies(at: '*');
        // No login page exists in this API-only app: never let the
        // auth middleware redirect guests to a `login` route (there
        // is none — the default redirect 500s with "Route [login]
        // not defined" while CONSTRUCTING the AuthenticationException,
        // before the exception handler below ever sees it). Guests
        // go to the landing page; API requests get a JSON 401 from
        // the exception render instead.
        $middleware->redirectGuestsTo(fn (Request $request) => '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Unauthenticated API requests must ALWAYS be JSON 401 — never
        // a redirect. Without this, AuthenticationException renders a
        // redirect to the named `login` route, which does not exist in
        // this API-only app, so a client without an Accept header got
        // a 500 "Route [login] not defined" instead of a 401.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });
    })->create();
