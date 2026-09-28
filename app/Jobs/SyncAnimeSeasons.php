<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\MetadataProvider;
use App\Enums\AnimeSeason;
use App\Models\AnimeExternalId;
use App\Services\Metadata\AnimeSeasons;
use App\Services\Metadata\AnimeSyncer;
use App\Services\Metadata\MetadataProviderException;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Upserts every anime of the given seasons (and, with $allLinked, refreshes every
 * linked anime), then queues covers for new entries (and changed cover URLs) and
 * runs automatic matching.
 */
final class SyncAnimeSeasons implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Below the database queue's 90s retry_after. */
    public int $timeout = 80;

    public int $maxExceptions = 3;

    /**
     * @param  array<int, array{season: string, year: int}>  $seasons
     */
    public function __construct(
        public readonly array $seasons,
        public readonly bool $allLinked = false,
    ) {}

    /**
     * The weekly run: the previous, current and next season, plus every linked
     * anime. The previous season catches shows still airing from it (split cours,
     * long runs); it's one season back, not a backfill.
     */
    public static function weekly(bool $allLinked = true): self
    {
        $seasons = app(AnimeSeasons::class);

        return new self([
            self::target(...$seasons->previous()),
            self::target(...$seasons->current()),
            self::target(...$seasons->next()),
        ], $allLinked);
    }

    /**
     * @return array{season: string, year: int}
     */
    public static function target(AnimeSeason $season, int $year): array
    {
        return ['season' => $season->value, 'year' => $year];
    }

    public function uniqueId(): string
    {
        return collect($this->seasons)->map(fn (array $target) => $target['season'].'-'.$target['year'])->implode(',')
            .($this->allLinked ? '+linked' : '');
    }

    /** Rate-limit releases don't count against tries; real failures do (maxExceptions). */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function handle(MetadataProvider $provider, AnimeSyncer $syncer, AnimeSeasons $animeSeasons): void
    {
        $newIds = [];
        $coverIds = [];
        $seen = [];
        $counts = [];

        try {
            foreach ($this->seasons as $target) {
                $count = 0;

                foreach ($provider->season($target['season'], $target['year']) as $entry) {
                    $result = $syncer->upsert($entry);
                    $seen[$entry->externalId] = true;
                    $count++;

                    if ($result->created) {
                        $newIds[] = $result->anime->id;
                    }

                    if ($result->coverChanged) {
                        $coverIds[] = $result->anime->id;
                    }
                }

                $counts[$target['season'].' '.$target['year']] = $count;
            }

            if ($this->allLinked) {
                $linkedIds = AnimeExternalId::query()
                    ->where('provider', $provider->key())
                    ->whereHas('anime.links')
                    ->pluck('external_id')
                    ->reject(fn (string $id) => isset($seen[$id]))
                    ->values()
                    ->all();

                foreach ($provider->byExternalIds($linkedIds) as $entry) {
                    $result = $syncer->upsert($entry);

                    if ($result->coverChanged) {
                        $coverIds[] = $result->anime->id;
                    }
                }

                $counts['linked'] = count($linkedIds);
            }
        } catch (MetadataProviderException $e) {
            if (! $e->rateLimited) {
                throw $e;
            }

            // Everything so far is saved; the rerun re-upserts it idempotently.
            logger()->warning('Anime season sync rate limited; released', ['retry_after' => $e->retryAfter]);
            $this->release($e->retryAfter ?? 60);

            return;
        }

        logger()->info('Anime season sync finished', ['entries' => $counts, 'new' => count($newIds), 'cover_changed' => count($coverIds)]);

        // New entries, and existing ones whose stored cover is now stale.
        $eligible = $animeSeasons->coverEligible()
            ->whereIn('id', array_unique([...$newIds, ...$coverIds]))
            ->orderBy('id')
            ->pluck('id');

        foreach ($eligible->values() as $i => $animeId) {
            FetchAnimeCover::dispatch($animeId)->delay(now()->addSeconds($i * 2));
        }

        MatchShowsToAnime::dispatch();
    }
}
