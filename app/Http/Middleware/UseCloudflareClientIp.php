<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Behind the Cloudflare Tunnel, X-Forwarded-For starts with whatever the
 * visitor sent, so request()->ip() — and every per-IP rate limit and login
 * lockout — could be dodged with a made-up header. Cloudflare also sends
 * CF-Connecting-IP, which it always overwrites with the real visitor address.
 *
 * Registered (before TrustProxies) only when TRUSTED_PROXIES is set, and it
 * only acts on requests from this machine or the Docker network — the
 * tunnel — so nobody reaching the app another way can fake
 * CF-Connecting-IP either.
 */
class UseCloudflareClientIp
{
    /** Where the tunnel connects from: the cloudflared container or the Windows service. */
    private const TUNNEL_SOURCES = ['127.0.0.0/8', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];

    public function handle(Request $request, Closure $next): Response
    {
        $visitor = $request->headers->get('CF-Connecting-IP');

        if ($visitor !== null
            && filter_var($visitor, FILTER_VALIDATE_IP)
            && IpUtils::checkIp((string) $request->server->get('REMOTE_ADDR'), self::TUNNEL_SOURCES)) {
            $request->headers->set('X-Forwarded-For', $visitor);
            $request->server->set('HTTP_X_FORWARDED_FOR', $visitor);
        }

        return $next($request);
    }
}
