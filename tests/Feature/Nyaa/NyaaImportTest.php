<?php

declare(strict_types=1);

use App\Enums\DispatchStatus;
use App\Enums\ReleaseSource;
use App\Events\NewReleaseDetected;
use App\Events\ShowDiscovered;
use App\Jobs\QueueReleases;
use App\Jobs\SyncShowRule;
use App\Models\Release;
use App\Models\Show;
use App\Services\Feed\PublishedAtFixer;
use App\Services\Nyaa\MagnetLink;
use App\Services\Nyaa\NyaaClient;
use App\Services\Nyaa\NyaaException;
use App\Services\Nyaa\NyaaFeedParser;
use App\Services\Nyaa\NyaaUrl;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const NYAA_LINK = 'https://nyaa.si/?page=rss&q=Hanaori-san+wa+Tensei+shitemo+Kenka+ga+Shitai+%5Bsubsplease%5D+1080&c=0_0&f=0';
const HANAORI = 'Hanaori-san wa Tensei shitemo Kenka ga Shitai';

/**
 * A Nyaa RSS page in the real feed's format (tests/Fixtures/nyaa/search_rss.xml),
 * for the cases that feed doesn't contain: v2s, remakes, batches, other groups.
 * The .torrent URL in <link>, the view page in <guid>, and the nyaa: fields.
 *
 * @param  array<int, array<string, mixed>>  $items  title, id, hash, and optionally size, seeders, trusted, remake, pubDate
 */
function nyaaRss(array $items, string $title = 'Nyaa - "Hanaori-san [subsplease] 1080" - Torrent File RSS'): string
{
    $xml = '<?xml version="1.0" encoding="utf-8"?>'."\n"
        .'<rss xmlns:atom="http://www.w3.org/2005/Atom" xmlns:nyaa="https://nyaa.si/xmlns/nyaa" version="2.0"><channel>'
        .'<title>'.htmlspecialchars($title, ENT_XML1).'</title><description>RSS Feed</description><link>https://nyaa.si/</link>';

    foreach ($items as $item) {
        $xml .= '<item>'
            .'<title>'.htmlspecialchars($item['title'], ENT_XML1).'</title>'
            .'<link>https://nyaa.si/download/'.$item['id'].'.torrent</link>'
            .'<guid isPermaLink="true">https://nyaa.si/view/'.$item['id'].'</guid>'
            .'<pubDate>'.($item['pubDate'] ?? 'Sun, 20 Sep 2026 15:31:09 -0000').'</pubDate>'
            .'<nyaa:seeders>'.($item['seeders'] ?? 120).'</nyaa:seeders><nyaa:leechers>3</nyaa:leechers><nyaa:downloads>900</nyaa:downloads>'
            .'<nyaa:infoHash>'.$item['hash'].'</nyaa:infoHash>'
            .'<nyaa:categoryId>1_2</nyaa:categoryId><nyaa:category>Anime - English-translated</nyaa:category>'
            .'<nyaa:size>'.($item['size'] ?? '1.4 GiB').'</nyaa:size><nyaa:comments>0</nyaa:comments>'
            .'<nyaa:trusted>'.(($item['trusted'] ?? true) ? 'Yes' : 'No').'</nyaa:trusted>'
            .'<nyaa:remake>'.(($item['remake'] ?? false) ? 'Yes' : 'No').'</nyaa:remake>'
            .'<description><![CDATA[<a href="https://nyaa.si/view/'.$item['id'].'">#'.$item['id'].'</a>]]></description>'
            .'</item>';
    }

    return $xml.'</channel></rss>';
}

function hanaori(string $episode, string $crc = '0E3DE4FA'): string
{
    return '[SubsPlease] '.HANAORI." - {$episode} (1080p) [{$crc}].mkv";
}

function hashFor(string $seed): string
{
    return sha1($seed);
}

/**
 * @param  array<int, array<string, mixed>>  $items
 * @param  array<int, string>  $inQbit  infohashes qBittorrent reports
 */
