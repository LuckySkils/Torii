<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * A tool that changes state. Registered only with MCP_ALLOW_WRITES=true, so it's
 * absent from tools/list (and uncallable) otherwise. Every call is logged.
 */
abstract class WriteTool extends ToriiTool
{
    public function shouldRegister(): bool
    {
        return (bool) config('subtracker.mcp.allow_writes');
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function done(Request $request, array $result): Response
    {
        Log::info('MCP write tool called', [
            'tool' => $this->name(),
            'arguments' => $request->all(),
            'result' => $result,
        ]);

        return $this->json($result);
    }

    protected function failed(Request $request, string $message): Response
    {
        Log::info('MCP write tool called', [
            'tool' => $this->name(),
            'arguments' => $request->all(),
            'result' => ['error' => $message],
        ]);

        return Response::error($message);
    }
}
