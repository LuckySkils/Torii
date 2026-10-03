<?php

declare(strict_types=1);

namespace App\Services\Metadata;

/**
 * One tag in a provider's vocabulary (not its use on an anime; that's
 * ProviderTag): its category, e.g. AniList's "Theme-Fantasy", and description.
 */
final readonly class ProviderTagDefinition
{
    public function __construct(
        public string $name,
        public ?string $category = null,
        public ?string $description = null,
        public bool $isAdult = false,
    ) {}
}
