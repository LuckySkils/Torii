<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Anime;
use App\Models\AnimeImage;
use App\Support\DispatchSpacing;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Downloads an anime's cover (AniList's largest, ~500x715) into `anime_images`,
 * like FetchShowImage does for posters: size cap, image/* only, must decode,
 * sha256 compare before writing, so an unchanged cover is never rewritten and a
 * changed one (e.g. the old smaller size) is replaced.
 *
 * The image CDN isn't the GraphQL API, so this doesn't spend the AniList
 * throttle; every dispatch goes through the `covers` spacing channel instead.
 * A failure that won't fix itself is recorded on the anime (`cover_error`), which
 * on-demand fetching respects; a success clears it.
 */
final class FetchAnimeCover implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** extraLarge covers measured up to ~0.7 MB; 5 MB leaves room and still rejects anything absurd. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Gap between cover downloads, across every source. */
    public const SPACING_SECONDS = 2;

    /** How long a page view's request suppresses repeat requests for the same cover. */
    private const ON_DEMAND_DEDUP_SECONDS = 600;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $animeId) {}

    /**
     * Queues a fetch in the next free `covers` slot, unless one for this anime is
     * already queued. The unique lock is taken before a slot is reserved, so
     * duplicates from overlapping sources don't leave gaps in the spacing.
     *
     * @return bool whether a fetch was queued
     */
    public static function dispatchSpaced(int $animeId): bool
    {
        $job = new self($animeId);

        // What PendingDispatch does for ShouldBeUnique; the worker releases it when the job ends.
        if (! (new UniqueLock(app(Repository::class)))->acquire($job)) {
            return false;
        }

        $delay = app(DispatchSpacing::class)->nextDelay('covers', self::SPACING_SECONDS);

        if ($delay > 0) {
            $job->delay(now()->addSeconds($delay));
        }

        app(Dispatcher::class)->dispatch($job);

        return true;
    }

    public static function dispatchIfMissing(int $animeId): void
    {
        if (! AnimeImage::where('anime_id', $animeId)->exists()) {
            self::dispatchSpaced($animeId);
        }
    }

    /**
     * A page showed this anime without a cover: fetch it, unless a download has
     * failed for good before, or a view already asked in the last 10 minutes.
     */
    public static function requestOnDemand(Anime $anime): void
    {
        if ($anime->cover_url === null || $anime->cover_error_at !== null) {
            return;
        }

        if (Cache::add("anime:cover:requested:{$anime->id}", true, self::ON_DEMAND_DEDUP_SECONDS)) {
            self::dispatchSpaced($anime->id);
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
            ->timeout(20)
            ->get($anime->cover_url);

        // A 5xx is worth another try later; a 404 or a non-image body isn't.
        if ($response->serverError()) {
            $response->throw();
        }

        $body = $response->body();
        $contentType = trim(explode(';', (string) $response->header('Content-Type'))[0]);

        if (! $response->successful() || strlen($body) > self::MAX_BYTES || ! str_starts_with($contentType, 'image/')) {
            $this->recordError($anime->id, "Invalid cover response (status {$response->status()}, Content-Type: {$contentType}, size: ".strlen($body).' bytes).');

            return;
        }

        $dimensions = @getimagesizefromstring($body);

        if ($dimensions === false) {
            $this->recordError($anime->id, 'Downloaded cover could not be decoded.');

            return;
        }

        $sha256 = hash('sha256', $body);
        $existing = AnimeImage::where('anime_id', $anime->id)->first(['id', 'sha256', 'source_url']);

        if ($existing !== null && $existing->sha256 === $sha256) {
            // Same bytes; keep the row, but note the URL it now comes from.
            if ($existing->source_url !== $anime->cover_url) {
                $existing->forceFill(['source_url' => $anime->cover_url])->save();
            }
        } else {
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

        Anime::whereKey($anime->id)->whereNotNull('cover_error_at')->update(['cover_error' => null, 'cover_error_at' => null]);
    }

    public function failed(Throwable $exception): void
    {
        $this->recordError($this->animeId, $exception->getMessage());
    }

    private function recordError(int $animeId, string $error): void
    {
        logger()->warning('Anime cover fetch failed', ['anime_id' => $animeId, 'error' => $error]);

        Anime::whereKey($animeId)->update(['cover_error' => $error, 'cover_error_at' => now()]);
    }
}
