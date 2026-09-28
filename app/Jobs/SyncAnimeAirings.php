<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\MetadataProvider;
use App\Models\Anime;
use App\Services\Metadata\AnimeAiringWriter;
use App\Services\Metadata\AnimeSeasons;
use App\Services\Metadata\MetadataProviderException;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Refreshes `anime_airings` (and so `episodes_aired`) for linked anime that are
 * airing or about to, plus, unless $linkedOnly, every unfinished anime of the
 * current season.
 */
final class SyncAnimeAirings implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Below the database queue's 90s retry_after. */
    public int $timeout = 80;

    public int $maxExceptions = 3;

    public function __construct(public readonly bool $linkedOnly = false) {}

    public function uniqueId(): string
    {
        return $this->linkedOnly ? 'linked' : 'all';
    }

    /** Rate-limit releases don't count against tries; real failures do (maxExceptions). */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function handle(MetadataProvider $provider, AnimeAiringWriter $writer, AnimeSeasons $seasons): void
    {
        $anime = $this->targets($seasons)
            ->with(['externalIds' => fn ($query) => $query->where('provider', $provider->key())])
            ->get()
            ->keyBy(fn (Anime $entry) => (string) $entry->externalId($provider->key()))
            ->filter(fn (Anime $entry, string $externalId) => $externalId !== '');

        if ($anime->isEmpty()) {
            return;
        }

        try {
            $schedules = $provider->schedules(array_map('strval', $anime->keys()->all()));
        } catch (MetadataProviderException $e) {
            if (! $e->rateLimited) {
                throw $e;
            }

            logger()->warning('Anime airing sync rate limited; released', ['retry_after' => $e->retryAfter]);
            $this->release($e->retryAfter ?? 60);

            return;
        }

        foreach ($schedules as $externalId => $airings) {
            $entry = $anime->get((string) $externalId);

            if ($entry !== null) {
                $writer->replace($entry, $provider->key(), $airings);
            }
        }

        logger()->info('Anime airing sync finished', ['anime' => $anime->count(), 'schedules' => count($schedules)]);
    }

    /**
     * Linked anime that are airing, or not yet but could start before the next
     * weekly season sync notices; plus the current season's unfinished anime.
     *
     * @return Builder<Anime>
     */
    public function targets(AnimeSeasons $seasons): Builder
    {
        [$season, $year] = $seasons->current();

        return Anime::query()->where(fn (Builder $query) => $query
            ->where(fn (Builder $linked) => $linked
                ->whereHas('links')
                ->whereIn('status', ['RELEASING', 'NOT_YET_RELEASED']))
            ->when(! $this->linkedOnly, fn (Builder $any) => $any->orWhere(fn (Builder $current) => $current
                ->where('season', $season->value)
                ->where('season_year', $year)
                ->where(fn (Builder $status) => $status->whereNull('status')->orWhereNotIn('status', ['FINISHED', 'CANCELLED'])))));
    }
}
