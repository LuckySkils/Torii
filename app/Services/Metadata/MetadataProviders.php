<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use App\Contracts\MetadataProvider;
use InvalidArgumentException;

/**
 * Every registered provider, keyed by key(). The active one (config
 * `subtracker.metadata.provider`) is what MetadataProvider resolves to.
 */
final class MetadataProviders
{
    /** @var array<string, MetadataProvider> */
    private array $providers = [];

    /**
     * @param  iterable<int, MetadataProvider>  $providers
     */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->key()] = $provider;
        }
    }

    public function get(string $key): MetadataProvider
    {
        return $this->providers[$key] ?? throw new InvalidArgumentException("Unknown metadata provider [{$key}].");
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->providers);
    }
}
