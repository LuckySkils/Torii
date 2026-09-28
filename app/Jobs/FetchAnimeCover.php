<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Anime;
use App\Models\AnimeImage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Downloads an anime's cover into `anime_images`, like FetchShowImage does for
 * posters: 5 MB cap, image/* only, must decode, sha256 compare before writing.
 * The image CDN isn't the GraphQL API, so this doesn't spend the AniList
 * throttle; bulk dispatches are spaced out instead.
 */
final class FetchAnimeCover implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_BYTES = 5 * 1024 * 1024;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $animeId) {}

    public static function dispatchIfMissing(int $animeId): void
    {
        if (! AnimeImage::where('anime_id', $animeId)->exists()) {
            self::dispatch($animeId);
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->animeId;
    }

    public function handle(): void
    {
        $anime = Anime::find($this->animeId, ['id', 'cover_url']);

        if ($anime === null || $anime->cover_url === null) {
            return;
        }

        $response = Http::withHeaders(['User-Agent' => 'Torii-Subtracker/1.0'])
            ->timeout(15)
            ->get($anime->cover_url);

        // A 5xx is worth another try later; a 404 or a non-image body isn't.
        if ($response->serverError()) {
            $response->throw();
        }

        $body = $response->body();
        $contentType = trim(explode(';', (string) $response->header('Content-Type'))[0]);

        if (! $response->successful() || strlen($body) > self::MAX_BYTES || ! str_starts_with($contentType, 'image/')) {
            logger()->warning('Invalid anime cover response', [
                'anime_id' => $anime->id,
                'status' => $response->status(),
                'content_type' => $contentType,
                'size' => strlen($body),
            ]);

            return;
        }

        $dimensions = @getimagesizefromstring($body);

        if ($dimensions === false) {
            logger()->warning('Anime cover could not be decoded', ['anime_id' => $anime->id]);

            return;
        }

        $sha256 = hash('sha256', $body);
        $existing = AnimeImage::where('anime_id', $anime->id)->first(['id', 'sha256']);

        if ($existing !== null && $existing->sha256 === $sha256) {
            return;
        }

        AnimeImage::updateOrCreate(
            ['anime_id' => $anime->id],
            [
                'source_url' => $anime->cover_url,
                'mime' => $contentType,
                'data' => base64_encode($body),
                'size' => strlen($body),
                'width' => $dimensions[0],
                'height' => $dimensions[1],
                'sha256' => $sha256,
                'fetched_at' => now(),
            ],
        );
    }

    public function failed(Throwable $exception): void
    {
        logger()->warning('Anime cover fetch failed', ['anime_id' => $this->animeId, 'error' => $exception->getMessage()]);
    }
}