function fakeNyaa(array $items, array $inQbit = []): void
{
    Http::fake([
        'nyaa.si/*' => Http::response(nyaaRss($items), 200, ['Content-Type' => 'application/xml']),
        'http://qbit.test:8080/api/v2/torrents/info*' => Http::response(array_map(fn (string $hash) => ['hash' => $hash], $inQbit)),
    ]);
}

/**
 * @return array<string, mixed>
 */
function nyaaPreview(): array
{
    return test()->postJson('/import/nyaa/preview', ['url' => NYAA_LINK])->assertOk()->json();
}

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'subtracker.qbittorrent.url' => 'http://qbit.test:8080',
        'subtracker.qbittorrent.username' => '',
        'subtracker.qbittorrent.password' => '',
    ]);
});

// ── Parsing and magnets ──────────────────────────────────────────────────────

test('the parser reads every field, normalizing the infohash', function () {
    $items = (new NyaaFeedParser)->parse(nyaaRss([[
        'title' => hanaori('12'), 'id' => 2166527, 'hash' => '71DCE47AA56507200E52626667F2A1795D11711C',
        'size' => '1.4 GiB', 'seeders' => 321, 'trusted' => true, 'remake' => false, 'pubDate' => 'Sun, 20 Sep 2026 15:31:09 -0000',
    ]]));

    $item = $items['items'][0];

    expect($items['title'])->toBe('Nyaa - "Hanaori-san [subsplease] 1080" - Torrent File RSS')
        ->and($item->title)->toBe(hanaori('12'))
        ->and($item->torrentUrl)->toBe('https://nyaa.si/download/2166527.torrent')
        ->and($item->viewUrl)->toBe('https://nyaa.si/view/2166527')
        ->and($item->nyaaId)->toBe('2166527')
        ->and($item->publishedAt->toIso8601String())->toBe('2026-09-20T15:31:09+00:00')
        ->and($item->infohash)->toBe('71dce47aa56507200e52626667f2a1795d11711c')
        ->and([$item->size, $item->seeders, $item->leechers, $item->downloads, $item->trusted, $item->remake])->toBe(['1.4 GiB', 321, 3, 900, true, false]);
});

test('a body that is not RSS is a clear error', function () {
    expect(fn () => (new NyaaFeedParser)->parse('<html><body>Cloudflare</body></html>'))
        ->toThrow(NyaaException::class, 'Nyaa did not return an RSS feed.');
});

test('the magnet built for episode 12 matches the real Nyaa magnet exactly', function () {
    expect(MagnetLink::build('71dce47aa56507200e52626667f2a1795d11711c', hanaori('12')))->toBe(
        'magnet:?xt=urn:btih:71dce47aa56507200e52626667f2a1795d11711c&dn=%5BSubsPlease%5D%20Hanaori-san%20wa%20Tensei%20shitemo%20Kenka%20ga%20Shitai%20-%2012%20%281080p%29%20%5B0E3DE4FA%5D.mkv&tr=http%3A%2F%2Fnyaa.tracker.wf%3A7777%2Fannounce&tr=udp%3A%2F%2Fopen.stealth.si%3A80%2Fannounce&tr=udp%3A%2F%2Ftracker.opentrackr.org%3A1337%2Fannounce&tr=udp%3A%2F%2Fexodus.desync.com%3A6969%2Fannounce&tr=udp%3A%2F%2Ftracker.torrent.eu.org%3A451%2Fannounce'
    );
});

test('the trackers come from config, in order', function () {
    config(['subtracker.nyaa.trackers' => ['udp://one.example:1/announce', 'http://two.example/announce']]);

    expect(MagnetLink::build('aa', 'x y'))->toBe('magnet:?xt=urn:btih:aa&dn=x%20y&tr=udp%3A%2F%2Fone.example%3A1%2Fannounce&tr=http%3A%2F%2Ftwo.example%2Fannounce');
});

// ── Fetching safely ──────────────────────────────────────────────────────────

