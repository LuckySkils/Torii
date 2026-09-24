<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ImageStatus;
use App\Models\Release;
use App\Models\Show;
use App\Models\ShowImage;
use App\Services\Premiere\PremiereCalculator;
use App\Services\SubsPlease\SearchResultItem;
use App\Services\SubsPlease\ShowImageMatcher;
use App\Services\SubsPlease\SubsPleaseApiClient;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Throwable;

final class FetchShowImage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_BYTES = 5 * 1024 * 1024;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $showId,
        public readonly bool $force = false,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->showId;
    }

    public function handle(SubsPleaseApiClient $client, ShowImageMatcher $matcher, PremiereCalculator $premiereCalculator): void
    {
        $show = Show::find($this->showId);

        if ($show === null) {
            return;
        }

        $show->update(['image_status' => ImageStatus::Pending]);

        $results = $client->search($show->name);
        $candidates = $matcher->candidates($results, $show->name);

        $premiereCalculator->apply($show, $premiereCalculator->fromSubsPlease($candidates));
        $this->logPublishedAtDrift($show, $candidates);

        $match = $matcher->firstWithImage($candidates);

        if ($match === null) {
            // A miss keeps any image already stored; only the check timestamp moves.
            $show->update([
                'image_status' => ImageStatus::Missing,
                'image_checked_at' => now(),
                'image_error' => null,
            ]);

            return;
        }

        $response = Http::timeout(15)->get((string) $match->imageUrl);

        $body = $response->body();
        $contentType = trim(explode(';', (string) $response->header('Content-Type'))[0]);

        if (! $response->successful() || strlen($body) > self::MAX_BYTES || ! str_starts_with($contentType, 'image/')) {
            $show->update([
                'image_status' => ImageStatus::Error,
                'image_checked_at' => now(),
                'image_error' => "Invalid image response from SubsPlease (Content-Type: {$contentType}, size: ".strlen($body).' bytes).',
            ]);

            return;
        }

        $dimensions = @getimagesizefromstring($body);

        if ($dimensions === false) {
            $show->update([
                'image_status' => ImageStatus::Error,
                'image_checked_at' => now(),
                'image_error' => 'Downloaded image could not be decoded.',
            ]);

            return;
        }

        $sha256 = hash('sha256', $body);
        $existing = ShowImage::where('show_id', $show->id)->first(['id', 'sha256']);

        if ($existing !== null && $existing->sha256 === $sha256) {
            $show->update([
                'image_status' => ImageStatus::Found,
                'image_checked_at' => now(),
                'image_error' => null,
            ]);

            return;
        }

        ShowImage::updateOrCreate(
            ['show_id' => $show->id],
            [
                'source_url' => $match->imageUrl,
                'mime' => $contentType,
                'data' => base64_encode($body),
                'size' => strlen($body),
                'width' => $dimensions[0],
                'height' => $dimensions[1],
                'sha256' => $sha256,
                'fetched_at' => now(),
            ],
        );

        $show->update([
            'image_status' => ImageStatus::Found,
            'image_checked_at' => now(),
            'image_error' => null,
        ]);
    }

    /**
     * Free drift check for FEED_PUBDATE_OFFSET_MINUTES: the search results already
     * carry the API's (correct) release_date, so compare it with our stored
     * published_at for any release we also have. Never makes a request of its own.
     *
     * @param  array<int, SearchResultItem>  $candidates
     */
    private function logPublishedAtDrift(Show $show, array $candidates): void
    {
        $releases = $show->releases()->get(['id', 'episode', 'version', 'is_batch', 'batch_from', 'batch_to', 'published_at']);
        $diffMinutes = [];

        foreach ($candidates as $item) {
            $episode = (string) $item->episode;
            $release = $releases->first(fn (Release $release) => $this->isSameRelease($release, $episode));

            if ($release === null || $item->releaseDate === null) {
                continue;
            }

            try {
                $apiTime = Carbon::parse($item->releaseDate)->utc();
            } catch (Throwable) {
                continue;
            }

            $diffMinutes[$episode] = (int) round($release->published_at->diffInSeconds($apiTime, false) / 60);
        }

        if ($diffMinutes !== []) {
            logger()->info('SubsPlease API release_date minus stored published_at (minutes)', [
                'show' => $show->name,
                'diff_minutes' => $diffMinutes,
            ]);
        }
    }

    private function isSameRelease(Release $release, string $apiEpisode): bool
    {
        if (preg_match('/^(\d+)-(\d+)$/', $apiEpisode, $range) === 1) {
            return $release->is_batch
                && $release->batch_from === (int) $range[1]
                && $release->batch_to === (int) $range[2];
        }

        if ($release->is_batch || $release->episode === null) {
            return false;
        }

        return $release->episode.($release->version !== null ? 'v'.$release->version : '') === $apiEpisode;
    }

    public function failed(Throwable $exception): void
    {
        Show::whereKey($this->showId)->update([
            'image_status' => ImageStatus::Error,
            'image_checked_at' => now(),
            'image_error' => $exception->getMessage(),
        ]);
    }
}
