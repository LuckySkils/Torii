<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AnimeSeason;
use App\Models\Anime;
use App\Services\Metadata\AnimeFacets;
use App\Services\Metadata\AnimeFilters;
use App\Services\Schedule\ScheduleBuilder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The planned schedule page. The rows come from ScheduleBuilder, the same code
 * the MCP list_schedule tool uses. `linked` and `tracked` are filters the user
 * applies, never implied.
 */
class ScheduleController extends Controller
{
    public function index(Request $request, AnimeFacets $facets, ScheduleBuilder $schedule): Response
    {
        [$from, $to] = $schedule->range($request->query('from'), $request->query('to'));

        // Absent season/year mean "any" here (unlike /anime, which opens on the current season).
        $filters = AnimeFilters::fromArray([
            'season' => $request->query('season'),
            'year' => $request->query('year'),
            'format' => $this->listParam($request, 'format'),
            'linked' => $request->query('linked'),
            // The page offers "all" or "tracked" only.
            'tracked' => $request->query('tracked') === 'tracked' ? 'tracked' : 'all',
            'adult' => $request->query('adult'),
        ]);

        return Inertia::render('Schedule/Index', [
            'airings' => $schedule->airings($from, $to, $filters)['rows'],
            'filters' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'season' => $filters->season,
                'year' => $filters->year,
                'format' => $filters->formats,
                'linked' => $filters->linked,
                'tracked' => $filters->tracked,
                'adult' => $filters->adult,
            ],
            'filterOptions' => [
                'seasons' => array_map(fn (AnimeSeason $s) => $s->value, AnimeSeason::cases()),
                'years' => Anime::query()->whereNotNull('season_year')->distinct()->orderByDesc('season_year')->pluck('season_year')->all(),
                'formats' => $facets->formats(),
            ],
        ]);
    }
}
