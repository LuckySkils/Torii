<?php

declare(strict_types=1);

use App\Enums\LinkSource;
use App\Jobs\FetchAnimeCover;
use App\Models\Anime;
use App\Models\AnimeImage;
use App\Models\ShowAnimeLink;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake([FetchAnimeCover::class]);
    Sleep::fake(syncWithCarbon: true);
    Carbon::setTestNow('2026-09-28 12:00:00');
});

function seedLocalCandidates(int $count): void
{
    for ($i = 1; $i <= $count; $i++) {
        metadataAnime(['title_romaji' => "Grand Blue Spinoff {$i}"]);
    }
}

test('search with enough local hits answers from the database only, scored against the show name', function () {
    $show = metadataShow('Grand Blue S3');
    $best = metadataAnime([
        'title_romaji' => 'Grand Blue 3rd Season',
        'title_english' => 'Grand Blue Dreaming Season 3',
        'season' => 'SUMMER',
        'season_year' => 2026,
        'format' => 'TV',
        'episodes_total' => 12,
    ]);
    AnimeImage::create(['anime_id' => $best->id, 'source_url' => 'x', 'mime' => 'image/jpeg', 'data' => '', 'size' => 0, 'sha256' => 'abcdef0123456789'.str_repeat('0', 48), 'fetched_at' => now()]);
    seedLocalCandidates(5);
    metadataAnime(['title_romaji' => 'Unrelated']);

    $response = $this->getJson("/shows/{$show->id}/link/search");

    $response->assertOk()
        ->assertJsonPath('query', 'Grand Blue S3')
        ->assertJsonPath('providerSearched', false)
        ->assertJsonPath('providerError', null)
        ->assertJsonCount(6, 'results')
        ->assertJsonPath('results.0', [
            'id' => $best->id,
            'titleRomaji' => 'Grand Blue 3rd Season',
            'titleEnglish' => 'Grand Blue Dreaming Season 3',
            'titleNative' => null,
            'season' => 'SUMMER',
            'seasonYear' => 2026,
            'format' => 'TV',
            'episodesTotal' => 12,
            'coverUrl' => "/anime/{$best->id}/cover?v=abcdef01",
            'coverWidth' => null,
            'coverHeight' => null,
            'seasonNumber' => 3,
            'score' => 90,
            'linkedShows' => [],
        ]);

    Http::assertNothingSent();
});

test('with fewer than 5 local hits it asks the provider once, stores the results and returns them', function () {
    $show = metadataShow('Sousou no Frieren S3');
    Http::fake(['graphql.anilist.co' => Http::response(anilistFixture('season_fuzzy_dates'))]);

    $response = $this->getJson("/shows/{$show->id}/link/search?q=Sousou+no+Frieren");

    $response->assertOk()
        ->assertJsonPath('query', 'Sousou no Frieren')
        ->assertJsonPath('providerSearched', true)
        ->assertJsonPath('providerError', null)
        ->assertJsonCount(5, 'results');

    // Frieren S3's synonyms match the show name's season marker exactly.
    $frieren = Anime::where('title_romaji', 'Sousou no Frieren 3rd Season')->sole();
    $response->assertJsonPath('results.0.id', $frieren->id)
        ->assertJsonPath('results.0.score', 90)
        ->assertJsonPath('results.0.coverUrl', null);

    expect(Anime::count())->toBe(5)
        ->and($frieren->payloads()->count())->toBe(1);

    Http::assertSentCount(1);
});

