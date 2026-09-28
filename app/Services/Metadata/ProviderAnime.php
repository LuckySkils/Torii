<?php

declare(strict_types=1);

namespace App\Services\Metadata;

/**
 * One anime as a provider describes it, normalized, plus the untouched payload.
 * Dates are 'Y-m-d' strings, set only when the provider gives a full date.
 */
final readonly class ProviderAnime
{
    /**
     * @param  array<string, string>  $otherExternalIds  e.g. ['mal' => '61987']
     * @param  array<int, string>  $synonyms
     * @param  array<int, string>  $genres
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $provider,
        public string $externalId,
        public array $otherExternalIds,
        public ?string $titleRomaji,
        public ?string $titleEnglish,
        public ?string $titleNative,
        public array $synonyms,
        public ?string $description,
        public array $genres,
        public ?string $format,
        public ?string $status,
        public ?int $episodesTotal,
        public ?string $season,
        public ?int $seasonYear,
        public ?string $startDate,
        public ?string $endDate,
        public ?int $durationMinutes,
        public ?string $coverUrl,
        public ?string $bannerUrl,
        public ?string $siteUrl,
        public bool $isAdult,
        public ?ProviderAiring $nextAiring,
        public array $raw,
    ) {}
}
