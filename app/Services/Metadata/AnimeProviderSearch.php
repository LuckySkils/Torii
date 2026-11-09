<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use App\Contracts\MetadataProvider;
use App\Models\Anime;
use Illuminate\Http\Client\ConnectionException;

/**
 * One fail-fast search at the metadata provider from a web request, its results
 * stored (AnimeSyncer) so they can be linked by id. A provider failure isn't
 * thrown: it comes back as the error text, so the caller can still answer with
 * local results. Used by the manual-link search and the Nyaa import's picker.
 */
final class AnimeProviderSearch
{
    public function __construct(
        private readonly MetadataProvider $provider,
        private readonly AnimeSyncer $syncer,
    ) {}

    /**
     * The stored entries, in the provider's order; on a failure, its message and the exception too.
     *
     * @return array{anime: array<int, Anime>, error: string|null, exception: MetadataProviderException|ConnectionException|null}
     */
    public function search(string $query, int $limit = 10): array
    {
        $anime = [];

        try {
            foreach ($this->provider->search($query, $limit) as $entry) {
                $anime[] = $this->syncer->upsert($entry)->anime;
            }
        } catch (MetadataProviderException|ConnectionException $e) {
            logger()->warning('Metadata provider search failed', ['query' => $query, 'error' => $e->getMessage()]);

            return ['anime' => $anime, 'error' => $e->getMessage(), 'exception' => $e];
        }

        return ['anime' => $anime, 'error' => null, 'exception' => null];
    }
}
