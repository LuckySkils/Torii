<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\FetchAnimeCover;
use App\Models\Anime;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class AnimeFetchImages extends Command
{
    protected $signature = 'anime:fetch-images
        {--missing : Anime with no stored cover (the default)}
        {--all : Every anime, stored or not; unchanged covers are not rewritten}
        {--upgrade : Anime whose stored cover came from a different URL than its current one (the old smaller size)}';

    protected $description = 'Download anime covers into the database, spaced out on the covers channel';

    public function handle(): int
    {
        $query = Anime::query()->whereNotNull('cover_url')->orderBy('id');

        if ($this->option('upgrade')) {
            $query->whereHas('image', fn (Builder $image) => $image->whereColumn('anime_images.source_url', '!=', 'anime.cover_url'));
            $what = 'stored at an old size or URL';
        } elseif ($this->option('all')) {
            $what = 'in the database';
        } else {
            $query->whereDoesntHave('image');
            $what = 'without a cover';
        }

        $ids = $query->pluck('id');

        foreach ($ids as $animeId) {
            FetchAnimeCover::dispatchSpaced($animeId);
        }

        $this->info("Dispatched cover fetches for {$ids->count()} anime {$what}, ".FetchAnimeCover::SPACING_SECONDS.'s apart.');

        return self::SUCCESS;
    }
}
