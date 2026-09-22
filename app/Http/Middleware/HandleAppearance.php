<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shares the user's theme choice with the Blade view, read from the `appearance`
 * cookie (set client-side by the `useAppearance` hook). This lets app.blade.php
 * render the correct `dark` class and theme-color meta tags on the very first
 * response, before any JS runs, so there is no flash of the wrong theme.
 *
 * "system" (the default) can't be resolved server-side — the inline script in
 * app.blade.php handles that case by checking prefers-color-scheme itself.
 */
class HandleAppearance
{
    public function handle(Request $request, Closure $next): Response
    {
        View::share('appearance', $request->cookie('appearance') ?? 'system');

        return $next($request);
    }
}
