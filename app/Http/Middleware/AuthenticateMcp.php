<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The MCP endpoint's whole auth story (§15): with MCP_TOKEN set, every request
 * needs `Authorization: Bearer <token>`, compared in constant time. Kept to this
 * one middleware so a second scheme (OAuth) can be added without touching tools.
 *
 * Also the transport's DNS-rebinding guard: a browser request whose Origin isn't
 * Torii's own (APP_URL) is refused. Non-browser clients send no Origin.
 */
class AuthenticateMcp
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');

        if ($origin !== null && $origin !== '' && ! $this->isOwnOrigin($origin)) {
            return response()->json(['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => 'Forbidden origin.']], 403);
        }

        $token = (string) config('subtracker.mcp.token');

        if ($token !== '' && ! hash_equals($token, (string) $request->bearerToken())) {
            return response()
                ->json(['jsonrpc' => '2.0', 'error' => ['code' => -32001, 'message' => 'Unauthorized: a valid bearer token is required.']], 401)
                ->header('WWW-Authenticate', 'Bearer realm="mcp"');
        }

        return $next($request);
    }

    private function isOwnOrigin(string $origin): bool
    {
        $own = parse_url((string) config('app.url'));

        if (! is_array($own) || ! isset($own['scheme'], $own['host'])) {
            return false;
        }

        $expected = $own['scheme'].'://'.$own['host'].(isset($own['port']) ? ':'.$own['port'] : '');

        return strcasecmp(rtrim($origin, '/'), $expected) === 0;
    }
}
