<?php

declare(strict_types=1);

namespace App\Services\Bootstrap;

use App\Jobs\RunArtisanCommand;
use App\Jobs\SyncAnimeAirings;
use App\Jobs\SyncAnimeSeasons;

/**
 * The one-time data tasks, in order. Add new ones at the end with a new key;
 * never rename a key, or every install runs that task again.
 */
class BootstrapTasks
{
    /**
     * @return array<int, BootstrapTaskDefinition>
     */
    public function all(): array
    {
        return [
            new BootstrapTaskDefinition(
                'feed.initial-poll',
                'Fetch the SubsPlease feed',
                [],
                fn () => [new RunArtisanCommand('feed:poll', ['--force' => true])],
            ),
            new BootstrapTaskDefinition(
                'images.initial-fetch',
                'Queue show posters',
                ['feed.initial-poll'],
                // Spaced 3s apart by images:fetch itself.
                fn () => [new RunArtisanCommand('images:fetch', ['--missing' => true])],
            ),
            new BootstrapTaskDefinition(
                'anime.initial-sync',
                'Sync anime seasons and match shows',
                // Not on the feed poll: a SubsPlease outage mustn't hold this up, and
                // shows that appear later are matched by the ShowDiscovered listener.
                [],
                fn () => [SyncAnimeSeasons::weekly(allLinked: true)],
            ),
            new BootstrapTaskDefinition(
                'anime.initial-airings',
                'Sync airing schedules',
                ['anime.initial-sync'],
                fn () => [new SyncAnimeAirings],
            ),
            new BootstrapTaskDefinition(
                'anime.initial-covers',
                'Queue anime covers',
                ['anime.initial-sync'],
                // Spaced 2s apart by anime:fetch-images itself.
                fn () => [new RunArtisanCommand('anime:fetch-images', ['--missing' => true])],
            ),
        ];
    }
}
