<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\FetchAnimeCover;
use App\Services\Metadata\AnimeSeasons;
use Illuminate\Console\Command;

class AnimeFetchImages extends Command
{
    protected $signature = 'anime:fetch-images {--missing : Only anime with no stored cover yet}';

    protected $description = 'Download anime covers (current and next season, and linked anime) into the database';

    public function handle(AnimeSeasons $seasons): int
    {
        $query = $seasons->coverEligible()->orderBy('id');

        if ($this->option('missing')) {
            $query->whereDoesntHave('image');
        }

        $ids = $query->pluck('id');

        foreach ($ids->values() as $i => $animeId) {
            FetchAnimeCover::dispatch($animeId)->delay(now()->addSeconds($i * 2));
        }

        $this->info("Dispatched cover fetches for {$ids->count()} anime.");

        return self::SUCCESS;
    }
}
