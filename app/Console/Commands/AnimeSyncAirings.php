<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncAnimeAirings;
use Illuminate\Console\Command;

class AnimeSyncAirings extends Command
{
    protected $signature = 'anime:sync-airings {--linked : Only anime linked to a show, not the whole current season}';

    protected $description = 'Queue a refresh of anime airing schedules and aired-episode counts';

    public function handle(): int
    {
        SyncAnimeAirings::dispatch((bool) $this->option('linked'));

        $this->info('Queued airing sync'.($this->option('linked') ? ' for linked anime.' : '.'));

        return self::SUCCESS;
    }
}
