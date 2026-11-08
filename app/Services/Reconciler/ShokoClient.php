<?php

declare(strict_types=1);

namespace App\Services\Reconciler;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Shoko's REST API, the one read the reconciler needs (§17): which series a
 * file is cross-referenced to. Auth: the `apikey` header.
 *
 * `GET /api/v3/File/{id}` was verified to answer; its cross-reference shape
 * isn't captured yet, so the reader accepts v3's `SeriesIDs[].SeriesID.ID` and
 * the event-style `CrossReferences[].SeriesID`. RECONCILER_DEBUG logs the raw
 * response so the shape can be confirmed.
 */
final class ShokoClient
{
    /**
     * @return array<int, int> Shoko series ids the file is linked to (empty: none yet)
     */
    public function fileSeriesIds(int $fileId): array
    {
        try {
            $response = Http::withHeaders([
                'apikey' => (string) config('subtracker.reconciler.shoko_api_key'),
                'User-Agent' => 'Torii-Subtracker/1.0',
            ])->acceptJson()->timeout(15)->get(config('subtracker.reconciler.shoko_url')."/api/v3/File/{$fileId}", [
                // Newer servers take include=XRefs, older ones includeXRefs=true; each ignores the other.
                'include' => 'XRefs',
                'includeXRefs' => 'true',
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException("Shoko could not be reached: {$e->getMessage()}", previous: $e);
        }

        if (config('subtracker.reconciler.debug')) {
            logger()->info('Reconciler debug: Shoko file lookup', ['fileId' => $fileId, 'status' => $response->status(), 'body' => mb_substr($response->body(), 0, 2000)]);
        }

        if (! $response->successful()) {
            throw new RuntimeException("Shoko answered GET /api/v3/File/{$fileId} with HTTP {$response->status()}.");
        }

        return self::seriesIds($response->json());
    }

    /**
     * @return array<int, int>
     */
    public static function seriesIds(mixed $file): array
    {
        if (! is_array($file)) {
            return [];
        }

        $ids = [];

        foreach ((array) ($file['SeriesIDs'] ?? $file['CrossReferences'] ?? $file['CrossRefs'] ?? []) as $xref) {
            $series = is_array($xref) ? ($xref['SeriesID'] ?? null) : null;
            $series = is_array($series) ? ($series['ID'] ?? null) : $series;

            if (is_numeric($series)) {
                $ids[] = (int) $series;
            }
        }

        return array_values(array_unique($ids));
    }
}
