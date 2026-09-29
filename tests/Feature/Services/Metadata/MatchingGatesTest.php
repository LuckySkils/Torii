<?php

declare(strict_types=1);

use App\Enums\LinkSource;
use App\Enums\SuggestionReason;
use App\Jobs\FetchAnimeCover;
use App\Jobs\MatchShowsToAnime;
use App\Models\Release;
use App\Models\Show;
use App\Models\ShowAnimeLink;
use App\Models\ShowAnimeRejection;
use App\Models\ShowAnimeSuggestion;
use App\Services\Metadata\Matching\MatchOutcome;
use App\Services\Metadata\Matching\ShowAnimeLinker;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([FetchAnimeCover::class]);
});

function gateRelease(Show $show, ?string $episode, bool $batch = false, ?int $from = null, ?int $to = null): void
{
    static $n = 0;
    $n++;

    Release::create([
        'show_id' => $show->id,
        'guid' => "GATE-{$n}",
        'title' => "[SubsPlease] {$show->name} - ".($episode ?? 'special')." (1080p) [{$n}].mkv",
        'episode' => $episode,
        'is_batch' => $batch,
        'batch_from' => $from,
        'batch_to' => $to,
        'resolution' => '1080p',
        'link' => "magnet:?xt=urn:btih:GATE{$n}",
        'published_at' => now(),
        'first_seen_at' => now(),
    ]);
}

function gateMatch(): void
{
    (new MatchShowsToAnime)->handle(app(ShowAnimeLinker::class));
}

/** The real Hyakkano entries: S1 carries the bare "Hyakkano" synonym, S3 says "Hyakkano 3". */
function hyakkanoEntries(): array
{
    return [
        metadataAnime(['title_romaji' => 'Kimi no Koto ga Dai Dai Dai Dai Daisuki na 100-nin no Kanojo', 'synonyms' => ['100 Kanojo', '100Kano', 'Hyakkano'], 'episodes_total' => 12, 'season' => 'FALL', 'season_year' => 2023]),
        metadataAnime(['title_romaji' => 'Kimi no Koto ga Dai Dai Dai Dai Daisuki na 100-nin no Kanojo 3rd Season', 'synonyms' => ['Hyakkano 3'], 'episodes_total' => 12, 'season' => 'SUMMER', 'season_year' => 2026]),
    ];
}

// ── Episode-count gate ───────────────────────────────────────────────────────

test('Hyakkano: episode 36 against a 12-episode entry is suggested with the reason, never linked', function () {
    $show = metadataShow('Hyakkano');
    gateRelease($show, '35');
    gateRelease($show, '36');
    [$season1] = hyakkanoEntries();

    $decision = app(ShowAnimeLinker::class)->plan($show->id)[0];

    // The score is untouched: it's a gate, not a penalty.
    expect($decision->outcome)->toBe(MatchOutcome::Held)
        ->and($decision->reason)->toBe(SuggestionReason::EpisodeCount)
        ->and($decision->best()->score)->toBe(100);

    gateMatch();

    $suggestion = ShowAnimeSuggestion::where('show_id', $show->id)->sole();

    expect(ShowAnimeLink::count())->toBe(0)
        ->and($suggestion->anime_id)->toBe($season1->id)
        ->and($suggestion->score)->toBe(100)
        ->and($suggestion->reason)->toBe(SuggestionReason::EpisodeCount);
});

test('up to 2 episodes past the total still links (specials); 3 past is held', function (string $episode, bool $links) {
    $show = metadataShow('Grand Blue S3');
    gateRelease($show, $episode);
    metadataAnime(['title_romaji' => 'Grand Blue Season 3', 'episodes_total' => 12]);

    gateMatch();

    expect(ShowAnimeLink::where('show_id', $show->id)->exists())->toBe($links)
        ->and(ShowAnimeSuggestion::where('show_id', $show->id)->exists())->toBe(! $links);
})->with([
    'within the total' => ['12', true],
    'a .5 special' => ['12.5', true],
    'two past' => ['14', true],
    'three past' => ['15', false],
]);

