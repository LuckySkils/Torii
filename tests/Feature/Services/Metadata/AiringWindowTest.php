<?php

declare(strict_types=1);

use App\Models\Anime;
use App\Models\AnimeAiring;
use App\Models\ShowAnimeLink;
use App\Services\Metadata\AiringWindow;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Carbon::setTestNow('2026-09-28 12:00:00');
});

/**
 * Weekly airings, episode 1 on 2026-09-07 15:00 UTC.
 *
 * @param  array<int, int>  $episodes
 */
function weeklyAnime(array $episodes): Anime
{
    $anime = metadataAnime();

    foreach ($episodes as $episode) {
        AnimeAiring::create([
            'anime_id' => $anime->id,
            'provider' => 'anilist',
            'episode' => $episode,
            'airs_at' => Carbon::parse('2026-09-07 15:00:00')->addWeeks($episode - 1),
        ]);
    }

    return $anime;
}

function windowOf(Anime $anime): array
{
    return app(AiringWindow::class)->for($anime->id, now());
}

test('mid-run: previous aired, current is due next, next follows it', function () {
    // Episodes 1-3 aired on 09-07, 09-14, 09-21; episode 4 airs today at 15:00.
    $window = windowOf(weeklyAnime(range(1, 12)));

    expect($window)->toBe([
        'previous' => ['episode' => 3, 'airsAt' => '2026-09-21T15:00:00+00:00'],
        'current' => ['episode' => 4, 'airsAt' => '2026-09-28T15:00:00+00:00'],
        'next' => ['episode' => 5, 'airsAt' => '2026-10-05T15:00:00+00:00'],
    ]);
});

test('the window moves on by itself once the current airing time passes', function () {
    $anime = weeklyAnime(range(1, 12));

    // Exactly at airing time it is still current…
    $this->travelTo(Carbon::parse('2026-09-28 15:00:00'));
    expect(windowOf($anime)['current']['episode'])->toBe(4);

    // …and a second later it becomes previous.
    $this->travelTo(Carbon::parse('2026-09-28 15:00:01'));
    expect(windowOf($anime))->toMatchArray([
        'previous' => ['episode' => 4, 'airsAt' => '2026-09-28T15:00:00+00:00'],
        'current' => ['episode' => 5, 'airsAt' => '2026-10-05T15:00:00+00:00'],
        'next' => ['episode' => 6, 'airsAt' => '2026-10-12T15:00:00+00:00'],
    ]);
});

test('gaps are null: not started, last episode, finished, unknown', function () {
    expect(windowOf(weeklyAnime([6, 7])))->toMatchArray(['previous' => null])
        ->and(windowOf(weeklyAnime([3, 4]))['next'])->toBeNull()
        ->and(windowOf(weeklyAnime([1, 2]))['current'])->toBeNull()
        ->and(windowOf(metadataAnime()))->toBe(['previous' => null, 'current' => null, 'next' => null]);
});

test('the anime page lists airings newest first and carries the window on the anime object', function () {
    $this->withoutVite();
    $anime = weeklyAnime(range(1, 6));

    $this->get("/anime/{$anime->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('airings.0.episode', 6)
        ->where('airings.5.episode', 1)
        ->where('anime.airingWindow.previous.episode', 3)
        ->where('anime.airingWindow.current.episode', 4)
        ->where('anime.airingWindow.next.episode', 5));
});

test('the show page anime object carries the window too', function () {
    $show = metadataShow('Windowed');
    $anime = weeklyAnime(range(1, 12));
    ShowAnimeLink::create(['show_id' => $show->id, 'anime_id' => $anime->id, 'confidence' => 90, 'source' => 'auto', 'linked_at' => now()]);

    $this->get("/shows/{$show->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('show.anime.airingWindow.current', ['episode' => 4, 'airsAt' => '2026-09-28T15:00:00+00:00'])
        // Same airing as the older nextEpisode/nextAiringAt fields.
        ->where('show.anime.nextEpisode', 4));
});
