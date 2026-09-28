<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Metadata\Matching\ShowAnimeLinker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Automatic matching (§9.5), all local: no provider requests. Runs after a season
 * sync (every show) and when a new show appears (just that one).
 */
final class MatchShowsToAnime implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly ?int $showId = null) {}

    public function uniqueId(): string
    {
        return $this->showId === null ? 'all' : (string) $this->showId;
    }

    public function handle(ShowAnimeLinker $linker): void
    {
        $results = [];

        foreach ($linker->plan($this->showId) as $decision) {
            $result = $linker->apply($decision);
            $results[$result] = ($results[$result] ?? 0) + 1;

            if (($result === 'created' || $result === 'updated') && $decision->link !== null) {
                FetchAnimeCover::dispatchIfMissing($decision->link->candidate->animeId);
            }
        }

        if ($this->showId === null) {
            logger()->info('Anime matching finished', $results);
        }
    }
}
