<?php

declare(strict_types=1);

namespace App\Services\Metadata\AniList;

use App\Services\Metadata\ProviderAiring;
use App\Services\Metadata\ProviderAnime;
use Carbon\CarbonImmutable;

/**
 * Turns one AniList `Media` object into a ProviderAnime. Pure, no I/O. Built from
 * the real responses in tests/Fixtures/anilist/.
 */
final class AniListMediaParser
{
    public const PROVIDER = 'anilist';

    /**
     * @param  array<string, mixed>  $media
     */
    public function parse(array $media): ProviderAnime
    {
        $title = is_array($media['title'] ?? null) ? $media['title'] : [];
        $cover = is_array($media['coverImage'] ?? null) ? $media['coverImage'] : [];

        return new ProviderAnime(
            provider: self::PROVIDER,
            externalId: (string) $media['id'],
            otherExternalIds: isset($media['idMal']) && $media['idMal'] !== null ? ['mal' => (string) $media['idMal']] : [],
            titleRomaji: $this->string($title['romaji'] ?? null),
            titleEnglish: $this->string($title['english'] ?? null),
            titleNative: $this->string($title['native'] ?? null),
            synonyms: $this->strings($media['synonyms'] ?? null),
            description: $this->string($media['description'] ?? null),
            genres: $this->strings($media['genres'] ?? null),
            format: $this->string($media['format'] ?? null),
            status: $this->string($media['status'] ?? null),
            episodesTotal: $this->int($media['episodes'] ?? null),
            season: $this->string($media['season'] ?? null),
            seasonYear: $this->int($media['seasonYear'] ?? null),
            startDate: $this->fuzzyDate($media['startDate'] ?? null),
            endDate: $this->fuzzyDate($media['endDate'] ?? null),
            durationMinutes: $this->int($media['duration'] ?? null),
            // The largest (~500x715, ~240 KB): one stored size, which the frontend
            // downscales. Every variant stays in the raw payload.
            coverUrl: $this->string($cover['extraLarge'] ?? null) ?? $this->string($cover['large'] ?? null) ?? $this->string($cover['medium'] ?? null),
            bannerUrl: $this->string($media['bannerImage'] ?? null),
            siteUrl: $this->string($media['siteUrl'] ?? null),
            isAdult: (bool) ($media['isAdult'] ?? false),
            nextAiring: $this->airing($media['nextAiringEpisode'] ?? null),
            raw: $media,
        );
    }

    /**
     * One `{ episode, airingAt }` node (nextAiringEpisode or an airingSchedule node).
     */
    public function airing(mixed $node): ?ProviderAiring
    {
        if (! is_array($node) || ! is_numeric($node['episode'] ?? null) || ! is_numeric($node['airingAt'] ?? null)) {
            return null;
        }

        return new ProviderAiring(
            episode: (int) $node['episode'],
            airsAt: CarbonImmutable::createFromTimestampUTC((int) $node['airingAt']),
        );
    }

    /**
     * AniList's FuzzyDate: any of year/month/day may be null. Only a full, valid
     * date becomes 'Y-m-d'; the fuzzy parts stay in the raw payload.
     */
    private function fuzzyDate(mixed $date): ?string
    {
        if (! is_array($date)) {
            return null;
        }

        $year = $this->int($date['year'] ?? null);
        $month = $this->int($date['month'] ?? null);
        $day = $this->int($date['day'] ?? null);

        if ($year === null || $month === null || $day === null || ! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function string(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return trim($value) === '' ? null : $value;
    }

    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return array<int, string>
     */
    private function strings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, fn ($value) => is_string($value) && trim($value) !== ''));
    }
}
