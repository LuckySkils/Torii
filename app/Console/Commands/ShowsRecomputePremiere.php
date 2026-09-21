<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Show;
use App\Services\Premiere\PremiereCalculator;
use Illuminate\Console\Command;

class ShowsRecomputePremiere extends Command
{
    protected $signature = 'shows:recompute-premiere';

    protected $description = 'Recompute premiere date, season and year for every show from existing release data (no network calls)';

    public function handle(PremiereCalculator $calculator): int
    {
        $shows = Show::all();
        $updated = 0;

        foreach ($shows as $show) {
            $result = $calculator->fromEpisode1OrEarliest($show);

            if ($calculator->apply($show, $result)) {
                $updated++;
            }
        }

        $this->info("Recomputed premiere data for {$shows->count()} shows, updated {$updated}.");

        return self::SUCCESS;
    }
}
