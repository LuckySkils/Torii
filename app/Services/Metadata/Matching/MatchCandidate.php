<?php

declare(strict_types=1);

namespace App\Services\Metadata\Matching;

use App\Enums\AnimeSeason;

final readonly class MatchCandidate
{
    /**
     * @param  array<int, NormalizedTitle>  $titles
     * @param  array<int, NormalizedTitle>  $prefixes  each title's part before its first colon, with the full title's season
     */
    public function __construct(
        public int $animeId,
        public string $label,
        public ?AnimeSeason $season,
        public ?int $seasonYear,
        public array $titles,
        public array $prefixes = [],
        public ?int $episodesTotal = null,
    ) {}
}
