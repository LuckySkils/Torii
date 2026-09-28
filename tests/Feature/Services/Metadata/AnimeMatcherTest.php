<?php

declare(strict_types=1);

use App\Enums\LinkSource;
use App\Enums\Season;
use App\Events\ShowDiscovered;
use App\Jobs\FetchAnimeCover;
use App\Jobs\MatchShowsToAnime;
use App\Models\ShowAnimeLink;
use App\Services\Metadata\Matching\AnimeMatcher;
use App\Services\Metadata\Matching\MatchOutcome;
use App\Services\Metadata\Matching\ShowAnimeLinker;
use App\Services\Metadata\Matching\TitleNormalizer;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // Matching dispatches cover fetches for new links; none of these tests want the CDN.
    Queue::fake([FetchAnimeCover::class]);
});

function matcher(): AnimeMatcher
{
    return new AnimeMatcher(new TitleNormalizer);
}

test('normalization unifies season markers and drops tags and punctuation', function (string $title, string $base, ?int $season) {
    $normalized = (new TitleNormalizer)->normalize($title);

    expect($normalized->base)->toBe($base)
        ->and($normalized->season)->toBe($season);
})->with([
    ['Grand Blue S3', 'grand blue', 3],
    ['Grand Blue Season 3', 'grand blue', 3],
    ['Grand Blue 3rd Season', 'grand blue', 3],
    ['Youjo Senki II', 'youjo senki', 2],
    ['Mushoku Tensei III: Isekai Ittara Honki Dasu', 'mushoku tensei isekai ittara honki dasu', 3],
    ['[SubsPlease] One Piece (1080p)', 'one piece', null],
    ['Some Show (TV)', 'some show', null],
    ['Azur Lane - Bisoku Zenshin! S2', 'azur lane bisoku zenshin', 2],
    ['Toumei na Yoru ni Kakeru Kimi to, Me ni Mienai Koi wo Shita.', 'toumei na yoru ni kakeru kimi to me ni mienai koi wo shita', null],
    // Lowercase "ii" is a romaji word, not a numeral; "Part II" isn't a season.
    ['Ii Hito', 'ii hito', null],
    ['Show Part II', 'show part ii', null],
]);

test('an exact normalized match scores 100', function () {
    expect(matcher()->score('One Piece', ['ONE PIECE']))->toBe(100)
        ->and(matcher()->score('Kaijuu 8-gou - Narumi no Heijitsu', ['Kaijuu 8-gou: Narumi no Heijitsu']))->toBe(100);
});

test('accents and romanization spacing do not stop an exact match', function () {
    expect(matcher()->score('Otome Kaijuu Carameliser', ['Otome Kaijuu Caraméliser']))->toBe(100)
        ->and(matcher()->score('Hanaori-san wa Tensei shitemo Kenka ga Shitai', ['Hanaori-san wa Tensei Shite mo Kenka ga Shitai']))->toBe(100)
        ->and(matcher()->score('Suterare Seijo no Isekai Gohan Tabi S2', ['Suterare Seijo no Isekai Gohantabi 2nd Season']))->toBe(90)
        // Native titles are left alone by the accent folding.
        ->and((new TitleNormalizer)->normalize('薬屋のひとりごと 第3期')->plain)->toBe('薬屋のひとりごと 第3期');
});

test('season-marker variants of the same season score 90', function () {
    expect(matcher()->score('Tensei Shitara Ken Deshita S2', ['Tensei Shitara Ken Deshita 2nd Season']))->toBe(90)
        ->and(matcher()->score('Grand Blue S3', ['Grand Blue Season 3']))->toBe(90)
        ->and(matcher()->score('Youjo Senki S2', ['Youjo Senki II']))->toBe(90)
        ->and(matcher()->score('Show 2nd Season', ['Show S2']))->toBe(90);
});

test('different season numbers never match, however close the name', function () {
    expect(matcher()->score('Hell Mode S2', ['Hell Mode']))->toBe(0)
        ->and(matcher()->score('Grand Blue S3', ['Grand Blue Season 2']))->toBe(0);
});

test('a near-identical name scores 70–85, and anything below a 0.9 ratio scores 0', function () {
    $close = matcher()->score('Tsuihou sareta Tensei Juukishi wa Game Chishiki de Musou suru', ['Tsuihou Sareta Tensei Juukishi wa Game Chishiki de Musou Suru!']);
    $typo = matcher()->score('Seihantai na Kimi to Boku', ['Seihantai na Kimi to Bokuu']);

    expect($close)->toBe(100)
        ->and($typo)->toBeGreaterThanOrEqual(70)->toBeLessThanOrEqual(85)
        ->and(matcher()->score('Kimiai', ['Kimi to Ai no Monogatari']))->toBe(0);
});

