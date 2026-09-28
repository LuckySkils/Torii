<?php

declare(strict_types=1);

use App\Enums\LinkSource;
use App\Enums\MatchRule;
use App\Enums\SuggestionReason;
use App\Jobs\FetchAnimeCover;
use App\Jobs\MatchShowsToAnime;
use App\Models\Release;
use App\Models\ShowAnimeLink;
use App\Models\ShowAnimeRejection;
use App\Models\ShowAnimeSuggestion;
use App\Services\Metadata\Matching\AnimeMatcher;
use App\Services\Metadata\Matching\MatchOutcome;
use App\Services\Metadata\Matching\ShowAnimeLinker;
use App\Services\Metadata\Matching\TitleNormalizer;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Queue::fake([FetchAnimeCover::class]);
    Carbon::setTestNow('2026-09-28 12:00:00');
});

function runMatching(?int $showId = null): void
{
    (new MatchShowsToAnime($showId))->handle(app(ShowAnimeLinker::class));
}

// ── The subtitle rule ────────────────────────────────────────────────────────

test('the part of a title before its first colon scores 95, just below exact', function () {
    $matcher = new AnimeMatcher(new TitleNormalizer);

    expect($matcher->score('Saijo no Osewa', ['Saijo no Osewa: Takane no Hanadarake na Meimonkou de']))->toBe(95)
        ->and($matcher->score('Buchigire Reijou wa Houfuku wo Chikaimashita', ['Buchigire Reijou wa Houfuku wo Chikaimashita.: Madousho no Chikara de']))->toBe(95)
        // Spacing and season markers normalize as everywhere else.
        ->and($matcher->score('Futsutsuka na Akujo dewa Gozaimasu ga', ['Futsutsuka na Akujo de wa Gozaimasu ga: Suuguu Chouso Torikae Den']))->toBe(95)
        ->and($matcher->score('Mushoku Tensei S3', ['Mushoku Tensei III: Isekai Ittara Honki Dasu']))->toBe(95)
        // An exact title still wins over its own prefix.
        ->and($matcher->score('Kaijuu 8-gou - Narumi no Heijitsu', ['Kaijuu 8-gou: Narumi no Heijitsu']))->toBe(100);
});

test('a season marker in the subtitle counts, so a season-1 show never matches a sequel by prefix', function () {
    $matcher = new AnimeMatcher(new TitleNormalizer);
    $sequel = ['Hell Mode: Yarikomi-zuki no Gamer wa Haisettei no Isekai de Musou Suru 2nd Season'];

    expect($matcher->score('Hell Mode S2', $sequel))->toBe(95)
        ->and($matcher->score('Hell Mode', $sequel))->toBe(0);
});

test('a title without a colon has no prefix', function () {
    $matcher = new AnimeMatcher(new TitleNormalizer);

    expect($matcher->score('Meitantei Precure!', ['Meitantei Precure! Fushigi na Niwa to Futari no Himitsu']))->toBe(0);
});

test('exactly one subtitle match auto-links at 95', function () {
    $show = metadataShow('Saijo no Osewa');
    $anime = metadataAnime(['title_romaji' => 'Saijo no Osewa: Takane no Hanadarake na Meimonkou de']);

    runMatching();

    $link = ShowAnimeLink::where('show_id', $show->id)->sole();

    expect($link->anime_id)->toBe($anime->id)
        ->and($link->confidence)->toBe(95)
        ->and($link->source)->toBe(LinkSource::Auto)
        ->and(ShowAnimeSuggestion::count())->toBe(0);
});

// ── Ambiguity → suggestions ──────────────────────────────────────────────────

test('several strong candidates link nothing and all become suggestions, with their rule', function () {
    $show = metadataShow('One Piece');
    $main = metadataAnime(['title_romaji' => 'ONE PIECE']);
    $spinoff = metadataAnime(['title_romaji' => 'ONE PIECE: HEROINES']);
    metadataAnime(['title_romaji' => 'Something Unrelated']);

    runMatching();

    expect(ShowAnimeLink::count())->toBe(0)
        ->and(ShowAnimeSuggestion::where('show_id', $show->id)->orderByDesc('score')->get()
            ->map(fn (ShowAnimeSuggestion $s) => [$s->anime_id, $s->score, $s->rule])->all())
        ->toBe([[$main->id, 100, MatchRule::Exact], [$spinoff->id, 95, MatchRule::SubtitlePrefix]]);
});

