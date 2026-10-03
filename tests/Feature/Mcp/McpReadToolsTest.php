<?php

declare(strict_types=1);

use App\Enums\DispatchStatus;
use App\Enums\RuleState;
use App\Models\Anime;
use App\Models\AnimeAiring;
use App\Models\AnimePayload;
use App\Models\Release;
use App\Models\Show;
use App\Models\ShowAnimeLink;
use App\Models\ShowAnimeRejection;
use App\Models\ShowAnimeSuggestion;
use App\Services\Metadata\AnimeTaxonomyWriter;
use App\Services\Metadata\ProviderTag;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @param  array<string, mixed>  $attributes
 * @param  array<int, array{0: string, 1: int, 2?: bool}>  $tags  [name, rank, spoiler]
 * @param  array<int, string>  $studios
 */
function mcpAnime(string $title, array $attributes = [], array $tags = [], array $studios = []): Anime
{
    $anime = metadataAnime([
        'title_romaji' => $title,
        'format' => 'TV',
        'status' => 'RELEASING',
        'season' => 'FALL',
        'season_year' => 2026,
        'episodes_total' => 12,
        'genres' => [],
        ...$attributes,
    ]);

    AnimePayload::create([
        'anime_id' => $anime->id,
        'provider' => 'anilist',
        'fetched_at' => now(),
        'payload' => [
            'tags' => array_map(fn (array $tag) => ['name' => $tag[0], 'rank' => $tag[1], 'isMediaSpoiler' => $tag[2] ?? false], $tags),
            'studios' => ['nodes' => array_map(fn (string $studio) => ['id' => crc32($studio), 'name' => $studio], $studios)],
        ],
    ]);
    // What the sync writes alongside the payload.
    app(AnimeTaxonomyWriter::class)->replace(
        $anime,
        $anime->genres ?? [],
        array_map(fn (array $tag) => new ProviderTag($tag[0], $tag[1], $tag[2] ?? false), $tags),
        $studios,
    );

    return $anime;
}

