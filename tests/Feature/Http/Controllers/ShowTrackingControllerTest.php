<?php

declare(strict_types=1);

use App\Enums\RuleState;
use App\Jobs\SyncShowRule;
use App\Models\Show;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'subtracker.feed.url' => 'https://subsplease.org/rss/?r=1080',
        'subtracker.qbittorrent.url' => 'http://qbit.test:8080',
        'subtracker.qbittorrent.username' => 'admin',
        'subtracker.qbittorrent.password' => 'secret',
        'subtracker.qbittorrent.category' => 'Anime',
        'subtracker.qbittorrent.rule_prefix' => '[ST] ',
    ]);
});

function makeTrackableShow(string $name = 'Grand Blue S3'): Show
{
    return Show::create([
        'name' => $name,
        'slug' => str($name)->slug(),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'is_tracked' => false,
        'rule_state' => RuleState::None,
    ]);
}

test('track flips a single show and dispatches the sync job', function () {
    Queue::fake();
    $show = makeTrackableShow();

    $response = $this->patch("/shows/{$show->id}/track", ['tracked' => true]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Now tracking "Grand Blue S3".');
    $show->refresh();
    expect($show->is_tracked)->toBeTrue()
        ->and($show->rule_state)->toBe(RuleState::Pending);

    Queue::assertPushed(SyncShowRule::class, fn ($job) => $job->show->is($show) && $job->track === true);
});

test('track-bulk flips every given show', function () {
    Queue::fake();
    $a = makeTrackableShow('Grand Blue S3');
    $b = makeTrackableShow('One Piece');

    $response = $this->post('/shows/track-bulk', ['ids' => [$a->id, $b->id], 'tracked' => true]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Tracking 2 shows.');
    expect($a->refresh()->is_tracked)->toBeTrue()
        ->and($b->refresh()->is_tracked)->toBeTrue();

    Queue::assertPushed(SyncShowRule::class, 2);
});

test('delete-rule removes the qbit rule and resets rule_state to none', function () {
    Http::preventStrayRequests();
    Http::fake([
        'http://qbit.test:8080/api/v2/auth/login' => Http::response('Ok.', 200, ['Set-Cookie' => 'SID=abc123; path=/']),
        'http://qbit.test:8080/api/v2/rss/removeRule' => Http::response('', 200),
    ]);

    $show = makeTrackableShow();
    $show->update(['rule_state' => RuleState::Synced, 'rule_synced_at' => now()]);

    $response = $this->delete("/shows/{$show->id}/rule");

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Deleted the qBit rule for "Grand Blue S3".');
    $show->refresh();
    expect($show->rule_state)->toBe(RuleState::None)
        ->and($show->rule_synced_at)->toBeNull();

    Http::assertSent(fn ($r) => str_contains($r->url(), 'removeRule') && $r['ruleName'] === '[ST] Grand Blue S3');
});

test('delete-rule flashes an error and leaves the show untouched when qbit is unreachable', function () {
    Http::preventStrayRequests();
    Http::fake(function () {
        throw new ConnectionException('Connection refused');
    });

    $show = makeTrackableShow();
    $show->update(['rule_state' => RuleState::Synced, 'rule_synced_at' => now()]);

    $response = $this->delete("/shows/{$show->id}/rule");

    $response->assertRedirect();
    $response->assertSessionHas('error');
    $show->refresh();
    expect($show->rule_state)->toBe(RuleState::Synced);
});

test('matches returns the raw matchingArticles payload as json', function () {
    Http::preventStrayRequests();
    Http::fake([
        'http://qbit.test:8080/api/v2/auth/login' => Http::response('Ok.', 200, ['Set-Cookie' => 'SID=abc123; path=/']),
        'http://qbit.test:8080/api/v2/rss/matchingArticles*' => Http::response(json_encode([
            'SubsPlease 1080p' => ['[SubsPlease] Grand Blue S3 - 12 (1080p) [ABCD1234].mkv'],
        ]), 200),
    ]);

    $show = makeTrackableShow();

    $response = $this->get("/shows/{$show->id}/matches");

    $response->assertOk()
        ->assertJson([
            'SubsPlease 1080p' => ['[SubsPlease] Grand Blue S3 - 12 (1080p) [ABCD1234].mkv'],
        ]);
});