test('only https://nyaa.si/ RSS links are accepted', function (string $url) {
    expect(fn () => NyaaUrl::check($url))->toThrow(NyaaException::class);
})->with([
    'http' => 'http://nyaa.si/?page=rss&q=x',
    'other host' => 'https://example.com/?page=rss&q=x',
    'look-alike host' => 'https://nyaa.si.example.com/?page=rss',
    'subdomain' => 'https://sukebei.nyaa.si/?page=rss',
    'internal address' => 'https://192.168.1.1/?page=rss',
    'not the RSS page' => 'https://nyaa.si/?q=x',
    'another page' => 'https://nyaa.si/view/2166527?page=rss',
    'credentials' => 'https://user:pass@nyaa.si/?page=rss',
    'other port' => 'https://nyaa.si:8443/?page=rss',
    'not a URL' => 'nyaa',
]);

test('a Nyaa search RSS link is accepted', function () {
    expect(NyaaUrl::check(' '.NYAA_LINK.' '))->toBe(NYAA_LINK);
});

test('redirects are followed only within nyaa.si RSS links', function () {
    Http::fake([
        'https://nyaa.si/?page=rss&q=old' => Http::response('', 301, ['Location' => '/?page=rss&q=new']),
        'https://nyaa.si/?page=rss&q=new' => Http::response(nyaaRss([]), 200),
        'https://nyaa.si/?page=rss&q=away' => Http::response('', 302, ['Location' => 'http://192.168.1.1/admin']),
    ]);

    expect(app(NyaaClient::class)->fetch('https://nyaa.si/?page=rss&q=old'))->toContain('<rss')
        ->and(fn () => app(NyaaClient::class)->fetch('https://nyaa.si/?page=rss&q=away'))
        ->toThrow(NyaaException::class, 'Nyaa redirected somewhere other than a nyaa.si RSS feed, so the link was not followed.');

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '192.168.1.1'));
    Http::assertSent(fn (Request $request) => $request->hasHeader('User-Agent', 'Torii-Subtracker/1.0'));
});

test('an oversized body and a failed response are clear errors', function () {
    config(['subtracker.nyaa.max_bytes' => 1024 * 1024]);
    Http::fake([
        'https://nyaa.si/?page=rss&q=big' => Http::response(str_repeat('x', 1024 * 1024 + 1)),
        'https://nyaa.si/?page=rss&q=down' => Http::response('', 503),
    ]);

    expect(fn () => app(NyaaClient::class)->fetch('https://nyaa.si/?page=rss&q=big'))->toThrow(NyaaException::class, 'Nyaa sent more than 1 MB')
        ->and(fn () => app(NyaaClient::class)->fetch('https://nyaa.si/?page=rss&q=down'))->toThrow(NyaaException::class, 'Nyaa answered with HTTP 503.');
});