test('a provider failure only means fewer results', function () {
    $show = metadataShow('Grand Blue S3');
    metadataAnime(['title_romaji' => 'Grand Blue 3rd Season']);
    Http::fake(['graphql.anilist.co' => Http::response('<html>429</html>', 429, ['Retry-After' => '30'])]);

    $this->getJson("/shows/{$show->id}/link/search")
        ->assertOk()
        ->assertJsonPath('providerSearched', true)
        ->assertJsonPath('providerError', fn (?string $error) => str_contains((string) $error, 'rate limited'))
        ->assertJsonCount(1, 'results');

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

test('an unreachable provider only means fewer results', function () {
    $show = metadataShow('Grand Blue S3');
    Http::fake(['graphql.anilist.co' => fn () => throw new ConnectionException('Could not resolve host')]);

    $this->getJson("/shows/{$show->id}/link/search")
        ->assertOk()
        ->assertJsonPath('providerError', 'Could not resolve host')
        ->assertJsonCount(0, 'results');
});

test('results report which shows an anime is already linked to', function () {
    $show = metadataShow('Grand Blue S3');
    $other = metadataShow('Grand Blue Season 3 (Uncut)');
    $anime = metadataAnime(['title_romaji' => 'Grand Blue 3rd Season']);
    seedLocalCandidates(5);
    ShowAnimeLink::create(['show_id' => $other->id, 'anime_id' => $anime->id, 'confidence' => 100, 'source' => 'manual', 'linked_at' => now()]);

    $this->getJson("/shows/{$show->id}/link/search")
        ->assertJsonPath('results.0.linkedShows', [['id' => $other->id, 'name' => 'Grand Blue Season 3 (Uncut)']]);
});

test('linking creates a manual link at 100, flashes, and fetches a missing cover', function () {
    $show = metadataShow('Mushoku Tensei S3');
    $anime = metadataAnime(['title_romaji' => 'Mushoku Tensei III: Isekai Ittara Honki Dasu', 'title_english' => 'Mushoku Tensei: Jobless Reincarnation Season 3']);

    $this->from("/shows/{$show->id}")
        ->post("/shows/{$show->id}/link", ['anime_id' => $anime->id])
        ->assertRedirect("/shows/{$show->id}")
        ->assertSessionHas('success', 'Linked "Mushoku Tensei S3" to "Mushoku Tensei: Jobless Reincarnation Season 3".');

    $link = ShowAnimeLink::where('show_id', $show->id)->sole();

    expect($link->anime_id)->toBe($anime->id)
        ->and($link->source)->toBe(LinkSource::Manual)
        ->and($link->confidence)->toBe(100);

    Queue::assertPushed(FetchAnimeCover::class, fn (FetchAnimeCover $job) => $job->animeId === $anime->id);
});

test('linking replaces an existing link, auto or manual, and never duplicates it', function () {
    $show = metadataShow('Kokoore');
    $wrong = metadataAnime(['title_romaji' => 'Kokoore!']);
    $right = metadataAnime(['title_romaji' => 'Kokoro Connect Reboot']);
    ShowAnimeLink::create(['show_id' => $show->id, 'anime_id' => $wrong->id, 'confidence' => 90, 'source' => 'auto', 'linked_at' => now()->subDay()]);

    $this->post("/shows/{$show->id}/link", ['anime_id' => $right->id])->assertRedirect();
    $this->post("/shows/{$show->id}/link", ['anime_id' => $right->id])->assertRedirect();

    $link = ShowAnimeLink::sole();

    expect($link->anime_id)->toBe($right->id)
        ->and($link->source)->toBe(LinkSource::Manual)
        ->and($link->confidence)->toBe(100)
        ->and(Anime::count())->toBe(2);
});

test('linking validates the anime id', function () {
    $show = metadataShow('Kokoore');

    $this->post("/shows/{$show->id}/link", ['anime_id' => 999999])->assertSessionHasErrors('anime_id');
    $this->post("/shows/{$show->id}/link", [])->assertSessionHasErrors('anime_id');

    expect(ShowAnimeLink::count())->toBe(0);
});

test('unlinking removes the link and leaves both sides intact', function () {
    $show = metadataShow('Kokoore');
    $anime = metadataAnime(['title_romaji' => 'Kokoore!']);
    ShowAnimeLink::create(['show_id' => $show->id, 'anime_id' => $anime->id, 'confidence' => 90, 'source' => 'auto', 'linked_at' => now()]);

    $this->delete("/shows/{$show->id}/link")->assertRedirect()->assertSessionHas('success', 'Unlinked "Kokoore". That match won\'t be made automatically again.');
    $this->delete("/shows/{$show->id}/link")->assertRedirect()->assertSessionHas('success', '"Kokoore" wasn\'t linked.');

    expect(ShowAnimeLink::count())->toBe(0)
        ->and($show->fresh())->not->toBeNull()
        ->and($anime->fresh())->not->toBeNull();
});
