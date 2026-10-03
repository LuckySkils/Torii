<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SeparateMcpListener;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // MCP (§15): the route exists only when enabled; outside the web group (no session/CSRF).
            if (config('subtracker.mcp.enabled')) {
                Route::group([], __DIR__.'/../routes/mcp.php');
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Before routing, so the MCP listener 404s on everything but /mcp, assets included.
        $middleware->prepend(SeparateMcpListener::class);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
