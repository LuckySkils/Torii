<?php

declare(strict_types=1);

use App\Enums\PremiereSource;
use App\Models\Release;
use App\Models\Show;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

test('recomputes premiere data for existing shows without any network calls', function () {
    Http::preventStrayRequests();

    $show = Show::create([
        'name' => 'Grand Blue S3',
        'slug' => 'grand-blue-s3',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    Release::create([
        'show_id' => $show->id,
        'guid' => 'GUID-RP-1',
        'title' => 'irrelevant',
        'episode' => '01',
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:RP1',
        'published_at' => Carbon::parse('2026-04-10'),
        'first_seen_at' => now(),
    ]);

    $this->artisan('shows:recompute-premiere')->assertSuccessful();

    $show->refresh();

    expect($show->premiere_source)->toBe(PremiereSource::Episode1)
        ->and($show->premiered_at->toDateString())->toBe('2026-04-10')
        ->and($show->season_year)->not->toBeNull();
});

test('never downgrades a show that already has a subsplease-sourced premiere', function () {
    Http::preventStrayRequests();

    $show = Show::create([
        'name' => 'Grand Blue S3',
        'slug' => 'grand-blue-s3',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'premiered_at' => Carbon::parse('2020-01-01'),
        'premiere_source' => PremiereSource::SubsPlease,
    ]);

    Release::create([
        'show_id' => $show->id,
        'guid' => 'GUID-RP-2',
        'title' => 'irrelevant',
        'episode' => '01',
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:RP2',
        'published_at' => Carbon::parse('2026-04-10'),
        'first_seen_at' => now(),
    ]);

    $this->artisan('shows:recompute-premiere')->assertSuccessful();

    $show->refresh();

    expect($show->premiere_source)->toBe(PremiereSource::SubsPlease)
        ->and($show->premiered_at->toDateString())->toBe('2020-01-01');
});
