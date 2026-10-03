#!/bin/sh
# Supervisor's [program:mcp]: a second FrankenPHP process serving the same app on
# MCP_PORT, only when MCP_ENABLED is true and MCP_PORT is set. Otherwise it exits
# 0 straight away and supervisor leaves it stopped (exitcodes=0, autorestart=unexpected).
# The entrypoint has already rejected an MCP_PORT that clashes with the main port.
case "$(printf '%s' "${MCP_ENABLED:-false}" | tr '[:upper:]' '[:lower:]')" in
  1|true|on|yes) ;;
  *) exit 0 ;;
esac

if [ -z "$MCP_PORT" ]; then
  exit 0
fi

echo "[torii] MCP listener on port $MCP_PORT"
export TORII_LISTENER=mcp
exec frankenphp run --config /etc/frankenphp/mcp.Caddyfile --adapter caddyfile
