<?php

use App\Http\Middleware\AuthenticateMcp;
use App\Mcp\Servers\ToriiServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

// Loaded by bootstrap/app.php only when MCP_ENABLED (§15), outside the `web` group:
// no session, no CSRF. With it off, /mcp doesn't exist at all.
Route::middleware([AuthenticateMcp::class, 'throttle:mcp'])->group(function () {
    Mcp::web('/mcp', ToriiServer::class)->name('mcp');
});
