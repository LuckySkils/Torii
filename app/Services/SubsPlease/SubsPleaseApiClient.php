<?php

declare(strict_types=1);

namespace App\Services\SubsPlease;

use Illuminate\Support\Facades\Http;

final class SubsPleaseApiClient
{
    private const BASE_URL = 'https://subsplease.org';

    /**
     * @return array<int, SearchResultItem>
     */
    public function search(string $query): array
    {
        $response = Http::withHeaders([
            'User-Agent' => 'Torii-Subtracker/1.0',
            'Accept' => 'application/json',
        ])
            ->timeout(15)
            ->retry(2, 100, throw: false)
            ->get(self::BASE_URL.'/api/', [
                'f' => 'search',
                'tz' => 'UTC',
                's' => $query,
            ]);

        if (! $response->successful()) {
            throw new SubsPleaseApiException(
                "SubsPlease API returned status {$response->status()} for query [{$query}]."
            );
        }

        // SubsPlease's API mislabels genuinely-JSON responses as `Content-Type: text/html`,
        // so the header can't be trusted; only the decoded body tells JSON apart from an
        // actual HTML body (e.g. a Cloudflare challenge page).
        $data = json_decode($response->body(), true);

        if (! is_array($data)) {
            throw new SubsPleaseApiException(
                "SubsPlease API returned a non-JSON response for query [{$query}]."
            );
        }

        // An empty result comes back as either `[]` or `{}`; json_decode(..., true) makes both an empty array.
        if ($data === []) {
            return [];
        }

        $items = [];

        foreach ($data as $entry) {
            if (! is_array($entry) || ! isset($entry['show'])) {
                continue;
            }

            $items[] = new SearchResultItem(
                show: (string) $entry['show'],
                episode: isset($entry['episode']) ? (string) $entry['episode'] : null,
                releaseDate: isset($entry['release_date']) ? (string) $entry['release_date'] : null,
                time: isset($entry['time']) ? (string) $entry['time'] : null,
                page: isset($entry['page']) ? (string) $entry['page'] : null,
                imageUrl: $this->resolveImageUrl(isset($entry['image_url']) ? (string) $entry['image_url'] : null),
                downloads: $this->parseDownloads($entry['downloads'] ?? null),
            );
        }

        return $items;
    }

    private function resolveImageUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return self::BASE_URL.'/'.ltrim($url, '/');
    }

    /**
     * @return array<int, array{res: string, magnet: string}>
     */
    private function parseDownloads(mixed $downloads): array
    {
        if (! is_array($downloads)) {
            return [];
        }

        $parsed = [];

        foreach ($downloads as $download) {
            if (! is_array($download) || ! isset($download['res'], $download['magnet'])) {
                continue;
            }

            $parsed[] = [
                'res' => (string) $download['res'],
                'magnet' => (string) $download['magnet'],
            ];
        }

        return $parsed;
    }
}
