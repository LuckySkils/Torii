<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Mcp\Data\TrackedSummary;
use App\Mcp\Redactor;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Name('tracked-shows')]
#[Description('Every currently tracked show with episodes aired, released and downloaded, next airing and what is pending: the same payload as the tracked_summary tool.')]
#[Uri('torii://tracked')]
#[MimeType('application/json')]
final class TrackedShowsResource extends Resource
{
    public function handle(Request $request, TrackedSummary $summary): Response
    {
        return Response::json(Redactor::clean($summary->build((int) config('subtracker.mcp.max_results'))));
    }
}