test('two subtitle matches are ambiguous too', function () {
    $show = metadataShow('Sekai Saikyou no Kouei');
    metadataAnime(['title_romaji' => 'Sekai Saikyou no Kouei: Meikyuukoku no Shinjin Tansakusha']);
    metadataAnime(['title_romaji' => 'Sekai Saikyou no Kouei: Recap']);

    $decision = app(ShowAnimeLinker::class)->plan($show->id)[0];

    expect($decision->outcome)->toBe(MatchOutcome::Ambiguous)
        ->and($decision->suggestions)->toHaveCount(2);
});

test('the other rules follow the same ambiguity rule', function () {
    $show = metadataShow('Shangri-La Frontier S3');
    metadataAnime(['title_romaji' => 'Shangri-La Frontier 3rd Season']);
    metadataAnime(['title_english' => 'Shangri-La Frontier Season 3']);

    runMatching();

    expect(ShowAnimeSuggestion::where('show_id', $show->id)->pluck('rule')->unique()->values()->all())->toBe([MatchRule::SeasonMarker]);
});

test('suggestions include only candidates within 10 of the top', function () {
    $show = metadataShow('Tensei Shitara Slime Datta Ken S4');
    metadataAnime(['title_english' => 'That Time I Got Reincarnated as a Slime Season 4', 'synonyms' => ['Tensei Shitara Slime Datta Ken S4']]);
    metadataAnime(['title_romaji' => 'Tensei shitara Slime Datta Ken 4th Season: Recap']);
    // One edit on the 30-character base: 80, so 20 below the top.
    metadataAnime(['title_romaji' => 'Tensei Shitara Slime Datta Kan 4th Season']);

    runMatching();

    expect(ShowAnimeSuggestion::where('show_id', $show->id)->orderByDesc('score')->pluck('score')->all())->toBe([100, 95]);
});

test('weak and empty decisions leave no suggestions', function () {
    metadataShow('Seihantai na Kimi to Boku');
    metadataShow('Nothing Like It');
    metadataAnime(['title_romaji' => 'Seihantai na Kimi to Bokuu']);

    runMatching();

    expect(ShowAnimeSuggestion::count())->toBe(0)->and(ShowAnimeLink::count())->toBe(0);
});

test('matching runs refresh suggestions: stale ones go, surviving ones keep created_at', function () {
    $show = metadataShow('One Piece');
    $main = metadataAnime(['title_romaji' => 'ONE PIECE']);
    $spinoff = metadataAnime(['title_romaji' => 'ONE PIECE: HEROINES']);
    $film = metadataAnime(['title_romaji' => 'ONE PIECE: Film Red']);

    runMatching();
    $createdAt = ShowAnimeSuggestion::where('anime_id', $main->id)->value('created_at');
    expect(ShowAnimeSuggestion::where('show_id', $show->id)->count())->toBe(3);

    $this->travel(1)->days();
    $film->delete();
    runMatching();

    expect(ShowAnimeSuggestion::where('show_id', $show->id)->orderBy('anime_id')->pluck('anime_id')->all())->toBe([$main->id, $spinoff->id])
        ->and(ShowAnimeSuggestion::where('anime_id', $main->id)->value('created_at'))->toEqual($createdAt);

    // Once only one strong candidate is left, it links and the suggestions are cleared.
    $spinoff->delete();
    runMatching();

    expect(ShowAnimeLink::where('show_id', $show->id)->value('anime_id'))->toBe($main->id)
        ->and(ShowAnimeSuggestion::count())->toBe(0);
});

test('a show that already has an auto link gets no suggestions from an ambiguous run', function () {
    $show = metadataShow('Kokoore');
    $linked = metadataAnime(['title_romaji' => 'Kokoore!']);
    runMatching();

    metadataAnime(['title_english' => 'Kokoore']);
    runMatching();

    expect(ShowAnimeLink::where('show_id', $show->id)->value('anime_id'))->toBe($linked->id)
        ->and(ShowAnimeSuggestion::count())->toBe(0);
});

// ── Rejections ───────────────────────────────────────────────────────────────

test('a rejected pair is never auto-linked or suggested', function () {
    $show = metadataShow('One Piece');
    $main = metadataAnime(['title_romaji' => 'ONE PIECE']);
    $spinoff = metadataAnime(['title_romaji' => 'ONE PIECE: HEROINES']);

    ShowAnimeRejection::create(['show_id' => $show->id, 'anime_id' => $main->id, 'rejected_at' => now()]);
    ShowAnimeRejection::create(['show_id' => $show->id, 'anime_id' => $spinoff->id, 'rejected_at' => now()]);

    runMatching();

    expect(ShowAnimeLink::count())->toBe(0)->and(ShowAnimeSuggestion::count())->toBe(0);
});

