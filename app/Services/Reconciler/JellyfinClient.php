<?php

declare(strict_types=1);

namespace App\Services\Reconciler;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * The Jellyfin REST calls the handoff verified (§17). Auth: `Authorization:
 * MediaBrowser Token="<api key>"`. The two write calls refuse to run in dry run,
 * as a backstop: the reconciler decides and records before ever calling them.
 */
final class JellyfinClient
{
    /**
     * The newest episodes in the Anime library, matched on their `Shoko File`
     * provider id in PHP: AnyProviderIdEquals ignores Shokofin's ids.
     *
     * @return array{id: string, seriesId: string|null}|null
     */
    public function findEpisodeByShokoFile(int $shokoFileId): ?array
    {
        $items = $this->get('/Items', [
            'ParentId' => (string) config('subtracker.reconciler.jellyfin_anime_library_id'),
            'Recursive' => 'true',
            'IncludeItemTypes' => 'Episode',
            'SortBy' => 'DateCreated',
            'SortOrder' => 'Descending',
            'Limit' => (int) config('subtracker.reconciler.recent_episodes_limit'),
            'Fields' => 'ProviderIds',
        ])->json('Items') ?? [];

        foreach ($items as $item) {
            if ((string) ($item['ProviderIds']['Shoko File'] ?? '') === (string) $shokoFileId) {
                return ['id' => (string) $item['Id'], 'seriesId' => isset($item['SeriesId']) ? (string) $item['SeriesId'] : null];
            }
        }

        return null;
    }

    /** How many episodes the series lists; 0 is failure B's signature. */
    public function seriesEpisodeCount(string $seriesId): int
    {
        $body = $this->get('/Shows/'.rawurlencode($seriesId).'/Episodes')->json();

        return max((int) ($body['TotalRecordCount'] ?? 0), count($body['Items'] ?? []));
    }

    /** Fix A: refresh the Anime library only. Completes with a LibraryChanged. */
    public function refreshAnimeLibrary(): void
    {
        $this->write('/Items/'.rawurlencode((string) config('subtracker.reconciler.jellyfin_anime_library_id')).'/Refresh', [
            'Recursive' => 'true',
            'MetadataRefreshMode' => 'Default',
            'ImageRefreshMode' => 'Default',
            'ReplaceAllMetadata' => 'false',
        ]);
    }

    /** Fix B: refresh one series, replacing all metadata. Returns at once; no completion event. */
    public function refreshSeries(string $seriesId): void
    {
        $this->write('/Items/'.rawurlencode($seriesId).'/Refresh', [
            'Recursive' => 'true',
            'MetadataRefreshMode' => 'FullRefresh',
            'ImageRefreshMode' => 'Default',
            'ReplaceAllMetadata' => 'true',
        ]);
    }

    /**
     * @param  array<string, string|int>  $query
     */
    private function get(string $path, array $query = []): Response
    {
        try {
            $response = $this->request()->get($this->url($path), $query);
        } catch (Throwable $e) {
            throw new RuntimeException("Jellyfin could not be reached: {$e->getMessage()}", previous: $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException("Jellyfin answered GET {$path} with HTTP {$response->status()}.");
        }

        return $response;
    }

    /**
     * @param  array<string, string>  $query
     */
    private function write(string $path, array $query): void
    {
        if (config('subtracker.reconciler.dry_run')) {
            throw new LogicException("Dry run: POST {$path} must not be sent.");
        }

        try {
            $response = $this->request()->post($this->url($path).'?'.http_build_query($query));
        } catch (Throwable $e) {
            throw new RuntimeException("Jellyfin could not be reached: {$e->getMessage()}", previous: $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException("Jellyfin answered POST {$path} with HTTP {$response->status()}.");
        }
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'MediaBrowser Token="'.config('subtracker.reconciler.jellyfin_api_key').'"',
            'User-Agent' => 'Torii-Subtracker/1.0',
        ])->acceptJson()->timeout(15);
    }

    private function url(string $path): string
    {
        return config('subtracker.reconciler.jellyfin_url').$path;
    }
}
