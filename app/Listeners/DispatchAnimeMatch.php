<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ShowDiscovered;
use App\Jobs\MatchShowsToAnime;

final class DispatchAnimeMatch
{
    public function handle(ShowDiscovered $event): void
    {
        MatchShowsToAnime::dispatch($event->show->id);
    }
}
