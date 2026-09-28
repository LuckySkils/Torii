<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Services\Metadata\ProviderAiring;
use App\Services\Metadata\ProviderAnime;

interface MetadataProvider
{
    /** Stable provider key, e.g. 'anilist'; used for anime_external_ids.provider. */
    public function key(): string;

    /** @return array<int, string> e.g. ['seasons', 'schedule', 'images', 'descriptions'] */
    public function capabilities(): array;

    /**
     * Every anime of a season. $season is WINTER|SPRING|SUMMER|FALL.
     *
     * @return iterable<int, ProviderAnime>
     */
    public function season(string $season, int $year): iterable;

    public function byExternalId(string $id): ?ProviderAnime;

    /**
     * Batch form of byExternalId(), so refreshing many entries doesn't cost one
     * request each. Ids the provider doesn't know are simply absent.
     *
     * @param  array<int, string>  $ids
     * @return array<int, ProviderAnime>
     */
    public function byExternalIds(array $ids): array;

    /** @return array<int, ProviderAnime> */
    public function search(string $query, int $limit = 10): array;

    /**
     * The full known airing schedule, aired and upcoming, ordered by episode.
     *
     * @return array<int, ProviderAiring>
     */
    public function schedule(string $externalId): array;

    /**
     * Batch form of schedule(), keyed by external id. Ids the provider doesn't
     * know are absent; known ids with no schedule map to [].
     *
     * @param  array<int, string>  $externalIds
     * @return array<string, array<int, ProviderAiring>>
     */
    public function schedules(array $externalIds): array;
}
