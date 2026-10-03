<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * Which listener served this request: `main` (the UI's port) or `mcp` (the
 * MCP_PORT listener). Each FrankenPHP process listens on exactly one port and is
 * started by supervisor with its own TORII_LISTENER (docker/mcp-listener.sh, which
 * also sets it as a server variable in docker/mcp.Caddyfile), so this is the port
 * the request arrived on. Never derived from the request itself: FrankenPHP takes
 * SERVER_PORT from the client's Host header. A client can't set this server
 * variable either; headers only ever arrive as HTTP_*.
 */
class Listener
{
    public const MAIN = 'main';

    public const MCP = 'mcp';

    public function current(): string
    {
        // Not config(): config is cached once for both processes.
        $listener = $_SERVER['TORII_LISTENER'] ?? getenv('TORII_LISTENER');

        return $listener === self::MCP ? self::MCP : self::MAIN;
    }
}
