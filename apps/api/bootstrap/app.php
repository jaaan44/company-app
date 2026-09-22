<?php

use App\Http\Middleware\EnsureAccountIsActive;
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
        $middleware->alias([
            'account.active' => EnsureAccountIsActive::class,
        ]);

        // Phase 26 (Staging Mobile Connectivity & TLS): staging terminates TLS at a
        // host-level Nginx that reverse-proxies over loopback to this application's
        // own Docker-internal Nginx (docker/nginx/default.conf), which in turn
        // reaches PHP-FPM over FastCGI on an isolated, single-tenant Docker network.
        // `at: '*'` is safe here specifically because the Docker-published port is
        // loopback-only (127.0.0.1) and never opened in the VPS firewall — the host
        // Nginx is the only process that can ever originate a request to this
        // application at all, so there is no arbitrary/public proxy to guard
        // against. `$headers` is intentionally omitted: TrustProxies' own default
        // bitmask already includes X-Forwarded-For/-Host/-Port/-Proto, exactly the
        // set the host Nginx vhost sends — see
        // docs/phases/V1_PHASE_26_STAGING_MOBILE_CONNECTIVITY_TLS_PLAN.md §9.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
