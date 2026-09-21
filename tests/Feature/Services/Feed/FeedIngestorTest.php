<?php

declare(strict_types=1);

use App\Enums\PremiereSource;
use App\Events\NewReleaseDetected;
use App\Events\ShowDiscovered;
use App\Models\Release;
use App\Models\Show;
use App\Services\Feed\FeedIngestor;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // Creating a show synchronously dispatches ShowDiscovered, whose listener queues
    // FetchShowImage; with the sync queue driver in tests that would run for real.
    Queue::fake();
});

function sampleFeedXml(array $items): string
{
    $itemsXml = implode('', $items);

    return <<<XML
    <rss version="2.0" xmlns:subsplease="https://subsplease.org/rss"><channel><title>SubsPlease RSS</title>{$itemsXml}</channel></rss>
    XML;
}

function sampleItem(
    string $title,
    string $guid,
    string $category,
    string $infohash = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
    string $pubDate = 'Mon, 21 Sep 2026 08:32:00 +0000',
    string $size = '1.34 GiB',
): string {
    $link = 'magnet:?xt=urn:btih:'.$infohash.'&amp;dn=test';

    return "<item><title>{$title}</title><link>{$link}</link><guid isPermaLink=\"false\">{$guid}</guid><pubDate>{$pubDate}</pubDate><category>{$category}</category><subsplease:size>{$size}</subsplease:size></item>";
}

test('ingests items from the real feed fixture and creates shows and releases', function () {
    $xml = file_get_contents(dirname(__DIR__, 3).'/Fixtures/subsplease_1080.xml');

    Event::fake();

    $result = (new FeedIngestor)->ingest($xml);

    expect($result->itemsTotal)->toBe(50)
        ->and($result->itemsNew)->toBe(50)
        ->and($result->showsNew)->toBeGreaterThan(0)
        ->and(Release::count())->toBe(50)
        ->and(Show::count())->toBe($result->showsNew);

    Event::assertDispatchedTimes(NewReleaseDetected::class, 50);
});

test('is idempotent by guid: ingesting the same feed twice creates nothing new', function () {
    $xml = sampleFeedXml([
        sampleItem('[SubsPlease] Test Show - 01 (1080p) [ABCD1234].mkv', 'GUID-1', 'Test Show - 1080'),
    ]);

    Event::fake();

    $first = (new FeedIngestor)->ingest($xml);
    $release = Release::where('guid', 'GUID-1')->first();
    $firstSeenAt = $release->first_seen_at;

    $second = (new FeedIngestor)->ingest($xml);
    $release->refresh();

    expect($first->itemsNew)->toBe(1)
        ->and($second->itemsNew)->toBe(0)
        ->and(Release::count())->toBe(1)
        ->and($release->first_seen_at->equalTo($firstSeenAt))->toBeTrue();

    Event::assertDispatchedTimes(NewReleaseDetected::class, 1);
});