function mcpLinked(Anime $anime, string $showName, bool $tracked = false): Show
{
    $show = metadataShow($showName, ['is_tracked' => $tracked, 'rule_state' => $tracked ? RuleState::Synced : RuleState::None]);
    ShowAnimeLink::create(['show_id' => $show->id, 'anime_id' => $anime->id, 'confidence' => 95, 'source' => 'auto', 'linked_at' => now()]);

    return $show;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function mcpRelease(Show $show, ?string $episode, array $overrides = []): Release
{
    static $n = 0;
    $n++;

    return Release::create([
        'show_id' => $show->id, 'guid' => "MCP-{$n}", 'title' => "[SubsPlease] {$show->name} - {$episode} (1080p) [{$n}].mkv",
        'episode' => $episode, 'is_batch' => false, 'resolution' => '1080p', 'link' => "magnet:?xt=urn:btih:MCP{$n}",
        'published_at' => now()->subDays(2)->addMinutes($n), 'first_seen_at' => now()->subDays(2), ...$overrides,
    ]);
}

function mcpAiring(Anime $anime, int $episode, string $airsAt): void
{
    AnimeAiring::create(['anime_id' => $anime->id, 'provider' => 'anilist', 'episode' => $episode, 'airs_at' => $airsAt, 'is_estimate' => false]);
}

const MCP_ANIME_SUMMARY_KEYS = ['id', 'titleRomaji', 'titleEnglish', 'format', 'status', 'season', 'seasonYear', 'episodesTotal', 'genres', 'isAdult', 'isLinked', 'isTracked', 'showId'];

beforeEach(function () {
    Carbon::setTestNow('2026-10-05 12:00:00');
    config(['subtracker.mcp.max_results' => 50]);
});

// ── search_anime ─────────────────────────────────────────────────────────────

test('search_anime: compact rows with exactly the summary keys, no description', function () {
    $anime = mcpAnime('Kusuriya no Hitorigoto', ['title_english' => 'The Apothecary Diaries', 'description' => '<p>A long description</p>', 'genres' => ['Drama', 'Mystery']]);
    $show = mcpLinked($anime, 'Kusuriya no Hitorigoto', tracked: true);

    $result = mcpTool('search_anime', ['query' => 'apothecary']);

    expect($result['total'])->toBe(1)
        ->and(array_keys($result['results'][0]))->toBe(MCP_ANIME_SUMMARY_KEYS)
        ->and($result['results'][0])->toMatchArray(['id' => $anime->id, 'genres' => ['Drama', 'Mystery'], 'isLinked' => true, 'isTracked' => true, 'showId' => $show->id])
        ->and(json_encode($result))->not->toContain('A long description');
});

test('search_anime: format, genre, adult, linked and tracked filters', function () {
    $tvComedy = mcpAnime('TV Comedy', ['genres' => ['Comedy']]);
    $movie = mcpAnime('Movie Comedy', ['format' => 'MOVIE', 'genres' => ['Comedy']]);
    $horror = mcpAnime('TV Horror Comedy', ['genres' => ['Comedy', 'Horror']]);
    $adult = mcpAnime('Adult One', ['genres' => ['Comedy'], 'is_adult' => true]);
    mcpLinked($tvComedy, 'TV Comedy', tracked: true);
    mcpLinked($horror, 'TV Horror Comedy');

    $ids = fn (array $args) => array_column(mcpTool('search_anime', $args)['results'], 'id');

    expect($ids(['format' => ['TV'], 'genres_include' => ['Comedy']]))->toEqualCanonicalizing([$tvComedy->id, $horror->id])
        ->and($ids(['genres_include' => ['Comedy'], 'genres_exclude' => ['Horror']]))->toEqualCanonicalizing([$tvComedy->id, $movie->id])
        ->and($ids(['adult' => 'only']))->toBe([$adult->id])
        ->and($ids(['adult' => 'include']))->toHaveCount(4)
        ->and($ids(['linked' => 'unlinked']))->toBe([$movie->id])
        ->and($ids(['tracked' => 'tracked']))->toBe([$tvComedy->id])
        ->and($ids(['tracked' => 'untracked']))->toEqualCanonicalizing([$movie->id, $horror->id]);
});

test('search_anime: limit is capped by MCP_MAX_RESULTS, offset pages', function () {
    foreach (range(1, 5) as $i) {
        mcpAnime("Series {$i}");
    }
    config(['subtracker.mcp.max_results' => 3]);

    $page = mcpTool('search_anime', ['limit' => 100]);
    expect($page['total'])->toBe(5)->and($page['results'])->toHaveCount(3);

    $next = mcpTool('search_anime', ['limit' => 3, 'offset' => 3]);
    expect(array_column($next['results'], 'titleRomaji'))->toBe(['Series 4', 'Series 5']);
});

// ── get_anime ────────────────────────────────────────────────────────────────

test('get_anime: detail with truncated description, ranked non-spoiler tags, studios, airing and linked show', function () {
    $anime = mcpAnime(
        'Frieren',
        ['description' => '<p>'.str_repeat('Elf mage travels. ', 60).'</p>', 'episodes_aired' => 4, 'genres' => ['Adventure', 'Fantasy']],
        tags: [['Travel', 80], ['Elf', 95], ['Twist Ending', 70, true]],
        studios: ['Madhouse'],
    );
    $show = mcpLinked($anime, 'Sousou no Frieren');
    mcpAiring($anime, 5, '2026-10-06 15:00:00');

    $detail = mcpTool('get_anime', ['anime_id' => $anime->id]);

    expect($detail['descriptionTruncated'])->toBeTrue()
        ->and(mb_strlen(strip_tags($detail['description'])))->toBeLessThanOrEqual(601)
        ->and($detail['tags'])->toBe([['name' => 'Elf', 'rank' => 95], ['name' => 'Travel', 'rank' => 80]])
        ->and($detail['studios'])->toBe(['Madhouse'])
        ->and($detail['episodesAired'])->toBe(4)
        ->and($detail['airing']['next'])->toBe(['episode' => 5, 'airsAt' => '2026-10-06T15:00:00+00:00'])
        ->and($detail['linkedShows'])->toBe([['id' => $show->id, 'name' => 'Sousou no Frieren', 'isTracked' => false, 'linkSource' => 'auto']]);
});

test('get_anime: an unknown id is a tool error', function () {
    expect(mcpToolError('get_anime', ['anime_id' => 999999]))->toContain('No anime with id 999999');
});

// ── list_schedule ────────────────────────────────────────────────────────────

test('list_schedule: defaults to the next 7 days, filters apply, covers are left out', function () {
    $linked = mcpAnime('Linked Show', ['genres' => ['Action']]);
    $other = mcpAnime('Other Show', ['genres' => ['Romance']]);
    $show = mcpLinked($linked, 'Linked Show', tracked: true);
    mcpRelease($show, '03', ['downloaded_at' => now()]);
    mcpAiring($linked, 3, '2026-10-05 10:00:00'); // past: before now
    mcpAiring($linked, 4, '2026-10-06 10:00:00');
    mcpAiring($other, 1, '2026-10-07 10:00:00');
    mcpAiring($other, 2, '2026-10-14 10:00:00'); // past the 7 days

    $week = mcpTool('list_schedule');
    expect(array_map(fn ($a) => [$a['anime']['titleRomaji'], $a['episode']], $week['airings']))->toBe([['Linked Show', 4], ['Other Show', 1]])
        ->and($week['airings'][0]['show'])->toBe(['id' => $show->id, 'name' => 'Linked Show', 'isTracked' => true])
        ->and($week['airings'][0]['anime'])->not->toHaveKey('coverUrl');

    $earlier = mcpTool('list_schedule', ['from' => '2026-10-05T00:00:00Z', 'to' => '2026-10-08T00:00:00Z', 'genres_include' => ['Action']]);
    expect(array_column($earlier['airings'], 'episode'))->toBe([3, 4])
        ->and($earlier['airings'][0]['releaseState'])->toBe('downloaded')
        ->and($earlier['airings'][1]['releaseState'])->toBeNull();
});

test('list_schedule: ranges over 45 days are an error; rows are capped', function () {
    expect(mcpToolError('list_schedule', ['from' => '2026-10-01', 'to' => '2026-12-01']))->toContain('at most 45 days');

    $anime = mcpAnime('Daily');
    foreach (range(1, 5) as $day) {
        mcpAiring($anime, $day, "2026-10-0{$day} 13:00:00");
    }
    config(['subtracker.mcp.max_results' => 2]);

    $capped = mcpTool('list_schedule', ['from' => '2026-10-01', 'to' => '2026-10-10', 'limit' => 50]);
    expect($capped['total'])->toBe(5)->and($capped['airings'])->toHaveCount(2);
});

// ── list_shows / get_show ────────────────────────────────────────────────────

test('list_shows: rows, filters and cap', function () {
    $anime = mcpAnime('Dandadan');
    $tracked = mcpLinked($anime, 'Dandadan', tracked: true);
    mcpRelease($tracked, '01', ['dispatch_status' => DispatchStatus::Sent, 'downloaded_at' => now()]);
    mcpRelease($tracked, '02', ['dispatch_status' => DispatchStatus::Sent]);
    $review = metadataShow('Needs Review');
    ShowAnimeSuggestion::create(['show_id' => $review->id, 'anime_id' => $anime->id, 'score' => 92, 'rule' => 'similarity', 'reason' => 'ambiguous', 'created_at' => now()]);
    metadataShow('Errored', ['rule_state' => RuleState::Error]);

    $all = mcpTool('list_shows');
    expect($all['total'])->toBe(3)
        ->and(collect($all['results'])->firstWhere('name', 'Dandadan'))->toMatchArray([
            'id' => $tracked->id, 'isTracked' => true, 'ruleState' => 'synced', 'queuedCount' => 2, 'downloadedCount' => 1, 'animeId' => $anime->id, 'hasSuggestions' => false,
        ])
        ->and(collect($all['results'])->firstWhere('name', 'Dandadan')['latestEpisode']['episode'])->toBe('02');

    $names = fn (array $args) => array_column(mcpTool('list_shows', $args)['results'], 'name');
    expect($names(['tracked' => 'tracked']))->toBe(['Dandadan'])
        ->and($names(['has_suggestions' => true]))->toBe(['Needs Review'])
        ->and($names(['rule_state' => 'error']))->toBe(['Errored'])
        ->and($names(['query' => 'review']))->toBe(['Needs Review']);

    config(['subtracker.mcp.max_results' => 1]);
    expect(mcpTool('list_shows', ['limit' => 10])['results'])->toHaveCount(1);
});

test('get_show: newest releases first, capped, with dispatch state, link and suggestions', function () {
    $anime = mcpAnime('One Piece');
    $show = mcpLinked($anime, 'One Piece', tracked: true);
    foreach (range(1, 55) as $episode) {
        mcpRelease($show, str_pad((string) $episode, 2, '0', STR_PAD_LEFT));
    }
    $failed = mcpRelease($show, '56', ['dispatch_status' => DispatchStatus::Error, 'dispatch_error' => 'qBittorrent unreachable']);

    $detail = mcpTool('get_show', ['show_id' => $show->id]);

    expect($detail['releasesTotal'])->toBe(56)
        ->and($detail['releases'])->toHaveCount(50)
        ->and($detail['releases'][0])->toMatchArray(['id' => $failed->id, 'episode' => '56', 'dispatchStatus' => 'error', 'dispatchError' => 'qBittorrent unreachable'])
        ->and($detail['animeLink']['anime']['id'])->toBe($anime->id)
        ->and($detail['animeLink']['linkSource'])->toBe('auto')
        ->and($detail['linkSuggestions'])->toBe([]);
});

test('config secrets never reach tool output, even inside stored error text', function () {
    config(['subtracker.qbittorrent.password' => 'hunter2-qbit', 'subtracker.notifications.ntfy_token' => 'tk_ntfysecret']);
    $show = metadataShow('Leaky', ['rule_state' => RuleState::Error, 'rule_error' => 'Login failed for admin:hunter2-qbit']);
    mcpRelease($show, '01', ['dispatch_status' => DispatchStatus::Error, 'dispatch_error' => 'ntfy rejected tk_ntfysecret']);

    $json = json_encode(mcpTool('get_show', ['show_id' => $show->id]));

    expect($json)->not->toContain('hunter2-qbit')
        ->not->toContain('tk_ntfysecret')
        ->toContain('[redacted]');
});

// ── tracked_summary ──────────────────────────────────────────────────────────

test('tracked_summary: aired vs released vs downloaded, waiting, next airing and totals', function () {
    $anime = mcpAnime('Airing Now', ['episodes_aired' => 5]);
    $show = mcpLinked($anime, 'Airing Now', tracked: true);
    mcpRelease($show, '03', ['downloaded_at' => now()]);
    mcpRelease($show, '04');
    mcpAiring($anime, 6, '2026-10-06 01:00:00');
    metadataShow('Tracked Unlinked', ['is_tracked' => true]);
    metadataShow('Not Tracked');

    $summary = mcpTool('tracked_summary');

    expect($summary['totals'])->toBe(['trackedShows' => 2, 'withWaitingEpisodes' => 1, 'withUndownloadedReleases' => 1, 'airingInNext24Hours' => 1])
        ->and($summary['truncated'])->toBeFalse()
        ->and($summary['shows'][0])->toMatchArray([
            'name' => 'Airing Now',
            'episodes' => ['aired' => 5, 'released' => 4.0, 'downloaded' => 3.0],
            'nextAiring' => ['episode' => 6, 'airsAt' => '2026-10-06T01:00:00+00:00'],
            'waitingEpisodes' => 1,
            'undownloadedReleases' => 1,
        ])
        ->and($summary['shows'][1])->toMatchArray(['name' => 'Tracked Unlinked', 'anime' => null, 'waitingEpisodes' => null]);
});

// ── suggest_link ─────────────────────────────────────────────────────────────

test('suggest_link: matcher candidates, suggestions and rejections, without changing anything', function () {
    $right = mcpAnime('Ore dake Level Up na Ken', ['season' => 'FALL', 'season_year' => 2026]);
    $rejected = mcpAnime('Something Else');
    $show = metadataShow('Ore dake Level Up na Ken', ['season' => 'autumn', 'season_year' => 2026]);
    ShowAnimeRejection::create(['show_id' => $show->id, 'anime_id' => $rejected->id, 'rejected_at' => now()]);

    $counts = fn () => [ShowAnimeLink::count(), ShowAnimeSuggestion::count(), ShowAnimeRejection::count()];
    $before = $counts();

    $result = mcpTool('suggest_link', ['show_id' => $show->id]);

    expect($counts())->toBe($before)
        ->and($result['currentLink'])->toBeNull()
        ->and($result['outcome'])->toBe('held')
        ->and($result['candidates'][0])->toMatchArray(['score' => 100, 'rule' => 'exact'])
        ->and($result['candidates'][0]['anime']['id'])->toBe($right->id)
        ->and($result['rejected'][0]['anime']['id'])->toBe($rejected->id);
});

// ── suggest_anime ────────────────────────────────────────────────────────────

function suggestCatalog(): array
{
    $anchor = mcpAnime('Tracked Fantasy', ['genres' => ['Adventure', 'Fantasy']], [['Isekai', 90], ['Magic', 80]], ['Studio Bind']);
    mcpLinked($anchor, 'Tracked Fantasy', tracked: true);

    $close = mcpAnime('Close Match', ['genres' => ['Adventure', 'Fantasy']], [['Isekai', 70], ['Magic', 60]], ['Studio Bind']);
    $trackedToo = mcpAnime('Also Tracked', ['genres' => ['Fantasy']], [['Magic', 50]]);
    mcpLinked($trackedToo, 'Also Tracked', tracked: true);
    $movie = mcpAnime('Fantasy Movie', ['genres' => ['Fantasy'], 'format' => 'MOVIE', 'episodes_total' => null], [['Magic', 80]]);
    $unrelated = mcpAnime('Sports Thing', ['genres' => ['Sports']], [['Basketball', 90]]);

    return compact('anchor', 'close', 'trackedToo', 'movie', 'unrelated');
}

test('suggest_anime: anchors default to tracked shows; tracked candidates are returned and flagged; why is evidence', function () {
    ['anchor' => $anchor, 'close' => $close, 'trackedToo' => $trackedToo, 'unrelated' => $unrelated] = suggestCatalog();

    $result = mcpTool('suggest_anime');
    $byId = collect($result['candidates'])->keyBy('id');

    expect($result['anchorSource'])->toBe('tracked shows')
        ->and(array_column($result['anchors'], 'id'))->toEqualCanonicalizing([$anchor->id, $trackedToo->id])
        ->and($result['candidates'][0]['id'])->toBe($close->id)
        ->and($byId)->not->toHaveKey($unrelated->id)
        ->and($byId[$trackedToo->id]['isTracked'])->toBeTrue()
        ->and($byId[$anchor->id]['isTracked'])->toBeTrue()
        ->and($byId[$close->id]['isTracked'])->toBeFalse()
        ->and($byId[$close->id]['why'])->toBe([
            'sharedGenres' => [
                ['name' => 'Adventure', 'catalogShare' => 0.4, 'weight' => 1], // 1.0 travels as 1 in JSON
                ['name' => 'Fantasy', 'catalogShare' => 0.8, 'weight' => 0.49],
            ],
            'sharedTags' => [
                ['name' => 'Isekai', 'rank' => 70, 'catalogShare' => 0.4, 'weight' => 1.05],
                ['name' => 'Magic', 'rank' => 60, 'catalogShare' => 0.8, 'weight' => 0.4],
            ],
            'sharedStudios' => ['Studio Bind'],
            'anchors' => [$anchor->id, $trackedToo->id],
        ])
        ->and(array_keys($result['candidates'][0]))->toBe([...MCP_ANIME_SUMMARY_KEYS, 'score', 'why']);

    // Rarity over the 5 anime, ln(5 / df) / ln(5 / 2): on 2 anime 1 (Adventure, Isekai), on 4 0.2435 (Fantasy, Magic).
    // First anchor: (1 + 0.2435) genres + (70 × 1 + 60 × 0.2435) / 100 × 1.5 + 2 (studio) + 0.5 (format) = 5.013.
    // Second: 0.2435 (Fantasy) + 50 × 0.2435 / 100 × 1.5 + 0.5 (format) = 0.926.
    expect($byId[$close->id]['score'])->toBe(5.94);
});

test('suggest_anime: include_tracked false, filters and the cap', function () {
    ['close' => $close, 'trackedToo' => $trackedToo, 'movie' => $movie] = suggestCatalog();

    $untracked = array_column(mcpTool('suggest_anime', ['include_tracked' => false])['candidates'], 'id');
    expect($untracked)->toContain($close->id)->not->toContain($trackedToo->id);

    expect(array_column(mcpTool('suggest_anime', ['format' => ['MOVIE']])['candidates'], 'id'))->toBe([$movie->id]);

    config(['subtracker.mcp.max_results' => 1]);
    expect(mcpTool('suggest_anime', ['limit' => 30])['candidates'])->toHaveCount(1);
});

test('suggest_anime: like_anime_ids replace the tracked anchors and are not suggested themselves', function () {
    ['unrelated' => $unrelated, 'close' => $close] = suggestCatalog();
    $hoops = mcpAnime('More Hoops', ['genres' => ['Sports']], [['Basketball', 80]]);

    $result = mcpTool('suggest_anime', ['like_anime_ids' => [$unrelated->id]]);

    expect($result['anchorSource'])->toBe('like_anime_ids')
        ->and(array_column($result['candidates'], 'id'))->toBe([$hoops->id])
        ->and($result['candidates'][0]['why']['sharedTags'])->toBe([['name' => 'Basketball', 'rank' => 80, 'catalogShare' => 0.333, 'weight' => 1.2]])
        ->and(array_column($result['candidates'], 'id'))->not->toContain($close->id);
});

test('suggest_anime: genres and tags named in the query act as an anchor', function () {
    ['unrelated' => $unrelated] = suggestCatalog();
    Show::query()->update(['is_tracked' => false]);

    $result = mcpTool('suggest_anime', ['query' => 'something about basketball, maybe sports']);

    expect($result['queryTerms'])->toBe(['genres' => ['Sports'], 'tags' => ['Basketball'], 'notInCatalog' => [], 'unrecognised' => []])
        ->and($result['anchors'])->toBe([])
        ->and($result['candidates'][0]['id'])->toBe($unrelated->id)
        ->and($result['candidates'][0]['why']['anchors'])->toBe([]);
});

test('suggest_anime: a rare tag outweighs a common one at the same rank, and a tag on every anime adds nothing', function () {
    $anchor = mcpAnime('Anchor', tags: [['Common', 80], ['Rare', 80], ['Everywhere', 80]]);
    $common = mcpAnime('Shares Common', tags: [['Common', 80], ['Everywhere', 80]]);
    $rare = mcpAnime('Shares Rare', tags: [['Rare', 80], ['Everywhere', 80]]);
    foreach (range(1, 6) as $i) {
        mcpAnime("Filler {$i}", tags: [['Common', 50], ['Everywhere', 50]]);
    }

    $candidates = mcpTool('suggest_anime', ['like_anime_ids' => [$anchor->id]])['candidates'];
    $byId = collect($candidates)->keyBy('id');

    // 9 anime with tags: Rare on 2 → rarity 1; Common on 8 → ln(9/8) / ln(4.5) ≈ 0.078; Everywhere on 9 → 0.
    expect($candidates[0]['id'])->toBe($rare->id)
        ->and($byId[$rare->id]['why']['sharedTags'])->toBe([['name' => 'Rare', 'rank' => 80, 'catalogShare' => 0.222, 'weight' => 1.2]])
        ->and($byId[$common->id]['why']['sharedTags'])->toBe([['name' => 'Common', 'rank' => 80, 'catalogShare' => 0.889, 'weight' => 0.09]])
        ->and($byId[$rare->id]['score'])->toBe(1.7) // 1.2 + 0.5 same format
        ->and($byId[$common->id]['score'])->toBe(0.59);
});

test('suggest_anime: a single shared genre is cancelled out by wildly different episode counts', function () {
    $series = mcpAnime('Long Series', ['genres' => ['Fantasy'], 'episodes_total' => 24]);
    mcpAnime('Elsewhere', ['genres' => ['Sports']]); // so Fantasy isn't on every anime (rarity 0)
    mcpAnime('Short Film', ['genres' => ['Fantasy'], 'format' => 'MOVIE', 'episodes_total' => 1]);
    $sameLength = mcpAnime('Same Length', ['genres' => ['Fantasy'], 'episodes_total' => 13]);

    expect(array_column(mcpTool('suggest_anime', ['like_anime_ids' => [$series->id]])['candidates'], 'id'))->toBe([$sameLength->id]);
});

test('suggest_anime: with nothing to compare against it says so, and what the query contained', function () {
    mcpAnime('Lonely');
    DB::table('tags')->insert(['name' => 'Time Travel']); // known (from the vocabulary), on no anime

    $error = mcpToolError('suggest_anime', ['query' => 'cozy time travel']);

    expect($error)->toContain('Nothing to compare against')
        ->toContain('"notInCatalog":["Time Travel"]')
        ->toContain('"unrecognised":["cozy"]');
});

test('suggest_anime: "time travel" is a known tag that matches nothing here, never the shorter Travel', function () {
    $anchor = mcpAnime('Anchor', ['genres' => ['Romance']], [['Travel', 90]]);
    mcpLinked($anchor, 'Anchor', tracked: true);
    $traveller = mcpAnime('Road Trip', [], [['Travel', 90]]);
    $romance = mcpAnime('Love Story', ['genres' => ['Romance']]);
    mcpAnime('Filler', ['genres' => ['Sports']], [['Basketball', 50]]); // so Travel isn't on every tagged anime (rarity 0)
    DB::table('tags')->insert(['name' => 'Time Travel']);

    $result = mcpTool('suggest_anime', ['query' => 'something cozy with time travel and romance', 'include_tracked' => false]);

    expect($result['queryTerms'])->toBe([
        'genres' => ['Romance'],
        'tags' => [],
        'notInCatalog' => ['Time Travel'],
        'unrecognised' => ['cozy'],
    ]);
    // Road Trip is found through the tracked anchor's Travel tag only: 1.5 × 90 / 100 × rarity 1, + 0.5 format.
    // Had the query matched Travel, it would add another 1.35.
    $byId = collect($result['candidates'])->keyBy('id');
    expect($byId[$romance->id]['why']['sharedGenres'][0]['name'])->toBe('Romance')
        ->and($byId[$traveller->id]['score'])->toBe(1.85)
        ->and($byId[$traveller->id]['why']['anchors'])->toBe([$anchor->id]);
});

// ── Resources ────────────────────────────────────────────────────────────────

test('resources/list offers tracked shows and this week, templates offer anime and show by id', function () {
    expect(array_column(mcpRequest('resources/list')->assertOk()->json('result.resources'), 'uri'))
        ->toEqualCanonicalizing(['torii://tracked', 'torii://schedule/this-week'])
        ->and(array_column(mcpRequest('resources/templates/list')->assertOk()->json('result.resourceTemplates'), 'uriTemplate'))
        ->toEqualCanonicalizing(['torii://anime/{id}', 'torii://show/{id}']);
});

test('resources/read returns the same payload as the matching tool', function () {
    $anime = mcpAnime('Read Me', ['genres' => ['Comedy']]);
    $show = mcpLinked($anime, 'Read Me', tracked: true);
    mcpAiring($anime, 2, '2026-10-07 12:00:00');
    $read = fn (string $uri) => json_decode(mcpRequest('resources/read', ['uri' => $uri])->assertOk()->json('result.contents.0.text'), true);

    expect($read("torii://anime/{$anime->id}"))->toBe(mcpTool('get_anime', ['anime_id' => $anime->id]))
        ->and($read("torii://show/{$show->id}"))->toBe(mcpTool('get_show', ['show_id' => $show->id]))
        ->and($read('torii://tracked'))->toBe(mcpTool('tracked_summary'))
        ->and(array_column($read('torii://schedule/this-week')['airings'], 'episode'))->toBe([2]);
});

// ── list_tags ────────────────────────────────────────────────────────────────

function vocabularyCatalog(): void
{
    mcpAnime('Isekai One', ['genres' => ['Fantasy']], [['Isekai', 90], ['Twist', 80, true]]);
    mcpAnime('Isekai Two', ['genres' => ['Fantasy', 'Comedy']], [['Isekai', 70], ['Twist', 60]]);
    mcpAnime('Cook Off', ['genres' => ['Comedy']], [['Cooking', 70]]); // so Isekai isn't on every tagged anime (rarity 0)
    // The vocabulary sync's extra knowledge, and tags no anime here has.
    DB::table('tags')->where('name', 'Isekai')->update(['category' => 'Theme-Fantasy', 'description' => 'Transported to another world.']);
    DB::table('tags')->insert([
        ['name' => 'Time Manipulation', 'category' => 'Theme-Fantasy', 'description' => 'Time travel and the like.', 'is_adult' => false],
        ['name' => 'Nudity', 'category' => 'Sexual Content', 'description' => 'No clothing.', 'is_adult' => true],
    ]);
    DB::table('genres')->insert(['name' => 'Mecha']);
}

test('list_tags: genres and tags with category and catalog usage, most used first, no descriptions', function () {
    vocabularyCatalog();

    $result = mcpTool('list_tags');
    $byName = collect($result['results'])->keyBy('name');

    expect($result['total'])->toBe(8)
        ->and(array_column($result['results'], 'name'))->toBe(['Comedy', 'Fantasy', 'Isekai', 'Cooking', 'Twist', 'Mecha', 'Nudity', 'Time Manipulation'])
        ->and($result['results'][0])->toBe(['name' => 'Comedy', 'kind' => 'genre', 'category' => 'Genre', 'usedInCatalog' => 2])
        ->and($byName['Isekai'])->toBe(['name' => 'Isekai', 'kind' => 'tag', 'category' => 'Theme-Fantasy', 'usedInCatalog' => 2, 'isAdult' => false])
        // Spoiler uses never match, so they don't count.
        ->and($byName['Twist']['usedInCatalog'])->toBe(1)
        ->and($byName['Time Manipulation']['usedInCatalog'])->toBe(0)
        ->and($byName['Mecha']['usedInCatalog'])->toBe(0)
        ->and($byName['Nudity']['isAdult'])->toBeTrue()
        ->and(json_encode($result))->not->toContain('Transported')
        ->and($result['categories'])->toBe([
            ['category' => '(none)', 'count' => 2],
            ['category' => 'Genre', 'count' => 3],
            ['category' => 'Sexual Content', 'count' => 1],
            ['category' => 'Theme-Fantasy', 'count' => 2],
        ]);
});

test('list_tags: category and query filters, and the cap', function () {
    vocabularyCatalog();

    $names = fn (array $args) => array_column(mcpTool('list_tags', $args)['results'], 'name');

    expect($names(['category' => 'theme-fantasy']))->toBe(['Isekai', 'Time Manipulation'])
        ->and($names(['category' => 'Genre', 'query' => 'me']))->toBe(['Comedy', 'Mecha'])
        ->and($names(['query' => 'TIME']))->toBe(['Time Manipulation']);

    config(['subtracker.mcp.max_results' => 3]);
    $page = mcpTool('list_tags', ['limit' => 100]);
    expect($page['results'])->toHaveCount(3)->and($page['total'])->toBe(8);
});

test('list_tags: describe returns the named entries with descriptions, and unknown names', function () {
    vocabularyCatalog();

    expect(mcpTool('list_tags', ['describe' => ['isekai', 'Time Travel', 'Comedy']]))->toBe([
        'results' => [
            ['name' => 'Comedy', 'kind' => 'genre', 'category' => 'Genre', 'usedInCatalog' => 2, 'description' => null],
            ['name' => 'Isekai', 'kind' => 'tag', 'category' => 'Theme-Fantasy', 'usedInCatalog' => 2, 'isAdult' => false, 'description' => 'Transported to another world.'],
        ],
        'unknown' => ['Time Travel'],
    ]);
});

// ── suggest_anime tags[] ─────────────────────────────────────────────────────

test('suggest_anime: exact names in tags are an anchor; known-but-unused and unknown names are reported back', function () {
    vocabularyCatalog();
    $mecha = mcpAnime('Robots Everywhere', ['genres' => ['Sci-Fi']], [['Real Robot', 80]]);
    $isekai = Anime::where('title_romaji', 'Isekai One')->first();

    $result = mcpTool('suggest_anime', ['tags' => ['ISEKAI', 'comedy', 'Time Manipulation', 'Time Travel']]);

    expect($result['requestedTags'])->toBe([
        'genres' => ['Comedy'],
        'tags' => ['Isekai'],
        'notInCatalog' => ['Time Manipulation'],
        'unknown' => ['Time Travel'],
    ])->and($result['queryTerms'])->toBeNull()
        ->and($result['anchors'])->toBe([])
        ->and(array_column($result['candidates'], 'id'))->toContain($isekai->id)->not->toContain($mecha->id)
        ->and(collect($result['candidates'])->firstWhere('id', $isekai->id)['why']['sharedTags'][0])->toMatchArray(['name' => 'Isekai', 'rank' => 90]);
});

test('suggest_anime: tags and query combine into one requested anchor, each reported separately', function () {
    vocabularyCatalog();

    $result = mcpTool('suggest_anime', ['tags' => ['Isekai'], 'query' => 'cozy comedy']);

    expect($result['requestedTags'])->toBe(['genres' => [], 'tags' => ['Isekai'], 'notInCatalog' => [], 'unknown' => []])
        ->and($result['queryTerms'])->toBe(['genres' => [], 'tags' => [], 'notInCatalog' => [], 'unrecognised' => ['cozy comedy']])
        ->and($result['candidates'])->not->toBe([]);
});

test('suggest_anime: with only unknown tags it says so, listing them', function () {
    vocabularyCatalog();

    expect(mcpToolError('suggest_anime', ['tags' => ['Time Travel']]))->toContain('Nothing to compare against')
        ->toContain('"unknown":["Time Travel"]');
});

test('suggest_anime: tags must be a list of names', function () {
    expect(mcpToolError('suggest_anime', ['tags' => 'Isekai']))->toContain('tags');
});