test('rejecting one of two suggestions leaves the other as a suggestion: the show never auto-links again', function () {
    $show = metadataShow('One Piece');
    $main = metadataAnime(['title_romaji' => 'ONE PIECE']);
    $spinoff = metadataAnime(['title_romaji' => 'ONE PIECE: HEROINES']);
    runMatching();

    app(ShowAnimeLinker::class)->reject($show, $spinoff);

    expect(ShowAnimeSuggestion::where('show_id', $show->id)->pluck('anime_id')->all())->toBe([$main->id])
        ->and(ShowAnimeRejection::where('show_id', $show->id)->pluck('anime_id')->all())->toBe([$spinoff->id]);

    runMatching();

    $suggestion = ShowAnimeSuggestion::where('show_id', $show->id)->sole();

    expect(ShowAnimeLink::count())->toBe(0)
        ->and($suggestion->anime_id)->toBe($main->id)
        ->and($suggestion->reason)->toBe(SuggestionReason::ShowHasRejection);
});

test('unlinking an auto link sticks: matching never restores it', function () {
    $show = metadataShow('Grand Blue S3');
    $anime = metadataAnime(['title_romaji' => 'Grand Blue 3rd Season']);
    runMatching();

    $this->delete("/shows/{$show->id}/link")->assertRedirect();
    runMatching();

    expect(ShowAnimeLink::count())->toBe(0)
        ->and(ShowAnimeRejection::where('show_id', $show->id)->sole()->anime_id)->toBe($anime->id);
});

test('unlinking a manual link records a rejection too', function () {
    $show = metadataShow('Grand Blue S3');
    $anime = metadataAnime(['title_romaji' => 'Grand Blue 3rd Season']);
    app(ShowAnimeLinker::class)->linkManually($show, $anime);

    $this->delete("/shows/{$show->id}/link")->assertRedirect();
    runMatching();

    expect(ShowAnimeLink::count())->toBe(0)
        ->and(ShowAnimeRejection::where('show_id', $show->id)->where('anime_id', $anime->id)->exists())->toBeTrue();
});

test('manually linking a rejected pair clears its rejection and the show\'s suggestions', function () {
    $show = metadataShow('One Piece');
    $main = metadataAnime(['title_romaji' => 'ONE PIECE']);
    $spinoff = metadataAnime(['title_romaji' => 'ONE PIECE: HEROINES']);
    ShowAnimeRejection::create(['show_id' => $show->id, 'anime_id' => $spinoff->id, 'rejected_at' => now()]);
    ShowAnimeSuggestion::create(['show_id' => $show->id, 'anime_id' => $main->id, 'score' => 100, 'rule' => 'exact', 'created_at' => now()]);

    $this->post("/shows/{$show->id}/link", ['anime_id' => $spinoff->id])->assertRedirect();

    expect(ShowAnimeRejection::count())->toBe(0)
        ->and(ShowAnimeSuggestion::count())->toBe(0)
        ->and(ShowAnimeLink::where('show_id', $show->id)->value('anime_id'))->toBe($spinoff->id);
});

test('rejections of other shows do not affect this one', function () {
    $show = metadataShow('Grand Blue S3');
    $other = metadataShow('Something Else');
    $anime = metadataAnime(['title_romaji' => 'Grand Blue 3rd Season']);
    ShowAnimeRejection::create(['show_id' => $other->id, 'anime_id' => $anime->id, 'rejected_at' => now()]);

    runMatching();

    expect(ShowAnimeLink::where('show_id', $show->id)->value('anime_id'))->toBe($anime->id);
});

// ── Endpoints ────────────────────────────────────────────────────────────────

