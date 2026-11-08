<?php

declare(strict_types=1);

namespace App\Services\Nyaa;

use App\Models\Anime;
use App\Models\Show;
use App\Models\ShowAnimeLink;
use App\Models\ShowAnimeRejection;
use App\Services\Metadata\Matching\AnimeMatcher;
use App\Services\Metadata\Matching\MatchCandidate;
use App\Services\Metadata\Matching\NormalizedTitle;
use App\Services\Metadata\Matching\TitleNormalizer;
use App\Services\Releases\ReleaseTitleParser;
use Illuminate\Validation\ValidationException;

/**
 * Where each parsed series of a Nyaa import goes (§16): the suggested target
 * show and the candidates the user can pick from. Local data only, no provider
 * calls. Candidates (one per show, its best hit) come from:
 *
 *  1. a show named exactly the suggested name (100);
 *  2. a show whose normalized name (TitleNormalizer: case, punctuation, season
 *     wording) equals the suggested name or an alt title (+ season) (95);
 *  3. anime matching (AnimeMatcher) of the suggested name and alt titles against
 *     stored anime: every anime scoring >= 70 offers its linked shows (its score).
 *
 * The target is the exact-name show; else the single candidate scoring >= 90
 * with no other candidate within 10 (§11's decision rule), whichever rule
 * produced it; else a new show with the suggested name.
 *
 * Rejected show/anime pairs (show_anime_rejections) never offer that show
 * through that anime.
 */
final class SeriesTargets
{
    public const MAX_CANDIDATES = 5;

    /** @var array<int, array{id: int, name: string, isTracked: bool, normalized: NormalizedTitle}>|null */
    private ?array $shows = null;

    /** @var array<int, array{candidate: MatchCandidate, titles: array<int, string>}>|null */
    private ?array $anime = null;

    /** @var array<int, array<int, int>>|null anime id => offered show ids */
    private ?array $linkedShows = null;

    public function __construct(
        private readonly TitleNormalizer $normalizer,
        private readonly AnimeMatcher $matcher,
    ) {}

