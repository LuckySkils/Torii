<?php

declare(strict_types=1);

use App\Events\ShowDiscovered;
use App\Jobs\FetchShowImage;
use App\Services\Feed\FeedIngestor;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Carbon::setTestNow('2026-09-29 10:00:00');
});

function discover(string $name): void
{
    ShowDiscovered::dispatch(metadataShow($name));
}

/**
 * Each queued poster fetch's delay in seconds from now, in dispatch order.
 *
 * @return array<int, int>
 */
function posterDelays(): array
{
    return Queue::pushed(FetchShowImage::class)
        ->map(fn (FetchShowImage $job) => $job->delay === null ? 0 : (int) now()->diffInSeconds($job->delay))
        ->values()
        ->all();
}

test('a lone newly discovered show gets its poster fetched right away', function () {
    discover('Lonely Show');

    expect(posterDelays())->toBe([0]);
    Queue::assertPushed(FetchShowImage::class, fn (FetchShowImage $job) => $job->delay === null);
});

test('shows discovered together are spaced 3 seconds apart, the first immediate', function () {
    foreach (['A', 'B', 'C', 'D'] as $name) {
        discover($name);
    }

    expect(posterDelays())->toBe([0, 3, 6, 9]);
});

test('the spacing picks up from the next free slot while a burst is still running', function () {
    discover('A');
    discover('B');

    // 1s later: B's slot is 3s after A, so C goes 3s after that (5s from now).
    $this->travel(1)->seconds();
    discover('C');

    expect((int) now()->diffInSeconds(Queue::pushed(FetchShowImage::class)->last()->delay))->toBe(5);
});

test('once the burst is over, the next discovery is immediate again', function () {
    discover('A');
    discover('B');

    $this->travel(10)->seconds();
    discover('C');

    expect(Queue::pushed(FetchShowImage::class)->last()->delay)->toBeNull();
});

test('a first poll discovering 50 shows does not burst: 3 seconds apart, about 2.5 minutes in all', function () {
    $items = '';

    for ($i = 1; $i <= 50; $i++) {
        $hash = str_pad((string) $i, 40, 'A', STR_PAD_LEFT);
        $items .= "<item><title>[SubsPlease] New Show {$i} - 01 (1080p) [ABCD{$i}].mkv</title>"
            ."<link>magnet:?xt=urn:btih:{$hash}&amp;dn=test</link><guid isPermaLink=\"false\">GUID-{$i}</guid>"
            ."<pubDate>Tue, 29 Sep 2026 02:00:00 +0000</pubDate><category>New Show {$i} - 1080</category></item>";
    }

    app(FeedIngestor::class)->ingest("<rss version=\"2.0\"><channel><title>SubsPlease RSS</title>{$items}</channel></rss>");

    $delays = posterDelays();

    expect($delays)->toHaveCount(50)
        ->and($delays[0])->toBe(0)
        ->and($delays)->toBe(range(0, 49 * 3, 3));
});
