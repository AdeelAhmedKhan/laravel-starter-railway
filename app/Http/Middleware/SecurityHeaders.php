<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every response.
 *
 * Defence in depth with docker/nginx.conf, which sets the same
 * headers as a backstop for responses that never reach Laravel
 * (static 404s, nginx errors). This middleware is the source of
 * truth for application responses.
 *
 * - CSP: only same-origin scripts/styles/images; no plugins,
 *   no framing by other origins, forms only to self.
 * - HSTS: 1 year, include subdomains. Browsers ignore HSTS on
 *   plain HTTP, so sending it here is safe even behind Railway's
 *   TLS-terminating proxy (see TrustProxies in bootstrap/app.php).
 * - X-Content-Type-Options: nosniff stops MIME-sniffing attacks.
 * - X-Frame-Options: SAMEORIGIN stops clickjacking.
 * - Referrer-Policy: no-referrer-when-downgrade.
 * - Permissions-Policy: no camera / microphone / geolocation.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
            ."img-src 'self' data:; font-src 'self'; object-src 'none'; "
            ."base-uri 'self'; frame-ancestors 'self'; form-action 'self'"
        );
        $response->headers->set(
            'Strict-Transport-Security',
            'max-age=31536000; includeSubDomains'
        );
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'no-referrer-when-downgrade');
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=()'
        );
        // Never leak the PHP version. docker/php/security.ini sets
        // expose_php=0 for the container, but strip it here too —
        // defence in depth, and it also covers `php artisan serve`,
        // whose CLI php.ini has expose_php=1 by default. The
        // header_remove() call matters: the SAPI adds X-Powered-By
        // outside the Symfony response, so removing it from the
        // HeaderBag alone does not suppress it.
        $response->headers->remove('X-Powered-By');
        if (function_exists('header_remove')) {
            header_remove('X-Powered-By');
        }

        return $response;
    }
}
