<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TSD-1107 — the response headers the security section promises.
 *
 * The content-security-policy is only applied to a production build. The Vite
 * dev server loads scripts and an HMR websocket from a second origin, so a CSP
 * wide enough to be true in development would be a CSP that protects nothing;
 * better to state plainly that the header appears with the real build. The
 * other three headers apply everywhere because nothing about them changes.
 */
class SecurityHeaders
{
    private const CSP_PRODUCTION = "default-src 'self'; "
        ."script-src 'self'; "
        ."style-src 'self' 'unsafe-inline'; "            // Inertia puts page data in an inline attribute
        ."img-src 'self' data: blob:; "
        ."font-src 'self' data:; "
        ."connect-src 'self'; "
        ."frame-ancestors 'none'; "
        ."base-uri 'self'; "
        ."form-action 'self'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');

        if (app()->isProduction()) {
            $response->headers->set('Content-Security-Policy', self::CSP_PRODUCTION);
        }

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