test('a batch range counts by its last episode', function () {
    $show = metadataShow('Grand Blue S3');
    gateRelease($show, null, batch: true, from: 1, to: 24);
    metadataAnime(['title_romaji' => 'Grand Blue Season 3', 'episodes_total' => 12]);

    gateMatch();

    expect(ShowAnimeLink::count())->toBe(0)
        ->and(ShowAnimeSuggestion::sole()->reason)->toBe(SuggestionReason::EpisodeCount);
});

test('the gate is skipped when the total is unknown or the show has no numbered episodes', function () {
    $unknownTotal = metadataShow('Grand Blue S3');
    gateRelease($unknownTotal, '40');
    metadataAnime(['title_romaji' => 'Grand Blue Season 3', 'episodes_total' => null]);

    $specialsOnly = metadataShow('Kokoore');
    gateRelease($specialsOnly, null);
    gateRelease($specialsOnly, 'OVA');
    metadataAnime(['title_romaji' => 'Kokoore!', 'synonyms' => ['Kokoore'], 'episodes_total' => 1]);

    $noReleases = metadataShow('Sayonara Lara');
    metadataAnime(['title_romaji' => 'Sayonara Lara', 'episodes_total' => 1]);

    gateMatch();

    expect(ShowAnimeLink::count())->toBe(3)->and(ShowAnimeSuggestion::count())->toBe(0);
});

test('highestEpisodes reads numbered singles and batch ends, and ignores everything else', function () {
    $a = metadataShow('A');
    gateRelease($a, '03');
    gateRelease($a, '12.5');
    gateRelease($a, '7');
    $b = metadataShow('B');
    gateRelease($b, '05');
    gateRelease($b, null, batch: true, from: 1, to: 26);
    $c = metadataShow('C');
    gateRelease($c, 'OVA');
    gateRelease($c, null, batch: true);

    expect(Release::highestEpisodes([$a->id, $b->id, $c->id]))->toBe([$a->id => 12.5, $b->id => 26.0])
        ->and(Release::highestEpisodes([]))->toBe([]);
});

test('the episode gate never touches an ambiguous decision or an existing link', function () {
    $ambiguous = metadataShow('Shangri-La Frontier S3');
    gateRelease($ambiguous, '30');
    metadataAnime(['title_romaji' => 'Shangri-La Frontier 3rd Season', 'episodes_total' => 12]);
    metadataAnime(['title_english' => 'Shangri-La Frontier Season 3', 'episodes_total' => 12]);

    $linked = metadataShow('Grand Blue S3');
    $anime = metadataAnime(['title_romaji' => 'Grand Blue Season 3', 'episodes_total' => 12]);
    ShowAnimeLink::create(['show_id' => $linked->id, 'anime_id' => $anime->id, 'confidence' => 90, 'source' => 'auto', 'linked_at' => now()]);
    gateRelease($linked, '30');

    gateMatch();

    expect(ShowAnimeSuggestion::where('show_id', $ambiguous->id)->pluck('reason')->unique()->values()->all())->toBe([SuggestionReason::Ambiguous])
        ->and(ShowAnimeLink::where('show_id', $linked->id)->value('anime_id'))->toBe($anime->id);
});

// ── Rejection gate ───────────────────────────────────────────────────────────

test('a show with any rejection never auto-links, however clear the winner', function () {
    $show = metadataShow('Grand Blue S3');
    $unrelated = metadataAnime(['title_romaji' => 'Something Else Entirely']);
    $clear = metadataAnime(['title_romaji' => 'Grand Blue Season 3']);
    ShowAnimeRejection::create(['show_id' => $show->id, 'anime_id' => $unrelated->id, 'rejected_at' => now()]);

    gateMatch();
    gateMatch();

    $suggestion = ShowAnimeSuggestion::where('show_id', $show->id)->sole();

    expect(ShowAnimeLink::count())->toBe(0)
        ->and($suggestion->anime_id)->toBe($clear->id)
        ->and($suggestion->score)->toBe(90)
        ->and($suggestion->reason)->toBe(SuggestionReason::ShowHasRejection);
});

