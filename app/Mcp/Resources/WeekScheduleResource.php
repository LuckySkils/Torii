<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Mcp\Redactor;
use App\Services\Metadata\AnimeFilters;
use App\Services\Schedule\ScheduleBuilder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Name('schedule-this-week')]
#[Description('Airings of anime linked to a Torii show over the next 7 days, oldest first, in the list_schedule row shape (adult titles hidden). total counts all of them; truncated is true when more exist than the result cap. For other ranges or unlinked anime use the list_schedule tool.')]
#[Uri('torii://schedule/this-week')]
#[MimeType('application/json')]
final class WeekScheduleResource extends Resource
{
    public function handle(Request $request, ScheduleBuilder $schedule): Response
    {
        [$from, $to] = $schedule->range(null, null);
        $limit = (int) config('subtracker.mcp.max_results');
        $airings = $schedule->airings($from, $to, new AnimeFilters(linked: 'linked'), $limit);

        return Response::json(Redactor::clean([
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'total' => $airings['total'],
            'truncated' => $airings['total'] > $limit,
            'airings' => array_map(function (array $row): array {
                unset($row['anime']['coverUrl'], $row['anime']['coverWidth'], $row['anime']['coverHeight']);

                return $row;
            }, $airings['rows']),
        ]));
    }
}