test('the preview endpoint rejects a bad link with a message, and is rate limited', function () {
    config(['subtracker.nyaa.previews_per_minute' => 2]);
    fakeNyaa([]);

    $this->postJson('/import/nyaa/preview', ['url' => 'https://example.com/?page=rss'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Only https://nyaa.si/ links can be imported.');
    $this->postJson('/import/nyaa/preview', ['url' => NYAA_LINK])->assertOk();
    $this->postJson('/import/nyaa/preview', ['url' => NYAA_LINK])->assertTooManyRequests();
});

// ── Preview: states and default selection ────────────────────────────────────

test('preview: each state with its default selection and reason', function () {
    Release::create([
        'guid' => 'https://subsplease.org/known', 'title' => hanaori('04'), 'episode' => '04', 'is_batch' => false, 'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:'.hashFor('04'), 'infohash' => hashFor('04'), 'published_at' => now(), 'first_seen_at' => now(),
        'dispatch_status' => DispatchStatus::Sent,
    ]);
    fakeNyaa([
        ['title' => hanaori('01'), 'id' => 1, 'hash' => hashFor('01')],
        ['title' => hanaori('02'), 'id' => 2, 'hash' => hashFor('02')],
        ['title' => hanaori('02v2', 'ABCDEF12'), 'id' => 22, 'hash' => hashFor('02v2')],
        ['title' => hanaori('03'), 'id' => 3, 'hash' => hashFor('03')],
        ['title' => hanaori('04'), 'id' => 4, 'hash' => hashFor('04')],
        ['title' => hanaori('05'), 'id' => 5, 'hash' => hashFor('05'), 'remake' => true],
        ['title' => '[Erai-raws] Hanaori-san - 06 [1080p][Multiple Subtitle].mkv', 'id' => 6, 'hash' => hashFor('06')],
    ], inQbit: [strtoupper(hashFor('03'))]);

    $preview = nyaaPreview();
    $byKey = collect($preview['items'])->keyBy('key');
    $summary = fn (string $key) => [$byKey[$key]['state'], $byKey[$key]['selected'], $byKey[$key]['reason']];

    expect($summary('1'))->toBe(['new', true, null])
        ->and($summary('2'))->toBe(['superseded', false, 'v2 of this episode is also in the list.'])
        ->and($summary('22'))->toBe(['new', true, null])
        ->and($summary('3'))->toBe(['in_qbit', false, 'Already in qBittorrent.'])
        ->and($summary('4'))->toBe(['known_release', false, 'Torii already sent this release to qBittorrent.'])
        ->and($summary('5'))->toBe(['new', false, 'Marked as a remake on Nyaa.'])
        ->and($summary('6')[0])->toBe('unparsed')
        ->and($summary('6')[1])->toBeFalse()
        ->and($preview['show'])->toBe(['existingShowId' => null, 'name' => HANAORI, 'willCreate' => true])
        ->and($preview['truncated'])->toBeFalse()
        ->and($preview['feedTitle'])->toContain('Torrent File RSS')
        ->and($preview['previewId'])->toBeString()
        ->and($preview['warnings'])->toBe([]);

    expect(array_keys($byKey['1']))->toBe([
        'key', 'title', 'showName', 'episode', 'version', 'isBatch', 'batchFrom', 'batchTo', 'resolution',
        'infohash', 'magnet', 'torrentUrl', 'viewUrl', 'publishedAt', 'size', 'seeders', 'trusted', 'remake', 'state', 'selected', 'reason',
    ])->and($byKey['1']['magnet'])->toBe(MagnetLink::build(hashFor('01'), hanaori('01')))
        ->and($byKey['22']['version'])->toBe(2);

    // Ordered by episode, unparsed last.
    expect(array_column($preview['items'], 'key'))->toBe(['1', '2', '22', '3', '4', '5', '6']);
});

test('preview: a batch is preferred over the episodes it covers', function () {
    fakeNyaa([
        ['title' => '[SubsPlease] '.HANAORI.' (01-12) (1080p) [Batch]', 'id' => 100, 'hash' => hashFor('batch')],
        ['title' => hanaori('03'), 'id' => 3, 'hash' => hashFor('03')],
        ['title' => hanaori('12'), 'id' => 12, 'hash' => hashFor('12')],
        ['title' => hanaori('13'), 'id' => 13, 'hash' => hashFor('13')],
    ]);

    $byKey = collect(nyaaPreview()['items'])->keyBy('key');

    expect([$byKey['100']['selected'], $byKey['100']['isBatch'], $byKey['100']['batchFrom'], $byKey['100']['batchTo']])->toBe([true, true, 1, 12])
        ->and([$byKey['3']['selected'], $byKey['3']['reason']])->toBe([false, 'Covered by the batch of episodes 1-12 in this list.'])
        ->and($byKey['12']['selected'])->toBeFalse()
        ->and($byKey['13'])->toMatchArray(['selected' => true, 'reason' => null]);
});

test('preview: a full page is flagged as possibly truncated', function () {
    fakeNyaa(array_map(fn (int $i) => ['title' => hanaori(sprintf('%02d', $i)), 'id' => $i, 'hash' => hashFor("e{$i}")], range(1, 75)));

    expect(nyaaPreview()['truncated'])->toBeTrue();
});

test('preview: works without qBittorrent, saying so', function () {
    Http::fake([
        'nyaa.si/*' => Http::response(nyaaRss([['title' => hanaori('01'), 'id' => 1, 'hash' => hashFor('01')]])),
        'http://qbit.test:8080/*' => Http::response('', 500),
    ]);

    $preview = nyaaPreview();

    expect($preview['items'][0]['state'])->toBe('new')
        ->and($preview['warnings'])->toBe(['qBittorrent could not be reached, so items it already has are not marked; it still skips them when adding.']);
});

test('preview: an existing show is named, several shows are listed in a warning', function () {
    $show = metadataShow(HANAORI);
    Http::fake([
        'nyaa.si/*' => Http::sequence()
            ->push(nyaaRss([['title' => hanaori('01'), 'id' => 1, 'hash' => hashFor('01')]]))
            ->push(nyaaRss([
                ['title' => hanaori('01'), 'id' => 1, 'hash' => hashFor('01')],
                ['title' => '[SubsPlease] Other Show - 01 (1080p) [AAAAAAAA].mkv', 'id' => 2, 'hash' => hashFor('other')],
            ])),
        'http://qbit.test:8080/api/v2/torrents/info*' => Http::response([]),
    ]);

    expect(nyaaPreview()['show'])->toBe(['existingShowId' => $show->id, 'name' => HANAORI, 'willCreate' => false]);

    $preview = nyaaPreview();

    expect($preview['show'])->toBeNull()
        ->and($preview['warnings'][0])->toStartWith('This feed holds releases of 2 shows');
});

// ── Confirm ──────────────────────────────────────────────────────────────────

test('confirm creates the show untracked, stores nyaa releases with magnets and queues them; no rule, no tracking', function () {
    Queue::fake();
    Event::fake([ShowDiscovered::class, NewReleaseDetected::class]);
    fakeNyaa([
        ['title' => hanaori('01'), 'id' => 1, 'hash' => hashFor('01'), 'pubDate' => 'Sun, 05 Jul 2026 15:31:09 -0000'],
        ['title' => hanaori('02'), 'id' => 2, 'hash' => hashFor('02')],
    ]);
    $preview = nyaaPreview();

    $result = $this->postJson('/import/nyaa/confirm', ['previewId' => $preview['previewId'], 'keys' => ['1', '2']])->assertOk()->json();

    $show = Show::where('name', HANAORI)->firstOrFail();
    $releases = Release::where('show_id', $show->id)->orderBy('episode')->get();

    expect($result)->toMatchArray(['queued' => 2, 'skipped' => [], 'errors' => []])
        ->and($result['shows'])->toBe([['id' => $show->id, 'name' => HANAORI, 'created' => true]])
        ->and($show->is_tracked)->toBeFalse()
        ->and($show->rule_state->value)->toBe('none')
        ->and($show->latest_episode)->toBe('02')
        ->and($releases)->toHaveCount(2)
        ->and($releases[0])->source->toBe(ReleaseSource::Nyaa)
        ->and($releases[0]->link)->toBe(MagnetLink::build(hashFor('01'), hanaori('01')))
        ->and($releases[0]->torrent_url)->toBe('https://nyaa.si/download/1.torrent')
        ->and($releases[0]->guid)->toBe('https://nyaa.si/view/1')
        ->and($releases[0]->infohash)->toBe(hashFor('01'))
        ->and($releases[0]->size_label)->toBe('1.4 GiB')
        ->and($releases[0]->published_at->toIso8601String())->toBe('2026-07-05T15:31:09+00:00');

    Queue::assertPushed(QueueReleases::class, fn (QueueReleases $job) => $job->releaseIds === $releases->modelKeys());
    Queue::assertNotPushed(SyncShowRule::class);
    Event::assertDispatched(ShowDiscovered::class, fn (ShowDiscovered $event) => $event->show->is($show));
    Event::assertNotDispatched(NewReleaseDetected::class);
});

test('confirm attaches to the existing show with that exact name, untouched', function () {
    Queue::fake();
    Event::fake([ShowDiscovered::class]);
    $show = metadataShow(HANAORI, ['latest_episode' => '09']);
    fakeNyaa([['title' => hanaori('01'), 'id' => 1, 'hash' => hashFor('01')]]);

    $result = $this->postJson('/import/nyaa/confirm', ['previewId' => nyaaPreview()['previewId'], 'keys' => ['1']])->assertOk()->json();

    expect($result['shows'])->toBe([['id' => $show->id, 'name' => HANAORI, 'created' => false]])
        ->and(Show::count())->toBe(1)
        ->and($show->fresh()->latest_episode)->toBe('09')
        ->and($show->fresh()->is_tracked)->toBeFalse()
        ->and(Release::where('show_id', $show->id)->count())->toBe(1);
    Event::assertNotDispatched(ShowDiscovered::class);
});

test('confirm reuses Torii\'s own release of the same torrent and skips one sent meanwhile', function () {
    Queue::fake();
    $show = metadataShow(HANAORI);
    $feedRelease = Release::create([
        'show_id' => $show->id, 'guid' => 'https://subsplease.org/rss/01', 'title' => hanaori('01'), 'episode' => '01', 'is_batch' => false,
        'resolution' => '1080p', 'link' => 'magnet:?xt=urn:btih:SUBSPLEASE', 'infohash' => hashFor('01'), 'published_at' => now(), 'first_seen_at' => now(),
    ]);
    fakeNyaa([
        ['title' => hanaori('01'), 'id' => 1, 'hash' => hashFor('01')],
        ['title' => hanaori('02'), 'id' => 2, 'hash' => hashFor('02')],
    ]);
    $preview = nyaaPreview();
    // Sent between preview and confirm.
    Release::create([
        'show_id' => $show->id, 'guid' => 'https://nyaa.si/view/2', 'title' => hanaori('02'), 'episode' => '02', 'is_batch' => false,
        'resolution' => '1080p', 'link' => 'magnet:?x', 'infohash' => hashFor('02'), 'published_at' => now(), 'first_seen_at' => now(),
        'dispatch_status' => DispatchStatus::Sent,
    ]);

    $result = $this->postJson('/import/nyaa/confirm', ['previewId' => $preview['previewId'], 'keys' => ['1', '2']])->assertOk()->json();

    expect($result['queued'])->toBe(1)
        ->and($result['skipped'])->toBe([['key' => '2', 'title' => hanaori('02'), 'reason' => 'Torii already sent this release to qBittorrent.']])
        ->and(Release::count())->toBe(2);
    Queue::assertPushed(QueueReleases::class, fn (QueueReleases $job) => $job->releaseIds === [$feedRelease->id]);
});

test('confirm accepts only previewed keys, and an expired or used preview is rejected', function () {
    Queue::fake();
    fakeNyaa([['title' => hanaori('01'), 'id' => 1, 'hash' => hashFor('01')]]);
    $preview = nyaaPreview();

    $this->postJson('/import/nyaa/confirm', ['previewId' => $preview['previewId'], 'keys' => ['1', '999']])
        ->assertStatus(422)
        ->assertJsonPath('errors.keys.0', 'Not in this preview: 999.');
    $this->postJson('/import/nyaa/confirm', ['previewId' => 'no-such-preview', 'keys' => ['1']])
        ->assertStatus(422)
        ->assertJsonPath('errors.previewId.0', 'This preview has expired or was already used (previews last 15 minutes). Preview the link again.');

    $this->postJson('/import/nyaa/confirm', ['previewId' => $preview['previewId'], 'keys' => ['1']])->assertOk();
    $this->postJson('/import/nyaa/confirm', ['previewId' => $preview['previewId'], 'keys' => ['1']])->assertStatus(422);

    $this->travel(16)->minutes();
    $expired = nyaaPreview();
    $this->travel(16)->minutes();
    $this->postJson('/import/nyaa/confirm', ['previewId' => $expired['previewId'], 'keys' => ['1']])->assertStatus(422);

    expect(Release::count())->toBe(1);
});

test('an unparsed item can still be added deliberately, without a show', function () {
    Queue::fake();
    fakeNyaa([['title' => '[Erai-raws] Something - 06 [1080p].mkv', 'id' => 6, 'hash' => hashFor('06')]]);
    $preview = nyaaPreview();

    expect($preview['show'])->toBeNull();

    $this->postJson('/import/nyaa/confirm', ['previewId' => $preview['previewId'], 'keys' => ['6']])->assertOk()->assertJsonPath('queued', 1);

    expect(Release::first())->show_id->toBeNull()->source->toBe(ReleaseSource::Nyaa)
        ->and(Show::count())->toBe(0);
});

test('releases:fix-published leaves Nyaa releases alone: their pubDate is already UTC', function () {
    $release = Release::create([
        'guid' => 'https://nyaa.si/view/1', 'title' => hanaori('01'), 'episode' => '01', 'is_batch' => false, 'resolution' => '1080p',
        'link' => 'magnet:?x', 'infohash' => hashFor('01'), 'published_at' => '2026-07-05 15:31:09', 'published_at_raw' => 'Sun, 05 Jul 2026 15:31:09 -0000',
        'first_seen_at' => now(), 'source' => ReleaseSource::Nyaa,
    ]);

    app(PublishedAtFixer::class)->run();

    expect($release->fresh()->published_at->toDateTimeString())->toBe('2026-07-05 15:31:09');
});

// ── The real feed (tests/Fixtures/nyaa/search_rss.xml, the brief's example link) ─

const BRIEF_MAGNET = 'magnet:?xt=urn:btih:71dce47aa56507200e52626667f2a1795d11711c&dn=%5BSubsPlease%5D%20Hanaori-san%20wa%20Tensei%20shitemo%20Kenka%20ga%20Shitai%20-%2012%20%281080p%29%20%5B0E3DE4FA%5D.mkv&tr=http%3A%2F%2Fnyaa.tracker.wf%3A7777%2Fannounce&tr=udp%3A%2F%2Fopen.stealth.si%3A80%2Fannounce&tr=udp%3A%2F%2Ftracker.opentrackr.org%3A1337%2Fannounce&tr=udp%3A%2F%2Fexodus.desync.com%3A6969%2Fannounce&tr=udp%3A%2F%2Ftracker.torrent.eu.org%3A451%2Fannounce';

function nyaaFixture(): string
{
    return file_get_contents(base_path('tests/Fixtures/nyaa/search_rss.xml'));
}

test('the real feed parses into its 12 episodes with every field', function () {
    $feed = (new NyaaFeedParser)->parse(nyaaFixture());
    $first = $feed['items'][0];

    expect($feed['title'])->toBe('Nyaa - "Hanaori-san wa Tensei shitemo Kenka ga Shitai [subsplease] 1080" - Torrent File RSS')
        ->and($feed['items'])->toHaveCount(12)
        ->and($first->title)->toBe(hanaori('12'))
        ->and($first->torrentUrl)->toBe('https://nyaa.si/download/2166527.torrent')
        ->and($first->viewUrl)->toBe('https://nyaa.si/view/2166527')
        ->and($first->publishedAt->toIso8601String())->toBe('2026-09-26T17:32:06+00:00')
        ->and($first->infohash)->toBe('71dce47aa56507200e52626667f2a1795d11711c')
        ->and([$first->size, $first->trusted, $first->remake, $first->category])->toBe(['1.4 GiB', true, false, 'Anime - English-translated'])
        ->and($first->seeders)->toBeGreaterThan(0)
        ->and(collect($feed['items'])->every(fn ($item) => preg_match('/^[0-9a-f]{40}$/', (string) $item->infohash) === 1))->toBeTrue();
});

test('the real feed previews as 12 new, selected episodes of one new show; episode 12\'s magnet is the real one', function () {
    Http::fake([
        'nyaa.si/*' => Http::response(nyaaFixture(), 200, ['Content-Type' => 'application/xml']),
        'http://qbit.test:8080/api/v2/torrents/info*' => Http::response([]),
    ]);

    $preview = nyaaPreview();
    $items = collect($preview['items']);

    expect($items->pluck('episode')->all())->toBe(['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'])
        ->and($items->every(fn (array $item) => $item['state'] === 'new' && $item['selected'] && $item['showName'] === HANAORI && $item['resolution'] === '1080p'))->toBeTrue()
        ->and($items->firstWhere('episode', '12')['magnet'])->toBe(BRIEF_MAGNET)
        ->and($items->firstWhere('episode', '12')['key'])->toBe('2166527')
        ->and($preview['show'])->toBe(['existingShowId' => null, 'name' => HANAORI, 'willCreate' => true])
        ->and($preview['truncated'])->toBeFalse()
        ->and($preview['warnings'])->toBe([]);
});
