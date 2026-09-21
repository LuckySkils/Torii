<?php

declare(strict_types=1);

use App\Enums\PremiereSource;
use App\Enums\Season;
use App\Models\Release;
use App\Models\Show;
use App\Services\Premiere\PremiereCalculator;
use App\Services\Premiere\PremiereResult;
use App\Services\SubsPlease\ShowImageMatcher;
use App\Services\SubsPlease\SubsPleaseApiClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

function premiereShow(string $name = 'Grand Blue S3'): Show
{
    return Show::create([
        'name' => $name,
        'slug' => str($name)->slug(),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
}

function premiereRelease(Show $show, string $seed, array $overrides = []): Release
{
    return Release::create(array_merge([
        'show_id' => $show->id,
        'guid' => 'GUID-PREM-'.$seed,
        'title' => 'irrelevant',
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:'.$seed,
        'published_at' => now(),
        'first_seen_at' => now(),
    ], $overrides));
}

test('season boundaries follow Tokyo time, including a UTC moment that has already rolled into the next day there', function () {
    $calculator = new PremiereCalculator;

    // 2026-03-31 23:30 UTC = 2026-04-01 08:30 JST: already April, so Spring, not Winter.
    [$season, $year] = $calculator->seasonFor(Carbon::parse('2026-03-31 23:30:00', 'UTC'));
    expect($season)->toBe(Season::Spring)->and($year)->toBe(2026);

    [$season] = $calculator->seasonFor(Carbon::parse('2026-01-01 00:00:00', 'UTC'));
    expect($season)->toBe(Season::Winter);

    [$season] = $calculator->seasonFor(Carbon::parse('2026-06-30 14:59:00', 'UTC')); // 23:59 JST, still June
    expect($season)->toBe(Season::Spring);

    [$season] = $calculator->seasonFor(Carbon::parse('2026-09-30 14:59:00', 'UTC')); // 23:59 JST, still September
    expect($season)->toBe(Season::Summer);

    [$season] = $calculator->seasonFor(Carbon::parse('2026-12-31 14:59:00', 'UTC')); // 23:59 JST, still December
    expect($season)->toBe(Season::Autumn);
});

test('source priority: subsplease beats episode1, and episode1 beats earliest_seen', function () {
    $calculator = new PremiereCalculator;
    $show = premiereShow();

    premiereRelease($show, 'earliest', ['published_at' => Carbon::parse('2026-01-10')]);
    $calculator->apply($show, $calculator->fromEpisode1OrEarliest($show));
    expect($show->premiere_source)->toBe(PremiereSource::EarliestSeen);

    premiereRelease($show, 'ep1', ['episode' => '01', 'published_at' => Carbon::parse('2026-01-05')]);
    $calculator->apply($show, $calculator->fromEpisode1OrEarliest($show));
    expect($show->premiere_source)->toBe(PremiereSource::Episode1)
        ->and($show->premiered_at->toDateString())->toBe('2026-01-05');

    $calculator->apply($show, new PremiereResult(Carbon::parse('2026-02-01'), PremiereSource::SubsPlease));
    expect($show->premiere_source)->toBe(PremiereSource::SubsPlease)
        ->and($show->premiered_at->toDateString())->toBe('2026-02-01');

    // A weaker source arriving afterwards must never downgrade an already-better one.
    $calculator->apply($show, new PremiereResult(Carbon::parse('2020-01-01'), PremiereSource::EarliestSeen));
    expect($show->premiere_source)->toBe(PremiereSource::SubsPlease)
        ->and($show->premiered_at->toDateString())->toBe('2026-02-01');
});

test('recomputing with the same data twice is idempotent', function () {
    $calculator = new PremiereCalculator;
    $show = premiereShow();

    premiereRelease($show, 'ep1', ['episode' => '01', 'published_at' => Carbon::parse('2026-03-01')]);

    $calculator->apply($show, $calculator->fromEpisode1OrEarliest($show));
    $firstPremieredAt = $show->premiered_at;
    $firstSeason = $show->season;
    $firstYear = $show->season_year;

    $calculator->apply($show, $calculator->fromEpisode1OrEarliest($show));

    expect($show->premiered_at->equalTo($firstPremieredAt))->toBeTrue()
        ->and($show->season)->toBe($firstSeason)
        ->and($show->season_year)->toBe($firstYear)
        ->and($show->premiere_source)->toBe(PremiereSource::Episode1);
});

test('an episode1 release found later upgrades a show that only had earliest_seen', function () {
    $calculator = new PremiereCalculator;
    $show = premiereShow();

    premiereRelease($show, 'later-ep', ['episode' => '05', 'published_at' => Carbon::parse('2026-04-01')]);
    $calculator->apply($show, $calculator->fromEpisode1OrEarliest($show));
    expect($show->premiere_source)->toBe(PremiereSource::EarliestSeen);

    premiereRelease($show, 'ep1-arrives-late', ['episode' => '1', 'published_at' => Carbon::parse('2026-01-01')]);
    $calculator->apply($show, $calculator->fromEpisode1OrEarliest($show));

    expect($show->premiere_source)->toBe(PremiereSource::Episode1)
        ->and($show->premiered_at->toDateString())->toBe('2026-01-01');
});

test('parses the subsplease premiere date from a real search fixture using its episode 01 entry', function () {
    Http::preventStrayRequests();
    Http::fake([
        'subsplease.org/api/*' => Http::response(
            file_get_contents(dirname(__DIR__, 3).'/Fixtures/subsplease_search_world-is-dancing.json'),
            200,
            ['Content-Type' => 'application/json'],
        ),
    ]);

    $results = (new SubsPleaseApiClient)->search('World Is Dancing');
    $candidates = (new ShowImageMatcher)->candidates($results, 'World Is Dancing');

    $result = (new PremiereCalculator)->fromSubsPlease($candidates);

    expect($result)->not->toBeNull()
        ->and($result->source)->toBe(PremiereSource::SubsPlease)
        ->and($result->premieredAt->toIso8601String())->toBe(Carbon::parse('Mon, 29 Jun 2026 13:34:44 +0000')->utc()->toIso8601String());
});

test('falls back to the earliest subsplease release date when there is no episode 01 entry', function () {
    Http::preventStrayRequests();
    Http::fake([
        'subsplease.org/api/*' => Http::response(
            file_get_contents(dirname(__DIR__, 3).'/Fixtures/subsplease_search_digimon-beatbreak.json'),
            200,
            ['Content-Type' => 'application/json'],
        ),
    ]);

    $results = (new SubsPleaseApiClient)->search('Digimon Beatbreak');
    $candidates = (new ShowImageMatcher)->candidates($results, 'Digimon Beatbreak');

    // This fixture has no standalone "01" episode (only a "01-24" batch entry), so
    // the earliest individual release_date across all candidates should be used.
    $expectedEarliest = collect($results)
        ->pluck('releaseDate')
        ->map(fn (string $date) => Carbon::parse($date)->utc())
        ->sort()
        ->first();

    $result = (new PremiereCalculator)->fromSubsPlease($candidates);

    expect($result)->not->toBeNull()
        ->and($result->source)->toBe(PremiereSource::SubsPlease)
        ->and($result->premieredAt->equalTo($expectedEarliest))->toBeTrue();
});
