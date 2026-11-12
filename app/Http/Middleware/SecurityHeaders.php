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
        // Conteúdo publicitário HTML só pode ser embutido no próprio site, em iframe isolado.
        $isAdEmbed = $request->is('api/v1/public/ads/*/embed');
        $response->headers->set('X-Frame-Options', $isAdEmbed ? 'SAMEORIGIN' : 'DENY');
        // Sanctum statefulApi() precisa de Origin/Referer em requests first-party, inclusive GET.
        // 'no-referrer' impediria a sessão nas chamadas API seguintes.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if ($request->is('api/*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }
        if ($isAdEmbed) {
            $response->headers->set('Content-Security-Policy', "default-src https: data:; script-src 'unsafe-inline' https:; style-src 'unsafe-inline' https:; img-src https: data:; connect-src https:; frame-src https:; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
        } elseif (app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
            // Third-party AdSense is allowed only on the public Home, and only loads
            // after an explicit visitor action in AceHomeAd.vue.
            $isHome = $request->is('/');
            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                $isHome ? "script-src 'self' https://pagead2.googlesyndication.com https://*.googlesyndication.com https://*.google.com" : "script-src 'self'",
                "style-src 'self' 'unsafe-inline'",
                $isHome ? "img-src 'self' data: https://*.googlesyndication.com https://*.doubleclick.net https://*.google.com" : "img-src 'self' data:",
                "font-src 'self' data:",
                $isHome ? "connect-src 'self' https://*.googlesyndication.com https://*.doubleclick.net https://*.google.com" : "connect-src 'self'",
                $isHome ? "frame-src https://*.googlesyndication.com https://*.doubleclick.net https://*.google.com" : "frame-src 'none'",
                "base-uri 'self'", "object-src 'none'", "frame-ancestors 'none'", "form-action 'self'",
            ]));
        }

        return $response;
    }
}
