<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AnimeSeason;
use App\Jobs\SyncAnimeSeasons;
use App\Services\Metadata\AnimeSeasons;
use Illuminate\Console\Command;

class AnimeSyncSeason extends Command
{
    protected $signature = 'anime:sync-season
        {season? : WINTER, SPRING, SUMMER or FALL (default: the current season)}
        {year? : Season year (default: the current season\'s year)}
        {--next : Sync the season after the current one instead}
        {--all-linked : Also refresh every anime linked to a show}';

    protected $description = 'Queue a sync of one season of anime metadata from the metadata provider';

    public function handle(AnimeSeasons $seasons): int
    {
        $seasonArgument = $this->argument('season');

        if ($seasonArgument !== null) {
            $season = AnimeSeason::tryFrom(strtoupper((string) $seasonArgument));

            if ($season === null) {
                $this->error("Unknown season [{$seasonArgument}]; use WINTER, SPRING, SUMMER or FALL.");

                return self::INVALID;
            }

            $year = $this->argument('year') !== null ? (int) $this->argument('year') : $seasons->current()[1];
        } else {
            [$season, $year] = $this->option('next') ? $seasons->next() : $seasons->current();
        }

        SyncAnimeSeasons::dispatch([SyncAnimeSeasons::target($season, $year)], (bool) $this->option('all-linked'));

        $this->info("Queued anime sync for {$season->value} {$year}".($this->option('all-linked') ? ' plus all linked anime.' : '.'));

        return self::SUCCESS;
    }
}
