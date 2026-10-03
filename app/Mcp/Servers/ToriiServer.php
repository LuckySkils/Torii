<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Resources\AnimeResource;
use App\Mcp\Resources\ShowResource;
use App\Mcp\Resources\TrackedShowsResource;
use App\Mcp\Resources\WeekScheduleResource;
use App\Mcp\Tools\DownloadRelease;
use App\Mcp\Tools\GetAnime;
use App\Mcp\Tools\GetShow;
use App\Mcp\Tools\ListSchedule;
use App\Mcp\Tools\ListShows;
use App\Mcp\Tools\ListTags;
use App\Mcp\Tools\QueueMissing;
use App\Mcp\Tools\SearchAnime;
use App\Mcp\Tools\SuggestAnime;
use App\Mcp\Tools\SuggestLink;
use App\Mcp\Tools\TrackedSummary;
use App\Mcp\Tools\TrackShow;
use App\Mcp\Tools\UntrackShow;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Torii over MCP (agents/CLAUDE.md §15). Write tools register themselves only
 * with MCP_ALLOW_WRITES=true (WriteTool::shouldRegister).
 */
#[Name('Torii')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
    Torii is a self-hosted tracker that downloads anime episodes released by SubsPlease through qBittorrent. It keeps two linked sides: anime (AniList metadata for recent and current seasons: titles, genres, tags, studios, airing schedule) and shows (SubsPlease release names, which are what gets tracked and downloaded). An anime with a linked show can be downloaded; a tracked show downloads new episodes automatically. Anime ids and show ids are different id spaces.
    TEXT)]
final class ToriiServer extends Server
{
    /**
     * One page for every list (tools, resources, templates), so clients never
     * need cursor paging: 13 tools today, room to grow.
     */
    public int $defaultPaginationLength = 50;

    public int $maxPaginationLength = 50;

    protected array $tools = [
        SearchAnime::class,
        GetAnime::class,
        ListSchedule::class,
        ListShows::class,
        GetShow::class,
        TrackedSummary::class,
        SuggestLink::class,
        SuggestAnime::class,
        ListTags::class,
        TrackShow::class,
        UntrackShow::class,
        QueueMissing::class,
        DownloadRelease::class,
    ];

    protected array $resources = [
        TrackedShowsResource::class,
        WeekScheduleResource::class,
        AnimeResource::class,
        ShowResource::class,
    ];
}
