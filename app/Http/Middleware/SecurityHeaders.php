<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser hardening on every page: no framing (clickjacking), no MIME
 * sniffing, a Content-Security-Policy that only allows our own scripts plus
 * Bootstrap's CDN, and HSTS over HTTPS.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $cdn = 'https://cdn.jsdelivr.net';
        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self' {$cdn}",
            "style-src 'self' 'unsafe-inline' {$cdn}",
            "font-src 'self' {$cdn}",
            "img-src 'self' data:",
            "connect-src 'self'",
            // The Pay button's POST redirects to PayMongo's checkout page,
            // and browsers apply form-action to that redirect too.
            "form-action 'self' https://*.paymongo.com",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "object-src 'none'",
        ]));
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
