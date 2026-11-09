<?php

declare(strict_types=1);

namespace App\Services\Nyaa;

use App\Jobs\FetchAnimeCover;
use App\Models\Anime;
use App\Services\Metadata\AnimeProviderSearch;
use App\Services\Metadata\Matching\AnimeMatcher;
use App\Services\Metadata\Matching\TitleNormalizer;
use App\Services\Metadata\MetadataProviderException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The anime half of the Nyaa import's target picker (§16): stored anime whose
 * titles hold every word of the query, best match first, each with the shows
 * it's linked to. With `provider`, one fail-fast online search too, its results
 * stored so they can be picked by id. The online search degrades quietly: over
 * its limit (10 a minute per client) or failing, the local results still come
 * back with a neutral `providerError`. Every returned anime without a stored
 * cover gets its cover queued.
 */
final class NyaaAnimeSearch
{
    public const MAX_LOCAL = 10;

    /** Local hits plus up to 10 from the provider. */
    public const MAX_WITH_PROVIDER = 20;

    public const BUSY = 'Online search is busy, try again in a minute.';

    public const UNREACHABLE = "Online search couldn't be reached, try again later.";

    public const FAILED = 'Online search failed, try again later.';

    /** Local rows considered before ranking. */
    private const LOCAL_POOL = 50;

    public function __construct(
        private readonly AnimeProviderSearch $providerSearch,
        private readonly AnimeMatcher $matcher,
        private readonly TitleNormalizer $normalizer,
    ) {}

    /**
     * @param  string  $client  who the online search's limit counts against (the IP)
     * @return array{anime: array<int, array<string, mixed>>, providerSearched: bool, providerError: string|null}
     */
    public function search(string $q, bool $provider, string $client = ''): array
    {
        $q = trim($q);
        $ids = [];

        // The same guard as the manual-link search: a query of only markers or punctuation matches everything.
        if (trim($this->normalizer->normalize($q)->base) !== '') {
            $ids = Anime::query()->select('id')->matchingTitle($q)->limit(self::LOCAL_POOL)->pluck('id')->all();
        }

        $providerSearched = false;
        $providerError = null;

        if ($provider && $q !== '') {
            if ($this->allowOnlineSearch($client)) {
                $providerSearched = true;
                ['anime' => $found, 'exception' => $exception] = $this->providerSearch->search($q, 10);
                $ids = [...$ids, ...array_map(fn (Anime $anime) => $anime->id, $found)];
                $providerError = match (true) {
                    $exception === null => null,
                    $exception instanceof MetadataProviderException && $exception->rateLimited => self::BUSY,
                    $exception instanceof MetadataProviderException => self::FAILED,
                    default => self::UNREACHABLE,
                };
            } else {
                $providerError = self::BUSY;
            }
        }

        if ($ids === []) {
            return ['anime' => [], 'providerSearched' => $providerSearched, 'providerError' => $providerError];
        }

        $anime = SeriesTargets::animeQuery()->whereKey(array_values(array_unique($ids)))->get();
        $linked = SeriesTargets::linkedShowsOf($anime->modelKeys());

        $rows = $anime
            ->map(fn (Anime $entry) => ['anime' => $entry, 'score' => $this->matcher->score($q, $entry->titles())])
            ->sort(fn (array $a, array $b) => [$b['score'], $b['anime']->season_year ?? PHP_INT_MIN, $a['anime']->id]
                <=> [$a['score'], $a['anime']->season_year ?? PHP_INT_MIN, $b['anime']->id])
            ->take($providerSearched ? self::MAX_WITH_PROVIDER : self::MAX_LOCAL)
            ->values();

        // Covers for what's shown, so the picker isn't a column of blanks. A cover that failed
        // for good, or an anime without a cover URL, isn't asked for again on every keystroke.
        foreach ($rows as $row) {
            if ($row['anime']->image === null && $row['anime']->cover_url !== null && $row['anime']->cover_error_at === null) {
                FetchAnimeCover::dispatchIfMissing($row['anime']->id);
            }
        }

        return [
            'anime' => $rows->map(fn (array $row) => [
                ...SeriesTargets::animeRef($row['anime']),
                'linkedShows' => $linked[$row['anime']->id] ?? [],
            ])->all(),
            'providerSearched' => $providerSearched,
            'providerError' => $providerError,
        ];
    }

    /** At most `provider_searches_per_minute` online searches a minute per client; counts this one. */
    private function allowOnlineSearch(string $client): bool
    {
        $key = 'nyaa-anime-search:'.$client;

        if (RateLimiter::tooManyAttempts($key, (int) config('subtracker.nyaa.provider_searches_per_minute'))) {
            return false;
        }

        RateLimiter::hit($key, 60);

        return true;
    }
}
