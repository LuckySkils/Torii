<?php

declare(strict_types=1);

use App\Models\Show;
use Illuminate\Support\Facades\Http;

test('reports created, updated, disabled, and orphaned rules', function () {
    config([
        'subtracker.feed.url' => 'https://subsplease.org/rss/?r=1080',
        'subtracker.qbittorrent.url' => 'http://qbit.test:8080',
        'subtracker.qbittorrent.username' => 'admin',
        'subtracker.qbittorrent.password' => 'secret',
        'subtracker.qbittorrent.category' => 'Anime',
        'subtracker.qbittorrent.rule_prefix' => '[ST] ',
    ]);

    Show::create([
        'name' => 'Needs Creation',
        'slug' => 'needs-creation',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'is_tracked' => true,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'http://qbit.test:8080/api/v2/auth/login' => Http::response('Ok.', 200, ['Set-Cookie' => 'SID=abc123; path=/']),
        'http://qbit.test:8080/api/v2/rss/rules' => Http::response(json_encode([
            '[ST] Orphaned Show' => ['enabled' => true],
        ]), 200),
        'http://qbit.test:8080/api/v2/rss/setRule' => Http::response('', 200),
    ]);

    $this->artisan('qbit:reconcile')
        ->expectsOutputToContain('Created: 1')
        ->expectsOutputToContain('[ST] Needs Creation')
        ->expectsOutputToContain('Orphaned (left alone): 1')
        ->expectsOutputToContain('[ST] Orphaned Show')
        ->assertExitCode(0);
});
