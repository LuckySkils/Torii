<?php

declare(strict_types=1);

namespace App\Services\Metadata\Matching;

final readonly class NormalizedTitle
{
    public function __construct(
        public string $plain,
        public string $base,
        public ?int $season,
    ) {}

    /** Romanization spacing varies ("shitemo" / "shite mo"), so equality ignores spaces. */
    public function samePlain(self $other): bool
    {
        return str_replace(' ', '', $this->plain) === str_replace(' ', '', $other->plain);
    }

    public function sameBase(self $other): bool
    {
        return str_replace(' ', '', $this->base) === str_replace(' ', '', $other->base);
    }

    /** No marker means the first season. */
    public function seasonNumber(): int
    {
        return $this->season ?? 1;
    }
}