test('GET suggestions returns the pending candidates, best first, in the search result shape plus rule', function () {
    Http::preventStrayRequests();
    $show = metadataShow('One Piece');
    $main = metadataAnime(['title_romaji' => 'ONE PIECE', 'season' => 'FALL', 'season_year' => 1999, 'format' => 'TV']);
    $spinoff = metadataAnime(['title_romaji' => 'ONE PIECE: HEROINES']);
    runMatching();

    $response = $this->getJson("/shows/{$show->id}/link/suggestions")->assertOk();

    $response->assertJsonCount(2, 'suggestions')
        ->assertJsonPath('suggestions.0', [
            'id' => $main->id,
            'titleRomaji' => 'ONE PIECE',
            'titleEnglish' => null,
            'titleNative' => null,
            'season' => 'FALL',
            'seasonYear' => 1999,
            'format' => 'TV',
            'episodesTotal' => null,
            'coverUrl' => null,
            'coverWidth' => null,
            'coverHeight' => null,
            'seasonNumber' => 1,
            'score' => 100,
            'linkedShows' => [],
            'rule' => 'exact',
            'reason' => 'ambiguous',
            'createdAt' => '2026-09-28T12:00:00+00:00',
        ])
        ->assertJsonPath('suggestions.1.id', $spinoff->id)
        ->assertJsonPath('suggestions.1.rule', 'subtitle_prefix');

    Http::assertNothingSent();
});

test('GET suggestions is empty for a show with none', function () {
    $this->getJson('/shows/'.metadataShow('Lonely')->id.'/link/suggestions')->assertOk()->assertExactJson(['suggestions' => []]);
});

test('DELETE a suggestion rejects it and flashes', function () {
    $show = metadataShow('One Piece');
    metadataAnime(['title_romaji' => 'ONE PIECE']);
    $spinoff = metadataAnime(['title_romaji' => 'ONE PIECE: HEROINES']);
    runMatching();

    $this->from("/shows/{$show->id}")
        ->delete("/shows/{$show->id}/link/suggestions/{$spinoff->id}")
        ->assertRedirect("/shows/{$show->id}")
        ->assertSessionHas('success', '"ONE PIECE: HEROINES" won\'t be suggested for "One Piece" again.');

    expect(ShowAnimeSuggestion::where('anime_id', $spinoff->id)->exists())->toBeFalse()
        ->and(ShowAnimeRejection::where('show_id', $show->id)->sole()->anime_id)->toBe($spinoff->id);
});

test('DELETE for something that is not a pending suggestion is a 404 and records nothing', function () {
    $show = metadataShow('One Piece');
    $anime = metadataAnime(['title_romaji' => 'ONE PIECE']);

    $this->delete("/shows/{$show->id}/link/suggestions/{$anime->id}")->assertNotFound();

    expect(ShowAnimeRejection::count())->toBe(0);
});

// ── Props ────────────────────────────────────────────────────────────────────

test('the shared pendingLinkSuggestions prop counts shows needing review, not suggestions', function () {
    $this->withoutVite();
    metadataShow('One Piece');
    metadataShow('Sekai Saikyou no Kouei');
    metadataAnime(['title_romaji' => 'ONE PIECE']);
    metadataAnime(['title_romaji' => 'ONE PIECE: HEROINES']);
    metadataAnime(['title_romaji' => 'Sekai Saikyou no Kouei: A']);
    metadataAnime(['title_romaji' => 'Sekai Saikyou no Kouei: B']);
    runMatching();

    expect(ShowAnimeSuggestion::count())->toBe(4);

    $this->get('/anime')->assertInertia(fn (AssertableInertia $page) => $page->where('pendingLinkSuggestions', 2));
});

test('the shared prop is 0 with the metadata layer empty', function () {
    $this->withoutVite();

    $this->get('/anime')->assertInertia(fn (AssertableInertia $page) => $page->where('pendingLinkSuggestions', 0));
});

test('show list, show page and dashboard entries carry hasSuggestions', function () {
    $pending = metadataShow('One Piece');
    metadataShow('Quiet Show');
    metadataAnime(['title_romaji' => 'ONE PIECE']);
    metadataAnime(['title_romaji' => 'ONE PIECE: HEROINES']);
    runMatching();

    $this->get('/shows')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('shows.data.0.name', 'One Piece')
        ->where('shows.data.0.hasSuggestions', true)
        ->where('shows.data.1.hasSuggestions', false));

    $this->get("/shows/{$pending->id}")->assertInertia(fn (AssertableInertia $page) => $page->where('show.hasSuggestions', true));

    Release::create([
        'show_id' => $pending->id,
        'guid' => 'G1',
        'title' => '[SubsPlease] One Piece - 1150 (1080p) [AAAA1111].mkv',
        'episode' => '1150',
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:AAAA1111',
        'published_at' => now()->subHour(),
        'first_seen_at' => now(),
    ]);
    Http::fake(fn () => throw new ConnectionException('offline'));

    $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page->where('latestReleases.0.show.hasSuggestions', true));
});
