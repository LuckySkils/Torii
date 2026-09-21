<?php

declare(strict_types=1);

namespace App\Services\SubsPlease;

final readonly class SearchResultItem
{
    /**
     * @param  array<int, array{res: string, magnet: string}>  $downloads
     */
    public function __construct(
        public string $show,
        public ?string $episode,
        public ?string $releaseDate,
        public ?string $time,
        public ?string $page,
        public ?string $imageUrl,
        public array $downloads,
    ) {}
}
