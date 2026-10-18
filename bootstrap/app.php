<?php

use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\PreventBackHistory;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [SecurityHeaders::class, PreventBackHistory::class]);

        $middleware->alias([
            'role' => EnsureRole::class,
            'email.verified' => EnsureEmailIsVerified::class,
            'active' => EnsureAccountActive::class,
        ]);

        // PayMongo can't send our CSRF token; the webhook is authenticated
        // by its HMAC signature instead.
        $middleware->validateCsrfTokens(except: ['webhooks/paymongo']);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('home'));

        // Behind Railway / a tunnel, set TRUSTED_PROXIES=* so request()->ip()
        // (used by the rate limits) is the real client. Left unset when the
        // app is reached directly, so nobody can fake their IP with an
        // X-Forwarded-For header to dodge the limits.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : explode(',', $proxies));
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
