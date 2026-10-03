<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Data\TrackedSummary as TrackedSummaryData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('tracked_summary')]
#[Description(<<<'TEXT'
    A compact snapshot of every tracked show, for questions like "what can I watch tonight" or "what's new" without paging through list_shows. Per show: id, name, the linked anime (id, titles, status, episodesTotal) or null, episodes {aired (AniList), released (highest SubsPlease episode), downloaded (highest episode qBittorrent finished)}, nextAiring, ruleState, trackingMode, waitingEpisodes (aired but not yet released by SubsPlease; null when unknown, e.g. no linked anime or release numbering that doesn't follow AniList's) and undownloadedReleases (releases seen but not finished downloading). Plus totals across all tracked shows; truncated is true when there were more shows than the result cap. Takes no parameters. Read-only.
    TEXT)]
#[IsReadOnly]
final class TrackedSummary extends ToriiTool
{
    public function __construct(private readonly TrackedSummaryData $summary) {}

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        return $this->json($this->summary->build($this->maxResults()));
    }
}
