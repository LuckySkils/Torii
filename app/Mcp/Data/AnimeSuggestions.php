<?php

declare(strict_types=1);

namespace App\Mcp\Data;

use App\Models\Anime;
use App\Models\Show;
use App\Services\Metadata\AnimeFilters;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * suggest_anime: retrieves and explains, never ranks by taste. Candidates are
 * scored in SQL against anchors (the given anime, or what's tracked) by shared
 * genres weighted by how rare they are in Torii's catalog, shared tags weighted
 * by AniList's relevance ranks and by rarity, shared studios, a small bonus for the same format
 * and a small penalty for wildly different episode counts. Every number in `why`
 * is evidence the model can check. No embeddings, no external calls.
 *
 * Genres, tags and studios come from `anime_genre`/`anime_tag`/`anime_studio`
 * (written by the sync), so scoring is joins and rarity a count over an indexed
 * column; no JSON is unpacked here (§15).
 */
final class AnimeSuggestions
{
    /** The pseudo-anchor id for the genres/tags requested in tags[] and the free-text query. */
    private const QUERY_ANCHOR = 0;

    /** A shared tag at rank 100 on both sides, as rare as a shareable tag can be. */
    private const TAG_WEIGHT = 1.5;

    /** A shared genre, as rare as a shareable genre can be. */
    private const GENRE_WEIGHT = 1.0;

    /**
     * @param  array<int, int>  $likeAnimeIds
     * @param  array<int, string>  $tagNames  exact genre/tag names (tags[])
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when there is nothing to compare against
     */
    public function suggest(?string $query, array $likeAnimeIds, AnimeFilters $filters, bool $includeTracked, int $limit, array $tagNames = []): array
    {
        $explicitAnchors = $likeAnimeIds !== [];
        $anchorIds = $explicitAnchors ? $likeAnimeIds : $this->trackedAnimeIds();
        $anchors = $this->features($anchorIds);
        [$requestedAnchor, $requestedTags, $queryTerms] = $this->requestedAnchor($tagNames, $query);

        if ($requestedAnchor !== null) {
            $anchors[] = $requestedAnchor;
        }

        if ($anchors === []) {
            $json = fn (array $value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            throw new InvalidArgumentException(
                'Nothing to compare against: no like_anime_ids were given, no tracked show is linked to an anime, and neither tags nor query name a genre or tag that any anime in the catalog has.'
                .($requestedTags === null ? '' : ' Requested tags: '.$json($requestedTags).'.')
                .($queryTerms === null ? '' : ' Query terms: '.$json($queryTerms).'.')
            );
        }

        // Each anchor genre and tag carries its rarity, so SQL scoring and `why` weigh it the same way.
        $rarity = $this->rarity('anime_tag', 'tag_id', array_merge([], ...array_map(fn (array $anchor) => array_column($anchor['tags'], 'id'), $anchors)));
        $genreRarity = $this->rarity('anime_genre', 'genre_id', array_merge([], ...array_map(fn (array $anchor) => array_column($anchor['genres'], 'id'), $anchors)));
        $anchors = array_map(fn (array $anchor) => [
            ...$anchor,
            'genres' => array_map(fn (array $genre) => [...$genre, 'idf' => $genreRarity[$genre['id']]['idf'] ?? 0.0], $anchor['genres']),
            'tags' => array_map(fn (array $tag) => [...$tag, 'idf' => $rarity[$tag['id']]['idf'] ?? 0.0], $anchor['tags']),
        ], $anchors);

        // Filters and the tracked switch narrow the candidate pool; explicit anchors aren't their own suggestions.
        $candidateQuery = Anime::query();
        $filters->apply($candidateQuery);
        $candidateQuery
            ->when(! $includeTracked, fn ($q) => $q->whereDoesntHave('links.show', fn ($show) => $show->where('is_tracked', true)))
            ->when($explicitAnchors, fn ($q) => $q->whereKeyNot($likeAnimeIds));
        $candidateIds = $candidateQuery->pluck('id')->all();

        $scored = $candidateIds === [] ? [] : $this->score($anchors, $candidateIds, $limit);

        $byId = Anime::query()
            ->whereKey(array_column($scored, 'id'))
            ->select(AnimeData::SUMMARY_COLUMNS)
            ->with(AnimeData::summaryRelations())
            ->get()
            ->keyBy('id');
        $candidateFeatures = collect($this->features(array_column($scored, 'id')))->keyBy('id');

        return [
            'anchors' => Anime::query()->whereKey($anchorIds)->get(['id', 'title_romaji', 'title_english'])
                ->map(fn (Anime $anime) => ['id' => $anime->id, 'title' => $anime->title_english ?? $anime->title_romaji])
                ->values()->all(),
            'anchorSource' => $explicitAnchors ? 'like_anime_ids' : 'tracked shows',
            'requestedTags' => $requestedTags,
            'queryTerms' => $queryTerms,
            'candidates' => collect($scored)
                ->filter(fn (array $row) => $byId->has($row['id']))
                ->map(fn (array $row) => [
                    ...AnimeData::summary($byId[$row['id']]),
                    'score' => $row['score'],
                    'why' => $this->why($candidateFeatures->get($row['id']), $anchors, $genreRarity, $rarity, $row['anchors']),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * One query: every candidate × anchor pair that shares a genre, tag or studio,
     * scored and summed per candidate. Anchors travel as Postgres arrays.
     *
     * @param  array<int, array<string, mixed>>  $anchors
     * @param  array<int, int>  $candidateIds
     * @return array<int, array{id: int, score: float, anchors: array<int, int>}>
     */
    private function score(array $anchors, array $candidateIds, int $limit): array
    {
        $genres = $tags = $studios = [];

        foreach ($anchors as $anchor) {
            foreach ($anchor['genres'] as $genre) {
                $genres[] = [$anchor['id'], $genre['id'], $genre['idf']];
            }
            foreach ($anchor['tags'] as $tag) {
                $tags[] = [$anchor['id'], $tag['id'], $tag['rank'], $tag['idf']];
            }
            foreach ($anchor['studios'] as $studio) {
                $studios[] = [$anchor['id'], $studio['id']];
            }
        }

        $rows = DB::select(<<<'SQL'
            WITH anchor AS (
                SELECT * FROM unnest(CAST(:anchor_ids AS int[]), CAST(:anchor_formats AS text[]), CAST(:anchor_episodes AS int[]))
                    AS a(id, format, episodes)
            ),
            anchor_genre AS (
                SELECT * FROM unnest(CAST(:genre_anchors AS int[]), CAST(:genre_ids AS int[]), CAST(:genre_idfs AS numeric[]))
                    AS g(anchor_id, genre_id, idf)
            ),
            anchor_tag AS (
                SELECT * FROM unnest(CAST(:tag_anchors AS int[]), CAST(:tag_ids AS int[]), CAST(:tag_ranks AS int[]), CAST(:tag_idfs AS numeric[]))
                    AS t(anchor_id, tag_id, rank, idf)
            ),
            anchor_studio AS (
                SELECT * FROM unnest(CAST(:studio_anchors AS int[]), CAST(:studio_ids AS int[])) AS s(anchor_id, studio_id)
            ),
            cand AS (
                SELECT id, format, episodes_total FROM anime WHERE id = ANY(CAST(:candidates AS int[]))
            ),
            genre_pairs AS (
                SELECT cg.anime_id AS cand_id, g.anchor_id, sum(g.idf) AS shared
                FROM cand c
                JOIN anime_genre cg ON cg.anime_id = c.id
                JOIN anchor_genre g ON g.genre_id = cg.genre_id
                GROUP BY 1, 2
            ),
            tag_pairs AS (
                SELECT ct.anime_id AS cand_id, t.anchor_id, sum(LEAST(ct.rank, t.rank) * t.idf) AS overlap
                FROM cand c
                JOIN anime_tag ct ON ct.anime_id = c.id AND NOT ct.is_spoiler
                JOIN anchor_tag t ON t.tag_id = ct.tag_id
                GROUP BY 1, 2
            ),
            studio_pairs AS (
                SELECT cs.anime_id AS cand_id, s.anchor_id, count(*) AS shared
                FROM cand c
                JOIN anime_studio cs ON cs.anime_id = c.id
                JOIN anchor_studio s ON s.studio_id = cs.studio_id
                GROUP BY 1, 2
            ),
            pairs AS (
                SELECT p.cand_id, p.anchor_id,
                    COALESCE(gp.shared, 0) AS shared_genres,
                    COALESCE(tp.overlap, 0) AS tag_overlap,
                    COALESCE(sp.shared, 0) AS shared_studios,
                    (c.format IS NOT NULL AND c.format = a.format) AS same_format,
                    (c.episodes_total IS NOT NULL AND a.episodes IS NOT NULL
                        AND GREATEST(c.episodes_total, a.episodes) > 4 * GREATEST(LEAST(c.episodes_total, a.episodes), 1)) AS wild_episodes
                FROM (
                    SELECT cand_id, anchor_id FROM genre_pairs
                    UNION SELECT cand_id, anchor_id FROM tag_pairs
                    UNION SELECT cand_id, anchor_id FROM studio_pairs
                ) p
                JOIN cand c ON c.id = p.cand_id
                JOIN anchor a ON a.id = p.anchor_id
                LEFT JOIN genre_pairs gp ON gp.cand_id = p.cand_id AND gp.anchor_id = p.anchor_id
                LEFT JOIN tag_pairs tp ON tp.cand_id = p.cand_id AND tp.anchor_id = p.anchor_id
                LEFT JOIN studio_pairs sp ON sp.cand_id = p.cand_id AND sp.anchor_id = p.anchor_id
                WHERE p.cand_id <> p.anchor_id
            ),
            scored AS (
                SELECT cand_id, anchor_id,
                    shared_genres * :genre_weight
                    + tag_overlap / 100.0 * :tag_weight
                    + shared_studios * 2.0
                    + CASE WHEN same_format THEN 0.5 ELSE 0 END
                    - CASE WHEN wild_episodes THEN 1.0 ELSE 0 END AS pair_score
                FROM pairs
                WHERE shared_genres + tag_overlap + shared_studios > 0
            )
            SELECT cand_id,
                   ROUND(CAST(sum(pair_score) AS numeric), 2) AS score,
                   jsonb_agg(anchor_id ORDER BY pair_score DESC) FILTER (WHERE anchor_id <> 0) AS anchors
            FROM scored
            GROUP BY cand_id
            HAVING sum(pair_score) > 0
            ORDER BY score DESC, cand_id
            LIMIT :limit
            SQL, [
            'anchor_ids' => self::pgArray(array_column($anchors, 'id')),
            'anchor_formats' => self::pgArray(array_column($anchors, 'format')),
            'anchor_episodes' => self::pgArray(array_column($anchors, 'episodes')),
            'genre_anchors' => self::pgArray(array_column($genres, 0)),
            'genre_ids' => self::pgArray(array_column($genres, 1)),
            'genre_idfs' => self::pgArray(array_column($genres, 2)),
            'tag_anchors' => self::pgArray(array_column($tags, 0)),
            'tag_ids' => self::pgArray(array_column($tags, 1)),
            'tag_ranks' => self::pgArray(array_column($tags, 2)),
            'tag_idfs' => self::pgArray(array_column($tags, 3)),
            'studio_anchors' => self::pgArray(array_column($studios, 0)),
            'studio_ids' => self::pgArray(array_column($studios, 1)),
            'candidates' => self::pgArray(array_values($candidateIds)),
            'limit' => $limit,
            'tag_weight' => self::TAG_WEIGHT,
            'genre_weight' => self::GENRE_WEIGHT,
        ]);

        return array_map(fn (object $row) => [
            'id' => (int) $row->cand_id,
            'score' => (float) $row->score,
            'anchors' => array_slice(json_decode((string) ($row->anchors ?? '[]'), true) ?: [], 0, 5),
        ], $rows);
    }

    /**
     * The concrete overlap behind a score. Each shared genre's and tag's weight is
     * exactly what it added to the score, summed over every anchor that has it.
     *
     * @param  array<string, mixed>|null  $candidate
     * @param  array<int, array<string, mixed>>  $anchors  genres and tags carrying their idf
     * @param  array<int, array{idf: float, share: float}>  $genreRarity  by genre id
     * @param  array<int, array{idf: float, share: float}>  $rarity  by tag id
     * @param  array<int, int>  $anchorIds  contributing anchors, strongest first
     * @return array<string, mixed>
     */
    private function why(?array $candidate, array $anchors, array $genreRarity, array $rarity, array $anchorIds): array
    {
        if ($candidate === null) {
            return ['sharedGenres' => [], 'sharedTags' => [], 'sharedStudios' => [], 'anchors' => $anchorIds];
        }

        $others = array_values(array_filter($anchors, fn (array $anchor) => $anchor['id'] !== $candidate['id']));
        $anchorStudios = array_unique(array_merge([], ...array_map(fn (array $a) => array_column($a['studios'], 'id'), $others)));

        $sharedGenres = [];
        foreach ($candidate['genres'] as $genre) {
            $weight = 0.0;
            foreach ($others as $anchor) {
                foreach ($anchor['genres'] as $anchorGenre) {
                    if ($anchorGenre['id'] === $genre['id']) {
                        $weight += self::GENRE_WEIGHT * $anchorGenre['idf'];
                    }
                }
            }

            if ($weight > 0) {
                $sharedGenres[] = [
                    'name' => $genre['name'],
                    'catalogShare' => round($genreRarity[$genre['id']]['share'] ?? 0.0, 3),
                    'weight' => round($weight, 2),
                ];
            }
        }
        usort($sharedGenres, fn (array $a, array $b) => $b['weight'] <=> $a['weight']);

        $sharedTags = [];
        foreach ($candidate['tags'] as $tag) {
            $weight = 0.0;
            foreach ($others as $anchor) {
                foreach ($anchor['tags'] as $anchorTag) {
                    if ($anchorTag['id'] === $tag['id']) {
                        $weight += self::TAG_WEIGHT * min($tag['rank'], $anchorTag['rank']) / 100 * $anchorTag['idf'];
                    }
                }
            }

            if ($weight > 0) {
                $sharedTags[] = [
                    'name' => $tag['name'],
                    'rank' => $tag['rank'],
                    'catalogShare' => round($rarity[$tag['id']]['share'] ?? 0.0, 3),
                    'weight' => round($weight, 2),
                ];
            }
        }
        usort($sharedTags, fn (array $a, array $b) => $b['weight'] <=> $a['weight']);

        return [
            // What each shared genre added to the score: its rarity, most weight first.
            'sharedGenres' => $sharedGenres,
            // What each shared tag added to the score: rank × rarity, most weight first.
            'sharedTags' => array_slice($sharedTags, 0, 8),
            'sharedStudios' => array_values(array_column(
                array_filter($candidate['studios'], fn (array $studio) => in_array($studio['id'], $anchorStudios, true)),
                'name',
            )),
            'anchors' => $anchorIds,
        ];
    }

    /**
     * How rare each genre or tag is in the catalog (every anime with one, whatever
     * the filters): idf = ln(N / df) / ln(N / 2), clamped to 0–1. One only two
     * anime share (the rarest a shared one can be) is 1, one every anime has is 0;
     * with N = 329, a tag on 2% is ~0.77 and one on 60% ~0.10. One indexed count.
     *
     * @param  'anime_tag'|'anime_genre'  $pivot
     * @param  'tag_id'|'genre_id'  $column
     * @param  array<int, int>  $ids
     * @return array<int, array{idf: float, share: float}> by id; share = df / N
     */
    private function rarity(string $pivot, string $column, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = DB::select(<<<SQL
            SELECT {$column} AS id, count(*) AS df, (SELECT count(DISTINCT anime_id) FROM {$pivot}) AS n
            FROM {$pivot}
            WHERE {$column} = ANY(CAST(:ids AS int[]))
            GROUP BY {$column}
            SQL, ['ids' => self::pgArray(array_values(array_unique($ids)))]);

        $rarity = [];

        foreach ($rows as $row) {
            $n = (int) $row->n;
            $df = max(1, (int) $row->df);
            $rarity[(int) $row->id] = [
                'idf' => max(0.0, min(1.0, log($n / $df) / log(max($n, 3) / 2))),
                'share' => $df / $n,
            ];
        }

        return $rarity;
    }

    /**
     * Genres with ids, non-spoiler tags (most relevant first) with ids and ranks,
     * studios with ids, format and episode count.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array{id: int, genres: array<int, array{id: int, name: string}>, tags: array<int, array{id: int, name: string, rank: int}>, studios: array<int, array{id: int, name: string}>, format: string|null, episodes: int|null}>
     */
    private function features(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $genres = DB::table('anime_genre')
            ->join('genres', 'genres.id', '=', 'anime_genre.genre_id')
            ->whereIn('anime_genre.anime_id', $ids)
            ->orderBy('genres.name')
            ->get(['anime_genre.anime_id', 'genres.id', 'genres.name'])
            ->groupBy('anime_id');

        $tags = DB::table('anime_tag')
            ->join('tags', 'tags.id', '=', 'anime_tag.tag_id')
            ->whereIn('anime_tag.anime_id', $ids)
            ->where('anime_tag.is_spoiler', false)
            ->orderByDesc('anime_tag.rank')
            ->orderBy('tags.name')
            ->get(['anime_tag.anime_id', 'tags.id', 'tags.name', 'anime_tag.rank'])
            ->groupBy('anime_id');

        $studios = DB::table('anime_studio')
            ->join('studios', 'studios.id', '=', 'anime_studio.studio_id')
            ->whereIn('anime_studio.anime_id', $ids)
            ->orderBy('studios.name')
            ->get(['anime_studio.anime_id', 'studios.id', 'studios.name'])
            ->groupBy('anime_id');

        return DB::table('anime')
            ->whereIn('id', $ids)
            ->get(['id', 'format', 'episodes_total'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'genres' => ($genres->get($row->id) ?? collect())
                    ->map(fn (object $genre) => ['id' => (int) $genre->id, 'name' => $genre->name])
                    ->values()->all(),
                'tags' => ($tags->get($row->id) ?? collect())
                    ->map(fn (object $tag) => ['id' => (int) $tag->id, 'name' => $tag->name, 'rank' => (int) $tag->rank])
                    ->values()->all(),
                'studios' => ($studios->get($row->id) ?? collect())
                    ->map(fn (object $studio) => ['id' => (int) $studio->id, 'name' => $studio->name])
                    ->values()->all(),
                'format' => $row->format,
                'episodes' => $row->episodes_total === null ? null : (int) $row->episodes_total,
            ])
            ->values()
            ->all();
    }

    /**
     * The genres and tags requested by name (tags[], exact) and in the free-text
     * query, together as one more anchor (each tag as if ranked 100), plus what the
     * response reports about each source: what was applied, what is a known
     * genre/tag but on no anime here (so it can't match anything), and what names
     * nothing known (unknown names in tags[], unrecognised phrases in the query).
     * The anchor is null when nothing applicable was requested.
     *
     * @param  array<int, string>  $tagNames
     * @return array{0: array<string, mixed>|null, 1: array<string, array<int, string>>|null, 2: array<string, array<int, string>>|null}
     */
    private function requestedAnchor(array $tagNames, ?string $query): array
    {
        $matcher = app(QueryTermMatcher::class);
        $named = $tagNames === [] ? null : $matcher->lookup($tagNames);
        $free = trim((string) $query) === '' ? null : $matcher->match((string) $query);

        $all = fn (string $kind) => collect([...($named[$kind] ?? []), ...($free[$kind] ?? [])])->unique('id')->values()->all();
        $used = fn (array $rows, string $pivot, string $column) => DB::table($pivot)
            ->whereIn($column, array_column($rows, 'id'))
            ->when($pivot === 'anime_tag', fn ($q) => $q->where('is_spoiler', false))
            ->distinct()
            ->pluck($column)
            ->map(fn ($id) => (int) $id)
            ->all();
        $usedGenres = $used($all('genres'), 'anime_genre', 'genre_id');
        $usedTags = $used($all('tags'), 'anime_tag', 'tag_id');

        $applicable = fn (array $rows, array $usedIds) => array_values(array_filter($rows, fn (array $row) => in_array($row['id'], $usedIds, true)));
        $report = fn (array $found) => [
            'genres' => array_column($applicable($found['genres'], $usedGenres), 'name'),
            'tags' => array_column($applicable($found['tags'], $usedTags), 'name'),
            'notInCatalog' => array_column([
                ...array_filter($found['genres'], fn (array $genre) => ! in_array($genre['id'], $usedGenres, true)),
                ...array_filter($found['tags'], fn (array $tag) => ! in_array($tag['id'], $usedTags, true)),
            ], 'name'),
        ];

        $genres = $applicable($all('genres'), $usedGenres);
        $tags = $applicable($all('tags'), $usedTags);

        $anchor = $genres === [] && $tags === [] ? null : [
            'id' => self::QUERY_ANCHOR,
            'genres' => $genres,
            'tags' => array_map(fn (array $tag) => [...$tag, 'rank' => 100], $tags),
            'studios' => [],
            'format' => null,
            'episodes' => null,
        ];

        return [
            $anchor,
            $named === null ? null : [...$report($named), 'unknown' => $named['unknown']],
            $free === null ? null : [...$report($free), 'unrecognised' => $free['unrecognised']],
        ];
    }

    /**
     * @return array<int, int>
     */
    private function trackedAnimeIds(): array
    {
        return Show::query()
            ->where('is_tracked', true)
            ->whereHas('animeLink')
            ->with('animeLink:id,show_id,anime_id')
            ->get(['id'])
            ->map(fn (Show $show) => $show->animeLink->anime_id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * A Postgres array literal for a bound parameter: `{1,NULL,"Slice of Life"}`.
     * Floats keep their shortest exact form, as JSON would carry them.
     *
     * @param  array<int, int|float|string|null>  $values
     */
    private static function pgArray(array $values): string
    {
        return '{'.implode(',', array_map(fn ($value) => match (true) {
            $value === null => 'NULL',
            is_int($value) => (string) $value,
            is_float($value) => json_encode($value, JSON_THROW_ON_ERROR),
            default => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value).'"',
        }, $values)).'}';
    }
}
