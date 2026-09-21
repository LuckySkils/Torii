<?php

declare(strict_types=1);

use App\Contracts\DownloadDriver;
use App\Enums\RuleState;
use App\Jobs\SyncShowRule;
use App\Models\Show;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'subtracker.feed.url' => 'https://subsplease.org/rss/?r=1080',
        'subtracker.qbittorrent.url' => 'http://qbit.test:8080',
        'subtracker.qbittorrent.username' => 'admin',
        'subtracker.qbittorrent.password' => 'secret',
        'subtracker.qbittorrent.category' => 'Anime',
        'subtracker.qbittorrent.rule_prefix' => '[ST] ',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'http://qbit.test:8080/api/v2/auth/login' => Http::response('Ok.', 200, ['Set-Cookie' => 'SID=abc123; path=/']),
        'http://qbit.test:8080/api/v2/rss/rules' => Http::response(json_encode([]), 200),
        'http://qbit.test:8080/api/v2/rss/setRule' => Http::response('', 200),
    ]);
});

function makePendingShow(bool $tracked): Show
{
    return Show::create([
        'name' => 'Grand Blue S3',
        'slug' => 'grand-blue-s3',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'is_tracked' => $tracked,
        'rule_state' => RuleState::Pending,
    ]);
}

test('handle syncs the rule and marks the show synced on success', function () {
    $show = makePendingShow(tracked: true);

    app(SyncShowRule::class, ['show' => $show, 'track' => true])->handle(app(DownloadDriver::class));
    $show->refresh();

    expect($show->rule_state)->toBe(RuleState::Synced)
        ->and($show->rule_synced_at)->not->toBeNull()
        ->and($show->rule_error)->toBeNull();
});

test('handle marks the show disabled on a successful untrack', function () {
    $show = makePendingShow(tracked: false);

    app(SyncShowRule::class, ['show' => $show, 'track' => false])->handle(app(DownloadDriver::class));
    $show->refresh();

    expect($show->rule_state)->toBe(RuleState::Disabled);
});

test('failed marks the show as errored with the exception message', function () {
    $show = makePendingShow(tracked: true);
    $job = new SyncShowRule($show, track: true);

    $job->failed(new RuntimeException('qBittorrent is unreachable'));
    $show->refresh();

    expect($show->rule_state)->toBe(RuleState::Error)
        ->and($show->rule_error)->toBe('qBittorrent is unreachable');
});
