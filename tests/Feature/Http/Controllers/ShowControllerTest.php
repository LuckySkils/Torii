<?php

declare(strict_types=1);

use App\Models\Release;
use App\Models\Show;

function makeIndexableShow(string $name, bool $tracked, string $lastSeen): Show
{
    return Show::create([
        'name' => $name,
        'slug' => str($name)->slug(),
        'first_seen_at' => now(),
        'last_seen_at' => $lastSeen,
        'is_tracked' => $tracked,
    ]);
}

test('index lists shows with their latest release and respects filters and sort', function () {
    $tracked = makeIndexableShow('Grand Blue S3', true, '2026-09-20 00:00:00');
    $untracked = makeIndexableShow('One Piece', false, '2026-09-21 00:00:00');

    Release::create([
        'show_id' => $tracked->id,
        'guid' => 'GUID-1',
        'title' => '[SubsPlease] Grand Blue S3 - 11 (1080p) [AAAA1111].mkv',
        'episode' => '11',
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:AAAA1111',
        'published_at' => '2026-09-19 00:00:00',
        'first_seen_at' => '2026-09-19 00:05:00',
    ]);
    Release::create([
        'show_id' => $tracked->id,
        'guid' => 'GUID-2',
        'title' => '[SubsPlease] Grand Blue S3 - 12v2 (1080p) [BBBB2222].mkv',
        'episode' => '12',
        'version' => 2,
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:BBBB2222',
        'published_at' => '2026-09-20 00:00:00',
        'first_seen_at' => '2026-09-20 00:05:00',
    ]);

    $response = $this->get('/shows?tracked=yes');

    $response->assertOk();
    $response->assertInertia(function ($page) use ($tracked) {
        $page->component('Shows/Index')
            ->has('shows.data', 1)
            ->where('shows.data.0.id', $tracked->id)
            ->where('shows.data.0.firstSeenAt', $tracked->first_seen_at->toIso8601String())
            ->where('shows.data.0.latestRelease.title', '[SubsPlease] Grand Blue S3 - 12v2 (1080p) [BBBB2222].mkv')
            ->where('filters.tracked', 'yes');
    });

    $this->get('/shows?q=One')->assertInertia(fn ($page) => $page->has('shows.data', 1)
        ->where('shows.data.0.name', 'One Piece'));

    $this->get('/shows?sort=last_seen')->assertInertia(fn ($page) => $page->where('shows.data.0.name', 'One Piece'));

    expect($untracked)->not->toBeNull();
});

test('search is case-insensitive and treats %, _ and \\ as literal characters', function () {
    makeIndexableShow('LIAR GAME', false, '2026-09-20 00:00:00');
    makeIndexableShow('100% OJ', false, '2026-09-20 00:00:00');
    makeIndexableShow('One Piece', false, '2026-09-20 00:00:00');

    $this->get('/shows?q=liar')->assertInertia(fn ($page) => $page->has('shows.data', 1)
        ->where('shows.data.0.name', 'LIAR GAME'));

    $this->get('/shows?q=Liar')->assertInertia(fn ($page) => $page->has('shows.data', 1)
        ->where('shows.data.0.name', 'LIAR GAME'));

    $this->get('/shows?q=100%25')->assertInertia(fn ($page) => $page->has('shows.data', 1)
        ->where('shows.data.0.name', '100% OJ'));

    // A bare "%" must not act as a wildcard matching every show — only the one that actually contains it.
    $this->get('/shows?q=%25')->assertInertia(fn ($page) => $page->has('shows.data', 1)
        ->where('shows.data.0.name', '100% OJ'));

    $this->get('/shows?q=nonexistent')->assertInertia(fn ($page) => $page->has('shows.data', 0));
});

test('show renders the show and all of its releases newest first', function () {
    $show = makeIndexableShow('Grand Blue S3', false, '2026-09-20 00:00:00');

    Release::create([
        'show_id' => $show->id,
        'guid' => 'GUID-1',
        'title' => 'older batch',
        'is_batch' => true,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:AAAA',
        'published_at' => '2026-09-19 00:00:00',
        'first_seen_at' => '2026-09-19 00:05:00',
    ]);
    Release::create([
        'show_id' => $show->id,
        'guid' => 'GUID-2',
        'title' => 'newer v2',
        'version' => 2,
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:BBBB',
        'published_at' => '2026-09-20 00:00:00',
        'first_seen_at' => '2026-09-20 00:05:00',
    ]);

    $response = $this->get("/shows/{$show->id}");

    $response->assertOk();
    $response->assertInertia(function ($page) use ($show) {
        $page->component('Shows/Show')
            ->where('show.id', $show->id)
            ->where('show.name', 'Grand Blue S3')
            ->where('show.firstSeenAt', $show->first_seen_at->toIso8601String())
            ->has('releases', 2)
            ->where('releases.0.title', 'newer v2')
            ->where('releases.0.version', 2)
            ->where('releases.0.isBatch', false)
            ->where('releases.1.title', 'older batch')
            ->where('releases.1.version', null)
            ->where('releases.1.isBatch', true)
            ->missing('stats');
    });
});