test('Detective Conan: rejecting one special leaves the other as a suggestion, not a link', function () {
    $show = metadataShow('Detective Conan');
    $highway = metadataAnime(['title_romaji' => 'Meitantei Conan: Highway no Datenshi', 'title_english' => 'Detective Conan: Fallen Angel of the Highway']);
    $hanamaru = metadataAnime(['title_romaji' => 'Meitantei Conan: Hanamaru na Answer', 'title_english' => 'Detective Conan: Hanamaru na Answer']);

    gateMatch();
    expect(ShowAnimeSuggestion::where('show_id', $show->id)->count())->toBe(2);

    $this->delete("/shows/{$show->id}/link/suggestions/{$highway->id}")->assertRedirect();
    gateMatch();

    $suggestion = ShowAnimeSuggestion::where('show_id', $show->id)->sole();

    expect(ShowAnimeLink::count())->toBe(0)
        ->and($suggestion->anime_id)->toBe($hanamaru->id)
        ->and($suggestion->reason)->toBe(SuggestionReason::ShowHasRejection);
});

test('unlinking a wrong auto link keeps the show out of auto-linking for good', function () {
    $show = metadataShow('Kokoore');
    $wrong = metadataAnime(['title_romaji' => 'Kokoore!']);
    gateMatch();

    $this->delete("/shows/{$show->id}/link")->assertRedirect();
    $right = metadataAnime(['title_english' => 'Kokoore']);
    gateMatch();

    expect(ShowAnimeLink::count())->toBe(0)
        ->and(ShowAnimeSuggestion::where('show_id', $show->id)->sole()->anime_id)->toBe($right->id);
});

test('manual linking through the UI still works for a show with rejections', function () {
    $show = metadataShow('Grand Blue S3');
    $rejected = metadataAnime(['title_romaji' => 'Grand Blue Recap']);
    $anime = metadataAnime(['title_romaji' => 'Grand Blue Season 3']);
    ShowAnimeRejection::create(['show_id' => $show->id, 'anime_id' => $rejected->id, 'rejected_at' => now()]);
    gateMatch();

    $this->post("/shows/{$show->id}/link", ['anime_id' => $anime->id])->assertRedirect()->assertSessionHas('success');

    $link = ShowAnimeLink::where('show_id', $show->id)->sole();

    expect($link->anime_id)->toBe($anime->id)
        ->and($link->source)->toBe(LinkSource::Manual)
        ->and(ShowAnimeSuggestion::count())->toBe(0)
        // Only the linked pair's rejection is cleared; the show's other one stays.
        ->and(ShowAnimeRejection::where('show_id', $show->id)->pluck('anime_id')->all())->toBe([$rejected->id]);
});

test('a show with a rejection and only weak or no candidates gets no suggestions', function () {
    $show = metadataShow('Seihantai na Kimi to Boku');
    $rejected = metadataAnime(['title_romaji' => 'Unrelated']);
    metadataAnime(['title_romaji' => 'Seihantai na Kimi to Bokuu']);
    ShowAnimeRejection::create(['show_id' => $show->id, 'anime_id' => $rejected->id, 'rejected_at' => now()]);

    gateMatch();

    expect(ShowAnimeSuggestion::count())->toBe(0)->and(ShowAnimeLink::count())->toBe(0);
});

test('the dry run shows held decisions with their reason', function () {
    $show = metadataShow('Hyakkano');
    gateRelease($show, '36');
    hyakkanoEntries();

    $this->artisan('anime:match --dry-run')
        ->expectsOutputToContain('held: episode_count')
        ->expectsOutputToContain('0 would link, 0 ambiguous and 1 held (would become suggestions)')
        ->assertSuccessful();

    expect(ShowAnimeSuggestion::count())->toBe(0);
});

test('suggestions report their reason', function () {
    $show = metadataShow('Hyakkano');
    gateRelease($show, '36');
    hyakkanoEntries();
    gateMatch();

    $this->getJson("/shows/{$show->id}/link/suggestions")->assertJsonPath('suggestions.0.reason', 'episode_count');
});
