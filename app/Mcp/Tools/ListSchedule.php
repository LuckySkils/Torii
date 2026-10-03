<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\Schedule\ScheduleBuilder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_schedule')]
#[Description(<<<'TEXT'
    Episode airings (Japanese broadcast schedule from AniList) in a date range, oldest first, with the same filters as search_anime. Each row: episode, airsAt (ISO 8601 with offset), isEstimate (true when AniList had no exact time and the slot was projected from the weekly pattern), a compact anime summary, show (the linked Torii show: id, name, isTracked) or null, releaseState and isNewSeries (episode 1, or a series that started within the last 14 days). releaseState compares an airing that has already happened with SubsPlease releases: "downloaded", "released" (a release exists, not downloaded yet) or "waiting" (no release yet); it is null for future airings, anime without a linked show, and shows whose release numbering doesn't follow AniList's (e.g. a sequel numbered on from season 1). Use it for "what airs tonight / this week"; for the overall state of tracked shows, tracked_summary is more compact. Read-only.
    TEXT)]
#[IsReadOnly]
final class ListSchedule extends ToriiTool
{
    private const DEFAULT_LIMIT = 50;

    public function __construct(private readonly ScheduleBuilder $schedule) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Range start, ISO 8601 date or date-time. Default: now.'),
            'to' => $schema->string()->description('Range end, ISO 8601. Default: 7 days after from. At most '.ScheduleBuilder::MAX_RANGE_DAYS.' days after from.'),
            ...$this->animeFilterSchema($schema),
            ...$this->pagingSchema($schema, self::DEFAULT_LIMIT),
        ];
    }

    public function handle(Request $request): Response
    {
        [$from, $to] = $this->schedule->range($request->get('from'), $request->get('to'));
        $offset = $this->offset($request);
        $airings = $this->schedule->airings($from, $to, $this->animeFilters($request), $this->limit($request, self::DEFAULT_LIMIT), $offset);

        return $this->json([
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'total' => $airings['total'],
            'offset' => $offset,
            // Cover URLs point at the UI, which the MCP listener doesn't serve.
            'airings' => array_map(function (array $row): array {
                unset($row['anime']['coverUrl'], $row['anime']['coverWidth'], $row['anime']['coverHeight']);

                return $row;
            }, $airings['rows']),
        ]);
    }
}
