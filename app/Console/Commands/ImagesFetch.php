<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ImageStatus;
use App\Jobs\FetchShowImage;
use App\Models\Show;
use Illuminate\Console\Command;

class ImagesFetch extends Command
{
    protected $signature = 'images:fetch {--missing : Fetch shows with none, missing or error status (default)} {--all : Re-check every show, even ones already found}';

    protected $description = 'Backfill show poster images from SubsPlease';

    public function handle(): int
    {
        $query = Show::query()->orderBy('id');

        if (! $this->option('all')) {
            $query->whereIn('image_status', [ImageStatus::None, ImageStatus::Missing, ImageStatus::Error]);
        }

        $shows = $query->get();

        foreach ($shows as $i => $show) {
            FetchShowImage::dispatch($show->id)->delay(now()->addSeconds($i * 3));
        }

        $this->info("Dispatched image fetches for {$shows->count()} shows.");

        return self::SUCCESS;
    }
}
