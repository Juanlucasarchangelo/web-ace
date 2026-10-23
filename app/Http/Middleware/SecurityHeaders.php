<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        // Sanctum statefulApi() precisa de Origin/Referer em requests first-party, inclusive GET.
        // 'no-referrer' impediria a sessão nas chamadas API seguintes.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if ($request->is('api/*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }
        if (app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'", "script-src 'self'", "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data:", "font-src 'self' data:", "connect-src 'self'",
                "base-uri 'self'", "object-src 'none'", "frame-ancestors 'none'", "form-action 'self'",
            ]));
        }

        return $response;
    }
}