    /**
     * @param  array<int, string>  $altTitles
     * @return array{target: array<string, mixed>, candidates: array<int, array<string, mixed>>}
     */
    public function suggest(string $suggestedName, ?int $season, array $altTitles): array
    {
        $queries = [$suggestedName => null];
        foreach ($altTitles as $alt) {
            $queries[$season !== null && $season > 1 ? "{$alt} S{$season}" : $alt] ??= $alt;
        }

        $normalizedQueries = [];
        foreach ($queries as $query => $alt) {
            $normalizedQueries[] = ['query' => $query, 'alt' => $alt, 'normalized' => $this->normalizer->normalize($query)];
        }

        $hits = [];
        $target = null;

        // 1. Exact name.
        foreach ($this->shows() as $show) {
            if ($show['name'] === $suggestedName) {
                $hits[] = $this->candidate($show['id'], "Named exactly '{$suggestedName}'.", 100);
                $target = $show['id'];
            }
        }

        // 2. Same normalized name (+ season).
        $sameName = [];
        foreach ($this->shows() as $show) {
            foreach ($normalizedQueries as $query) {
                if ($this->sameName($show['normalized'], $query['normalized'])) {
                    $sameName[] = $this->candidate(
                        $show['id'],
                        $query['alt'] === null
                            ? "Same name as '{$suggestedName}', ignoring case, punctuation and season wording."
                            : "Same name as the alt title '{$query['alt']}' in the release title.",
                        95,
                    );
                    break;
                }
            }
        }
        // 3. Anime matching.
        $hits = [...$hits, ...$sameName, ...$this->animeMatches($normalizedQueries)];

        // One candidate per show (its best hit), best first; ties keep the rule order.
        $best = [];
        foreach ($hits as $hit) {
            if (! isset($best[$hit['showId']]) || $hit['score'] > $best[$hit['showId']]['score']) {
                $best[$hit['showId']] = $hit;
            }
        }
        $ranked = array_values($best);
        usort($ranked, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        // Without an exact name: a single candidate >= 90 with no runner-up within 10, whichever rule produced it.
        $top = $ranked[0] ?? null;
        $runnerUp = $ranked[1] ?? null;

        if ($target === null
            && $top !== null
            && $top['score'] >= AnimeMatcher::AUTO_LINK_MIN_SCORE
            && ($runnerUp === null || $top['score'] - $runnerUp['score'] >= AnimeMatcher::AUTO_LINK_MIN_MARGIN)) {
            $target = $top['showId'];
        }

        $candidates = array_slice($ranked, 0, self::MAX_CANDIDATES);

        return [
            // describe() completes both kinds (names, trackability).
            'target' => $target !== null ? ['kind' => 'existing', 'showId' => $target] : ['kind' => 'new', 'name' => $suggestedName],
            'candidates' => $candidates,
        ];
    }

    /**
     * Fills `showName` and `trackable` on every existing target and candidate of
     * these series, in one query, and `trackable`/`trackBlockedReason` on new
     * targets from the series' groups.
     *
     * @param  array<int|string, array<string, mixed>>  $series
     * @return array<int|string, array<string, mixed>>
     */
    public function describe(array $series): array
    {
        $ids = [];
        foreach ($series as $entry) {
            if ($entry['target']['kind'] === 'existing') {
                $ids[] = $entry['target']['showId'];
            }
            foreach ($entry['candidates'] as $candidate) {
                $ids[] = $candidate['showId'];
            }
        }

        $shows = $ids === [] ? collect() : Show::query()
            ->select(['id', 'name', 'is_tracked'])
            ->withTrackability()
            ->whereKey(array_values(array_unique($ids)))
            ->get()
            ->keyBy('id');

        foreach ($series as $key => $entry) {
            if ($entry['target']['kind'] === 'existing') {
                $show = $shows->get($entry['target']['showId']);
                $series[$key]['target'] = $show === null ? self::newTarget($entry['suggestedName'], $entry['groups']) : self::existingTarget($show);
            } else {
                $series[$key]['target'] = self::newTarget($entry['target']['name'], $entry['groups']);
            }

            $series[$key]['candidates'] = array_values(array_filter(array_map(function (array $candidate) use ($shows) {
                $show = $shows->get($candidate['showId']);

                return $show === null ? null : [
                    'showId' => $show->id,
                    'showName' => $show->name,
                    'why' => $candidate['why'],
                    'score' => $candidate['score'],
                    'trackable' => $show->trackability()['trackable'],
                ];
            }, $entry['candidates'])));
        }

        return $series;
    }

    /**
     * The target a retarget asks for: an existing show by id, or a new name
     * (which becomes the existing show when one has exactly that name).
     *
     * @param  array<int, string>  $groups  the series' groups, for a new target's trackability
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function chosen(?int $showId, ?string $newName, array $groups): array
    {
        if ($showId !== null) {
            $show = Show::query()->select(['id', 'name', 'is_tracked'])->withTrackability()->find($showId);

            if ($show === null) {
                throw ValidationException::withMessages(['target.showId' => "There is no show with id {$showId}."]);
            }

            return self::existingTarget($show);
        }

        $name = trim((string) $newName);

        if ($name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['target.newName' => 'A new show needs a name of 1 to 255 characters.']);
        }

        $show = Show::query()->select(['id', 'name', 'is_tracked'])->withTrackability()->where('name', $name)->first();

        return $show === null ? self::newTarget($name, $groups) : self::existingTarget($show);
    }

    /**
     * The show picker's search: every word of `q` in the name (case-insensitive),
     * an exact name first, at most 20.
     *
     * @return array<int, array{id: int, name: string, isTracked: bool, trackable: bool, latestEpisode: string|null}>
     */
    public function search(string $q): array
    {
        $q = trim($q);
        $words = array_values(array_filter(preg_split('/\s+/u', $q) ?: [], fn (string $word) => $word !== ''));

        if ($words === []) {
            return [];
        }

        $query = Show::query()->select(['id', 'name', 'is_tracked', 'latest_episode'])->withTrackability();

        foreach ($words as $word) {
            $query->where('name', 'ilike', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word).'%');
        }

        return $query
            ->orderByRaw('lower(name) = ? desc', [mb_strtolower($q)])
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (Show $show) => [
                'id' => $show->id,
                'name' => $show->name,
                'isTracked' => $show->is_tracked,
                'trackable' => $show->trackability()['trackable'],
                'latestEpisode' => $show->latest_episode,
            ])
            ->all();
    }

    /**
     * @return array{kind: 'existing', showId: int, showName: string, trackable: bool, trackBlockedReason: string|null}
     */
    public static function existingTarget(Show $show): array
    {
        $trackability = $show->trackability();

        return [
            'kind' => 'existing',
            'showId' => $show->id,
            'showName' => $show->name,
            'trackable' => $trackability['trackable'],
            'trackBlockedReason' => $trackability['reason'],
        ];
    }

    /**
     * A show to create with this name. It will be trackable only when the series
     * has a SubsPlease item (the qBittorrent rule follows SubsPlease's feed), so
     * the UI can warn before an untrackable show is created.
     *
     * @param  array<int, string>  $groups  the series' groups
     * @return array{kind: 'new', name: string, trackable: bool, trackBlockedReason: string|null}
     */
    public static function newTarget(string $name, array $groups): array
    {
        $trackable = in_array(ReleaseTitleParser::SUBSPLEASE, $groups, true);

        return [
            'kind' => 'new',
            'name' => $name,
            'trackable' => $trackable,
            'trackBlockedReason' => $trackable ? null : Show::blockedReason(),
        ];
    }

    private function sameName(NormalizedTitle $show, NormalizedTitle $query): bool
    {
        if ($show->plain === '' || $query->plain === '') {
            return false;
        }

        return $show->samePlain($query) || ($show->sameBase($query) && $show->seasonNumber() === $query->seasonNumber());
    }

    /**
     * @param  array<int, array{query: string, alt: string|null, normalized: NormalizedTitle}>  $queries
     * @return array<int, array{showId: int, why: string, score: int}> every scored anime's linked shows
     */
    private function animeMatches(array $queries): array
    {
        $scored = [];

        foreach ($this->anime() as $animeId => $entry) {
            $best = null;

            foreach ($queries as $query) {
                $score = $this->matcher->scoreCandidate($query['normalized'], $entry['candidate'])->score;

                if ($score >= AnimeMatcher::MIN_SCORE && ($best === null || $score > $best['score'])) {
                    $best = ['score' => $score, 'title' => $this->matchedTitle($query['normalized'], $entry, $score)];
                }
            }

            if ($best !== null) {
                $scored[] = ['animeId' => $animeId, ...$best];
            }
        }

        usort($scored, fn (array $a, array $b) => [$b['score'], $a['animeId']] <=> [$a['score'], $b['animeId']]);

        $hits = [];
        foreach ($scored as $match) {
            foreach ($this->linkedShows()[$match['animeId']] ?? [] as $showId) {
                $hits[] = $this->candidate($showId, "AniList title '{$match['title']}' of the linked anime.", $match['score']);
            }
        }

        return $hits;
    }

    /**
     * The anime title that gave this score, for the candidate's `why`.
     *
     * @param  array{candidate: MatchCandidate, titles: array<int, string>}  $entry
     */
    private function matchedTitle(NormalizedTitle $query, array $entry, int $score): string
    {
        foreach ($entry['candidate']->titles as $i => $title) {
            if ($this->matcher->scorePair($query, $title)->score === $score) {
                return $entry['titles'][$i] ?? $entry['candidate']->label;
            }
        }

        // The subtitle-prefix rule: the title whose part before the colon matched.
        foreach ($entry['titles'] as $title) {
            $parts = preg_split('/\s*[:：]\s*/u', $title, 2);

            if ($parts !== false && count($parts) === 2 && $query->sameBase($this->normalizer->normalize($parts[0]))) {
                return $title;
            }
        }

        return $entry['candidate']->label;
    }

    /**
     * @return array{showId: int, why: string, score: int}
     */
    private function candidate(int $showId, string $why, int $score): array
    {
        return ['showId' => $showId, 'why' => $why, 'score' => $score];
    }

    /**
     * @return array<int, array{id: int, name: string, isTracked: bool, normalized: NormalizedTitle}>
     */
    private function shows(): array
    {
        return $this->shows ??= Show::query()
            ->orderBy('id')
            ->get(['id', 'name', 'is_tracked'])
            ->mapWithKeys(fn (Show $show) => [$show->id => [
                'id' => $show->id,
                'name' => $show->name,
                'isTracked' => $show->is_tracked,
                'normalized' => $this->normalizer->normalize($show->name),
            ]])
            ->all();
    }

    /**
     * @return array<int, array{candidate: MatchCandidate, titles: array<int, string>}>
     */
    private function anime(): array
    {
        if ($this->anime !== null) {
            return $this->anime;
        }

        $anime = Anime::query()->get(['id', 'title_romaji', 'title_english', 'title_native', 'synonyms', 'season', 'season_year', 'episodes_total']);
        $index = $this->matcher->index($anime);
        $this->anime = [];

        foreach ($anime->values() as $i => $entry) {
            $this->anime[$entry->id] = ['candidate' => $index[$i], 'titles' => $entry->titles()];
        }

        return $this->anime;
    }

    /**
     * @return array<int, array<int, int>>
     */
    private function linkedShows(): array
    {
        if ($this->linkedShows !== null) {
            return $this->linkedShows;
        }

        $rejected = ShowAnimeRejection::query()->get(['show_id', 'anime_id'])
            ->mapWithKeys(fn (ShowAnimeRejection $r) => ["{$r->show_id}:{$r->anime_id}" => true])
            ->all();

        $this->linkedShows = [];

        foreach (ShowAnimeLink::query()->orderBy('show_id')->get(['show_id', 'anime_id']) as $link) {
            if (! isset($rejected["{$link->show_id}:{$link->anime_id}"])) {
                $this->linkedShows[$link->anime_id][] = $link->show_id;
            }
        }

        return $this->linkedShows;
    }
}
