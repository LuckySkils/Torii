<?php

declare(strict_types=1);

namespace App\Services\Metadata;

/**
 * Every genre and tag a provider knows, whether or not any anime in the catalog
 * uses it yet.
 */
final readonly class ProviderVocabulary
{
    /**
     * @param  array<int, string>  $genres
     * @param  array<int, ProviderTagDefinition>  $tags  unique by name
     */
    public function __construct(
        public array $genres,
        public array $tags,
    ) {}
}