test('extracts the infohash from the magnet link', function () {
    $xml = sampleFeedXml([
        sampleItem('[SubsPlease] Test Show - 01 (1080p) [ABCD1234].mkv', 'GUID-1', 'Test Show - 1080', 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef'),
    ]);

    (new FeedIngestor)->ingest($xml);

    $release = Release::where('guid', 'GUID-1')->first();

    expect($release->infohash)->toBe('deadbeefdeadbeefdeadbeefdeadbeefdeadbeef')
        ->and($release->size_label)->toBe('1.34 GiB');
});

test('stores an unparseable title with a null show_id and does not create a show', function () {
    $xml = sampleFeedXml([
        '<item><title>Some unrelated torrent.mkv</title><link>magnet:?xt=urn:btih:FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFF</link><guid isPermaLink="false">GUID-BAD</guid><pubDate>Mon, 21 Sep 2026 08:32:00 +0000</pubDate></item>',
    ]);

    $result = (new FeedIngestor)->ingest($xml);

    $release = Release::where('guid', 'GUID-BAD')->first();

    expect($result->itemsTotal)->toBe(1)
        ->and($result->showsNew)->toBe(0)
        ->and($release)->not->toBeNull()
        ->and($release->show_id)->toBeNull();
});

test('updates last_seen_at and latest_episode on the show when a new episode arrives', function () {
    $first = sampleFeedXml([
        sampleItem('[SubsPlease] Test Show - 01 (1080p) [ABCD1234].mkv', 'GUID-1', 'Test Show - 1080'),
    ]);
    $second = sampleFeedXml([
        sampleItem('[SubsPlease] Test Show - 02 (1080p) [EF012345].mkv', 'GUID-2', 'Test Show - 1080'),
    ]);

    (new FeedIngestor)->ingest($first);
    $show = Show::where('name', 'Test Show')->first();
    $firstSeenAt = $show->first_seen_at;

    (new FeedIngestor)->ingest($second);
    $show->refresh();

    expect(Show::count())->toBe(1)
        ->and($show->latest_episode)->toBe('02')
        ->and($show->first_seen_at->equalTo($firstSeenAt))->toBeTrue();
});

test('dispatches ShowDiscovered exactly once when a show is first seen', function () {
    $xml = sampleFeedXml([
        sampleItem('[SubsPlease] Test Show - 01 (1080p) [ABCD1234].mkv', 'GUID-1', 'Test Show - 1080'),
    ]);

    Event::fake();

    (new FeedIngestor)->ingest($xml);

    Event::assertDispatchedTimes(ShowDiscovered::class, 1);
});

test('does not dispatch ShowDiscovered again for an already-known show', function () {
    $first = sampleFeedXml([
        sampleItem('[SubsPlease] Test Show - 01 (1080p) [ABCD1234].mkv', 'GUID-1', 'Test Show - 1080'),
    ]);
    $second = sampleFeedXml([
        sampleItem('[SubsPlease] Test Show - 02 (1080p) [EF012345].mkv', 'GUID-2', 'Test Show - 1080'),
    ]);

    (new FeedIngestor)->ingest($first);

    Event::fake();

    (new FeedIngestor)->ingest($second);

    Event::assertNotDispatched(ShowDiscovered::class);
});

test('sets earliest_seen premiere data as soon as a new show gets its first release', function () {
    $xml = sampleFeedXml([
        sampleItem('[SubsPlease] Test Show - 05 (1080p) [ABCD1234].mkv', 'GUID-1', 'Test Show - 1080', pubDate: 'Mon, 21 Sep 2026 08:32:00 +0000'),
    ]);

    (new FeedIngestor)->ingest($xml);

    $show = Show::where('name', 'Test Show')->first();

    expect($show->premiere_source)->toBe(PremiereSource::EarliestSeen)
        ->and($show->premiered_at->toIso8601String())->toBe('2026-09-21T08:32:00+00:00')
        ->and($show->season)->not->toBeNull()
        ->and($show->season_year)->not->toBeNull();
});

test('upgrades premiere data to episode1 once an episode 01 release arrives', function () {
    $first = sampleFeedXml([
        sampleItem('[SubsPlease] Test Show - 05 (1080p) [ABCD1234].mkv', 'GUID-1', 'Test Show - 1080', pubDate: 'Mon, 21 Sep 2026 08:32:00 +0000'),
    ]);
    $second = sampleFeedXml([
        sampleItem('[SubsPlease] Test Show - 01 (1080p) [EF012345].mkv', 'GUID-2', 'Test Show - 1080', pubDate: 'Mon, 01 Jun 2026 00:00:00 +0000'),
    ]);

    (new FeedIngestor)->ingest($first);
    (new FeedIngestor)->ingest($second);

    $show = Show::where('name', 'Test Show')->first();

    expect($show->premiere_source)->toBe(PremiereSource::Episode1)
        ->and($show->premiered_at->toIso8601String())->toBe('2026-06-01T00:00:00+00:00');
});

test('never overwrites an already subsplease-sourced premiere via ingest', function () {
    $xml = sampleFeedXml([
        sampleItem('[SubsPlease] Test Show - 05 (1080p) [ABCD1234].mkv', 'GUID-1', 'Test Show - 1080'),
    ]);

    (new FeedIngestor)->ingest($xml);

    $show = Show::where('name', 'Test Show')->first();
    $show->update(['premiere_source' => PremiereSource::SubsPlease, 'premiered_at' => '2020-01-01']);

    $secondXml = sampleFeedXml([
        sampleItem('[SubsPlease] Test Show - 01 (1080p) [EF012345].mkv', 'GUID-2', 'Test Show - 1080', pubDate: 'Mon, 01 Jun 2026 00:00:00 +0000'),
    ]);
    (new FeedIngestor)->ingest($secondXml);

    $show->refresh();

    expect($show->premiere_source)->toBe(PremiereSource::SubsPlease)
        ->and($show->premiered_at->toDateString())->toBe('2020-01-01');
});

test('marks batch releases with is_batch true and a null episode', function () {
    $xml = sampleFeedXml([
        sampleItem('[SubsPlease] Test Show (01-12) (1080p) [Batch]', 'GUID-BATCH', 'Test Show - 1080'),
    ]);

    (new FeedIngestor)->ingest($xml);

    $release = Release::where('guid', 'GUID-BATCH')->first();

    expect($release->is_batch)->toBeTrue()
        ->and($release->episode)->toBeNull()
        ->and($release->batch_from)->toBe(1)
        ->and($release->batch_to)->toBe(12);
});
