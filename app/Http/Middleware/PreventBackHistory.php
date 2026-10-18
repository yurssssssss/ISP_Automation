<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tells the browser never to keep a copy of our pages, so the Back button
 * asks the server again instead of replaying a cached screen: after signing
 * out it lands on the login page, and after signing in the login page
 * redirects to the dashboard. public/js/app.js covers the browsers' instant
 * back/forward cache, which can skip these headers.
 */
class PreventBackHistory
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', 'Sat, 01 Jan 2000 00:00:00 GMT');

        return $response;
    }
}