test('the best title wins, synonyms included', function () {
    expect(matcher()->score('Hyakkano', ['Kimi no Koto ga Daidaidaidaidaisuki na 100-nin no Kanojo', 'Hyakkano', '100 Girlfriends']))->toBe(100);
});

test('a single strong candidate is auto-linked with its score as confidence', function () {
    $show = metadataShow('Grand Blue S3');
    $anime = metadataAnime(['title_romaji' => 'Grand Blue 3rd Season', 'title_english' => 'Grand Blue Dreaming Season 3']);
    metadataAnime(['title_romaji' => 'Something Else Entirely']);

    (new MatchShowsToAnime)->handle(app(ShowAnimeLinker::class));

    $link = ShowAnimeLink::where('show_id', $show->id)->first();

    expect($link->anime_id)->toBe($anime->id)
        ->and($link->confidence)->toBe(90)
        ->and($link->source)->toBe(LinkSource::Auto)
        ->and($link->linked_at)->not->toBeNull();
});

test('a runner-up within 10 points blocks auto-linking', function () {
    $show = metadataShow('Shangri-La Frontier S3');
    metadataAnime(['title_romaji' => 'Shangri-La Frontier 3rd Season']);
    metadataAnime(['title_romaji' => 'Shangri-La Frontier Season 3 Recap']);
    metadataAnime(['title_english' => 'Shangri-La Frontier Season 3']);

    $decision = app(ShowAnimeLinker::class)->plan($show->id)[0];

    expect($decision->outcome)->toBe(MatchOutcome::Ambiguous)
        ->and($decision->best()->score)->toBe(90)
        ->and($decision->runnerUp()->score)->toBe(90);

    (new MatchShowsToAnime)->handle(app(ShowAnimeLinker::class));

    expect(ShowAnimeLink::count())->toBe(0);
});

test('a runner-up exactly 10 lower does not block', function () {
    $show = metadataShow('Tensei Shitara Slime Datta Ken S4');
    $anime = metadataAnime(['title_romaji' => 'Tensei shitara Slime Datta Ken 4th Season']);
    // One edit on a 30-character base: ratio 0.967, which scores exactly 80.
    metadataAnime(['title_romaji' => 'Tensei Shitara Slime Datta Kan 4th Season']);

    $decision = app(ShowAnimeLinker::class)->plan($show->id)[0];

    expect($decision->best()->score)->toBe(90)
        ->and($decision->runnerUp()->score)->toBe(80)
        ->and($decision->outcome)->toBe(MatchOutcome::Link)
        ->and($decision->link->candidate->animeId)->toBe($anime->id);
});

test('a best score below 90 is left for manual linking', function () {
    $show = metadataShow('Seihantai na Kimi to Boku');
    metadataAnime(['title_romaji' => 'Seihantai na Kimi to Bokuu']);

    $decision = app(ShowAnimeLinker::class)->plan($show->id)[0];

    expect($decision->outcome)->toBe(MatchOutcome::Weak);
});

test('a manual link is never overwritten by automatic matching', function () {
    $show = metadataShow('One Piece');
    $manual = metadataAnime(['title_romaji' => 'One Piece Film: Red']);
    metadataAnime(['title_romaji' => 'ONE PIECE']);

    app(ShowAnimeLinker::class)->linkManually($show, $manual);

    (new MatchShowsToAnime)->handle(app(ShowAnimeLinker::class));
    (new MatchShowsToAnime($show->id))->handle(app(ShowAnimeLinker::class));

    $link = ShowAnimeLink::where('show_id', $show->id)->first();

    expect($link->anime_id)->toBe($manual->id)
        ->and($link->source)->toBe(LinkSource::Manual)
        ->and($link->confidence)->toBe(100)
        ->and(app(ShowAnimeLinker::class)->plan($show->id))->toBe([]);
});

