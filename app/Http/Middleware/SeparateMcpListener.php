<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Mcp\Listener;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * With MCP_ENABLED and MCP_PORT set, the two listeners serve disjoint surfaces:
 * the MCP listener answers only /mcp, the main one everything except /mcp. Both
 * get a plain 404 otherwise. Global, so it runs before routing (assets and the
 * health route included). Which listener is decided by App\Mcp\Listener, from
 * the process, never from a header a client can set.
 */
class SeparateMcpListener
{
    public function __construct(private readonly Listener $listener) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('subtracker.mcp.enabled') || config('subtracker.mcp.port') === null) {
            return $next($request);
        }

        $isMcpPath = trim($request->path(), '/') === 'mcp';
        $onMcpListener = $this->listener->current() === Listener::MCP;

        if ($isMcpPath !== $onMcpListener) {
            abort(404);
        }

        return $next($request);
    }
}
