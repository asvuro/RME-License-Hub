<?php

use App\Auth\Guards\TenantTokenGuard;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        // When the dedicated "hub" admin guard rejects an unauthenticated
        // request, redirect to the admin login (NOT the client "login" route).
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('hub/*') || $request->routeIs('hub.*')) {
                return route('hub.login');
            }

            return null;
        });

        // Ensure the framework knows which guard owns the "hub" route space
        // so the Authenticate middleware can pick the right redirect.
        $middleware->alias([
            'auth.hub' => \Illuminate\Auth\Middleware\Authenticate::using('hub'),
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['api']],
    )
    ->booted(function (Application $app): void {
        // Register the stateless tenant (branch instance) token guard used by
        // Reverb / broadcasting channel authorization.
        Auth::extend('tenant-token', function ($container, $name, array $config) {
            return new TenantTokenGuard($container['request']);
        });
    })
    ->create();