test('an existing auto link is updated when a better match appears, and kept when nothing qualifies', function () {
    $show = metadataShow('Kokoore');
    $old = metadataAnime(['title_romaji' => 'Kokoore!']);

    (new MatchShowsToAnime)->handle(app(ShowAnimeLinker::class));
    expect(ShowAnimeLink::where('show_id', $show->id)->value('anime_id'))->toBe($old->id);

    // A second equally good candidate makes it ambiguous: the existing link stays.
    $other = metadataAnime(['title_english' => 'Kokoore']);
    (new MatchShowsToAnime)->handle(app(ShowAnimeLinker::class));
    expect(ShowAnimeLink::where('show_id', $show->id)->value('anime_id'))->toBe($old->id);

    // Once the first is gone, the other one is linked instead.
    $old->delete();
    (new MatchShowsToAnime)->handle(app(ShowAnimeLinker::class));
    expect(ShowAnimeLink::where('show_id', $show->id)->value('anime_id'))->toBe($other->id);
});

test('among strong candidates, the one consistent with the show premiere season wins', function () {
    $show = metadataShow('Hunter x Hunter', ['season' => Season::Autumn, 'season_year' => 2011]);
    metadataAnime(['title_romaji' => 'Hunter x Hunter', 'season' => 'FALL', 'season_year' => 1999]);
    $reboot = metadataAnime(['title_romaji' => 'Hunter x Hunter (2011)', 'synonyms' => ['Hunter x Hunter'], 'season' => 'FALL', 'season_year' => 2011]);

    $decision = app(ShowAnimeLinker::class)->plan($show->id)[0];

    expect($decision->outcome)->toBe(MatchOutcome::Link)
        ->and($decision->link->candidate->animeId)->toBe($reboot->id);
});

test('a premiere season never blocks a lone strong match (long-running shows)', function () {
    // Torii first saw One Piece in summer 2026; the anime started in 1999.
    $show = metadataShow('One Piece', ['season' => Season::Summer, 'season_year' => 2026]);
    $anime = metadataAnime(['title_romaji' => 'ONE PIECE', 'season' => 'FALL', 'season_year' => 1999]);

    $decision = app(ShowAnimeLinker::class)->plan($show->id)[0];

    expect($decision->outcome)->toBe(MatchOutcome::Link)
        ->and($decision->link->candidate->animeId)->toBe($anime->id)
        ->and($decision->link->score)->toBe(100);
});

test('running matching twice changes nothing', function () {
    metadataShow('Grand Blue S3');
    metadataAnime(['title_romaji' => 'Grand Blue 3rd Season']);

    (new MatchShowsToAnime)->handle(app(ShowAnimeLinker::class));
    $before = ShowAnimeLink::first()->only(['id', 'anime_id', 'confidence', 'linked_at']);

    $this->travel(1)->hours();
    (new MatchShowsToAnime)->handle(app(ShowAnimeLinker::class));

    expect(ShowAnimeLink::count())->toBe(1)
        ->and(ShowAnimeLink::first()->only(['id', 'anime_id', 'confidence', 'linked_at']))->toEqual($before);
});

test('anime:match --dry-run prints the proposals and writes nothing', function () {
    metadataShow('Grand Blue S3');
    metadataShow('Unmatched Show');
    metadataAnime(['title_romaji' => 'Grand Blue 3rd Season']);

    $this->artisan('anime:match --dry-run')
        ->expectsTable(
            ['Show', 'Outcome', 'Best match', 'Score', 'Rule', 'Runner-up', 'Score'],
            [
                ['Grand Blue S3', 'link', 'Grand Blue 3rd Season', 90, 'season_marker', '', ''],
                ['Unmatched Show', 'none', '', '', '', '', ''],
            ],
        )
        ->expectsOutputToContain('1 would link, 0 ambiguous and 0 held (would become suggestions), 0 weak, 1 no candidate')
        ->assertSuccessful();

    expect(ShowAnimeLink::count())->toBe(0);
});

test('anime:match without --dry-run queues the job, optionally for one show by name', function () {
    Queue::fake();
    $show = metadataShow('Grand Blue S3');

    $this->artisan('anime:match', ['--show' => 'Grand Blue S3'])->assertSuccessful();
    $this->artisan('anime:match', ['--show' => 'Nope'])->assertFailed();

    Queue::assertPushed(MatchShowsToAnime::class, fn (MatchShowsToAnime $job) => $job->showId === $show->id);
    Queue::assertPushed(MatchShowsToAnime::class, 1);
});

test('a newly discovered show is matched on its own', function () {
    Queue::fake();
    $show = metadataShow('Brand New Show');

    ShowDiscovered::dispatch($show);

    Queue::assertPushed(MatchShowsToAnime::class, fn (MatchShowsToAnime $job) => $job->showId === $show->id);
});
