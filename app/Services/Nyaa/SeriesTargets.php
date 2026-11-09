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
use Illuminate\Database\Eloquent\Builder;
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
 *     stored anime: every anime scoring >= 70 offers its linked shows (its score),
 *     and an anime with no linked show is a candidate itself (a new show linked
 *     to it).
 *
 * The target is the exact-name show; else the single candidate scoring >= 90
 * with no other candidate within 10 (§11's decision rule), whichever rule
 * produced it (an anime candidate gives a new show linked to that anime); else
 * a new show with the suggested name.
 *
 * Rejected show/anime pairs (show_anime_rejections) never offer that show
 * through that anime: an anime whose only links are rejected counts as having
 * no show.
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
        // 3. Anime matching: linked shows, and anime without a show.
        ['shows' => $animeShows, 'anime' => $animeHits] = $this->animeMatches($normalizedQueries);
        $hits = [...$hits, ...$sameName, ...$animeShows];

        // One candidate per show (its best hit), in rule order.
        $best = [];
        foreach ($hits as $hit) {
            if (! isset($best[$hit['showId']]) || $hit['score'] > $best[$hit['showId']]['score']) {
                $best[$hit['showId']] = $hit;
            }
        }

        // One list, best first; ties: shows (in rule order) before anime (by id).
        $ranked = [];
        foreach (array_values($best) as $i => $hit) {
            $ranked[] = [...$hit, 'order' => [0, $i]];
        }
        foreach ($animeHits as $hit) {
            $ranked[] = [...$hit, 'order' => [1, $hit['animeId']]];
        }
        usort($ranked, fn (array $a, array $b) => [$b['score'], $a['order']] <=> [$a['score'], $b['order']]);
        $ranked = array_map(function (array $hit) {
            unset($hit['order']);

            return $hit;
        }, $ranked);

        // Without an exact name: a single candidate >= 90 with no runner-up within 10, whichever rule produced it.
        $top = $ranked[0] ?? null;
        $runnerUp = $ranked[1] ?? null;

        $targetAnime = null;

        if ($target === null
            && $top !== null
            && $top['score'] >= AnimeMatcher::AUTO_LINK_MIN_SCORE
            && ($runnerUp === null || $top['score'] - $runnerUp['score'] >= AnimeMatcher::AUTO_LINK_MIN_MARGIN)) {
            if ($top['kind'] === 'show') {
                $target = $top['showId'];
            } else {
                $targetAnime = $top['animeId'];
            }
        }

        $candidates = array_slice($ranked, 0, self::MAX_CANDIDATES);

        return [
            // describe() completes both kinds (names, trackability, the anime).
            'target' => $target !== null
                ? ['kind' => 'existing', 'showId' => $target]
                : ['kind' => 'new', 'name' => $suggestedName, 'animeId' => $targetAnime],
            'candidates' => $candidates,
        ];
    }

    /**
     * Fills `showName` and `trackable` on every existing target and show
     * candidate of these series, the anime on new targets and anime candidates
     * (one query each), and `trackable`/`trackBlockedReason` on new targets and
     * anime candidates from the series' groups.
     *
     * @param  array<int|string, array<string, mixed>>  $series
     * @return array<int|string, array<string, mixed>>
     */
    public function describe(array $series): array
    {
        $showIds = $animeIds = [];
        foreach ($series as $entry) {
            if ($entry['target']['kind'] === 'existing') {
                $showIds[] = $entry['target']['showId'];
            } elseif (($entry['target']['animeId'] ?? null) !== null) {
                $animeIds[] = $entry['target']['animeId'];
            }
            foreach ($entry['candidates'] as $candidate) {
                if ($candidate['kind'] === 'show') {
                    $showIds[] = $candidate['showId'];
                } else {
                    $animeIds[] = $candidate['animeId'];
                }
            }
        }

        $shows = $showIds === [] ? collect() : Show::query()
            ->select(['id', 'name', 'is_tracked'])
            ->withTrackability()
            ->whereKey(array_values(array_unique($showIds)))
            ->get()
            ->keyBy('id');

        $anime = $animeIds === [] ? collect() : self::animeQuery()
            ->whereKey(array_values(array_unique($animeIds)))
            ->get()
            ->keyBy('id');

        foreach ($series as $key => $entry) {
            if ($entry['target']['kind'] === 'existing') {
                $show = $shows->get($entry['target']['showId']);
                $series[$key]['target'] = $show === null ? self::newTarget($entry['suggestedName'], $entry['groups']) : self::existingTarget($show);
            } else {
                $targetAnime = $anime->get($entry['target']['animeId'] ?? null);
                $series[$key]['target'] = self::newTarget($entry['target']['name'], $entry['groups'], $targetAnime === null ? null : self::animeRef($targetAnime));
            }

            // An anime candidate means a new show: trackable like a new target.
            $animeTrackable = self::newTarget('', $entry['groups'])['trackable'];

            $series[$key]['candidates'] = array_values(array_filter(array_map(function (array $candidate) use ($shows, $anime, $animeTrackable) {
                if ($candidate['kind'] === 'anime') {
                    $entry = $anime->get($candidate['animeId']);

                    return $entry === null ? null : [
                        'kind' => 'anime',
                        'anime' => self::animeRef($entry),
                        'why' => $candidate['why'],
                        'score' => $candidate['score'],
                        'trackable' => $animeTrackable,
                    ];
                }

                $show = $shows->get($candidate['showId']);

                return $show === null ? null : [
                    'kind' => 'show',
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
     * The target a retarget asks for: an existing show by id; a new name (which
     * becomes the existing show when one has exactly that name); or an anime:
     * its one linked show, else a new show linked to it on confirm (named
     * `newName`, else the series' suggested name). An unlinked show that already
     * has that name becomes the target, remembering the anime to link.
     *
     * @param  array<int, string>  $groups  the series' groups, for a new target's trackability
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function chosen(?int $showId, ?string $newName, array $groups, ?int $animeId = null, string $suggestedName = ''): array
    {
        if ($showId !== null) {
            $show = Show::query()->select(['id', 'name', 'is_tracked'])->withTrackability()->find($showId);

            if ($show === null) {
                throw ValidationException::withMessages(['target.showId' => "There is no show with id {$showId}."]);
            }

            return self::existingTarget($show);
        }

        if ($animeId !== null) {
            return $this->chosenAnime($animeId, $newName ?? $suggestedName, $groups);
        }

        $name = self::validName($newName);
        $show = Show::query()->select(['id', 'name', 'is_tracked'])->withTrackability()->where('name', $name)->first();

        return $show === null ? self::newTarget($name, $groups) : self::existingTarget($show);
    }

    /**
     * @param  array<int, string>  $groups
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function chosenAnime(int $animeId, string $newName, array $groups): array
    {
        $anime = self::animeQuery()->find($animeId);

        if ($anime === null) {
            throw ValidationException::withMessages(['target.animeId' => "There is no anime with id {$animeId}."]);
        }

        $linked = self::linkedShowsOf([$anime->id])[$anime->id] ?? [];

        if (count($linked) > 1) {
            $names = implode(', ', array_column($linked, 'name'));

            throw ValidationException::withMessages(['target.animeId' => self::animeTitle($anime)." already has shows {$names}: pick one of them."]);
        }

        if (count($linked) === 1) {
            return self::existingTarget(Show::query()->select(['id', 'name', 'is_tracked'])->withTrackability()->findOrFail($linked[0]['id']));
        }

        $name = self::validName($newName);
        $show = Show::query()->select(['id', 'name', 'is_tracked'])->withTrackability()->with('animeLink.anime')->where('name', $name)->first();

        if ($show === null) {
            return self::newTarget($name, $groups, self::animeRef($anime));
        }

        $link = $show->animeLink;

        if ($link === null) {
            // Linked to this anime when the import is confirmed.
            return self::existingTarget($show, self::animeRef($anime));
        }

        if ($link->anime_id === $anime->id) {
            return self::existingTarget($show);
        }

        $other = $link->anime === null ? "anime #{$link->anime_id}" : self::animeTitle($link->anime);

        throw ValidationException::withMessages(['target.newName' => "A show named {$name} already exists and is linked to {$other}."]);
    }

    /**
     * @throws ValidationException
     */
    private static function validName(?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['target.newName' => 'A new show needs a name of 1 to 255 characters.']);
        }

        return $name;
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
     * An existing show. `anime` is the anime confirming links it to (an unlinked
     * show picked through an anime, by its name), else null.
     *
     * @param  array<string, mixed>|null  $anime  an animeRef()
     * @return array{kind: 'existing', showId: int, showName: string, trackable: bool, trackBlockedReason: string|null, anime: array<string, mixed>|null}
     */
    public static function existingTarget(Show $show, ?array $anime = null): array
    {
        $trackability = $show->trackability();

        return [
            'kind' => 'existing',
            'showId' => $show->id,
            'showName' => $show->name,
            'trackable' => $trackability['trackable'],
            'trackBlockedReason' => $trackability['reason'],
            'anime' => $anime,
        ];
    }

    /**
     * A show to create with this name, linked to `anime` on confirm when one is
     * given. It will be trackable only when the series has a SubsPlease item
     * (the qBittorrent rule follows SubsPlease's feed), so the UI can warn
     * before an untrackable show is created.
     *
     * @param  array<int, string>  $groups  the series' groups
     * @param  array<string, mixed>|null  $anime  an animeRef()
     * @return array{kind: 'new', name: string, trackable: bool, trackBlockedReason: string|null, anime: array<string, mixed>|null}
     */
    public static function newTarget(string $name, array $groups, ?array $anime = null): array
    {
        $trackable = in_array(ReleaseTitleParser::SUBSPLEASE, $groups, true);

        return [
            'kind' => 'new',
            'name' => $name,
            'trackable' => $trackable,
            'trackBlockedReason' => $trackable ? null : Show::blockedReason(),
            'anime' => $anime,
        ];
    }

    /**
     * The anime fields the import dialog shows (`NyaaAnimeRef`). Expects the
     * cover's `image` loaded without `data` (animeQuery()).
     *
     * @return array{id: int, title: string, titleRomaji: string|null, titleEnglish: string|null, season: string|null, seasonYear: int|null, format: string|null, coverUrl: string|null}
     */
    public static function animeRef(Anime $anime): array
    {
        return [
            'id' => $anime->id,
            'title' => self::animeTitle($anime),
            'titleRomaji' => $anime->title_romaji,
            'titleEnglish' => $anime->title_english,
            'season' => $anime->season,
            'seasonYear' => $anime->season_year,
            'format' => $anime->format,
            'coverUrl' => $anime->image?->url(),
        ];
    }

    public static function animeTitle(Anime $anime): string
    {
        return $anime->title_english ?? $anime->title_romaji ?? "#{$anime->id}";
    }

    /**
     * Anime with what animeRef() and matching need: the cover's metadata, never its bytes.
     *
     * @return Builder<Anime>
     */
    public static function animeQuery(): Builder
    {
        return Anime::query()
            ->select(['id', 'title_romaji', 'title_english', 'title_native', 'synonyms', 'season', 'season_year', 'format', 'cover_url', 'cover_error_at'])
            ->with(['image' => fn ($q) => $q->select(['id', 'anime_id', 'sha256', 'width', 'height'])]);
    }

    /**
     * The shows linked to these anime, leaving out links whose pair is rejected
     * (as the candidates do), by show id.
     *
     * @param  array<int, int>  $animeIds
     * @return array<int, array<int, array{id: int, name: string}>> anime id => shows
     */
    public static function linkedShowsOf(array $animeIds): array
    {
        if ($animeIds === []) {
            return [];
        }

        $linked = [];

        ShowAnimeLink::query()
            ->whereIn('anime_id', $animeIds)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('show_anime_rejections')
                ->whereColumn('show_anime_rejections.show_id', 'show_anime_links.show_id')
                ->whereColumn('show_anime_rejections.anime_id', 'show_anime_links.anime_id'))
            ->with(['show' => fn ($q) => $q->select(['id', 'name'])])
            ->orderBy('show_id')
            ->get(['show_id', 'anime_id'])
            ->each(function (ShowAnimeLink $link) use (&$linked) {
                if ($link->show !== null) {
                    $linked[$link->anime_id][] = ['id' => $link->show->id, 'name' => $link->show->name];
                }
            });

        return $linked;
    }

    private function sameName(NormalizedTitle $show, NormalizedTitle $query): bool
    {
        if ($show->plain === '' || $query->plain === '') {
            return false;
        }

        return $show->samePlain($query) || ($show->sameBase($query) && $show->seasonNumber() === $query->seasonNumber());
    }

    /**
     * Every scored anime's linked shows, and the scored anime without a show.
     *
     * @param  array<int, array{query: string, alt: string|null, normalized: NormalizedTitle}>  $queries
     * @return array{shows: array<int, array{kind: 'show', showId: int, why: string, score: int}>, anime: array<int, array{kind: 'anime', animeId: int, why: string, score: int}>}
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

        $shows = $anime = [];
        foreach ($scored as $match) {
            $linked = $this->linkedShows()[$match['animeId']] ?? [];

            if ($linked === []) {
                $anime[] = ['kind' => 'anime', 'animeId' => $match['animeId'], 'why' => "Anime title '{$match['title']}', no show yet.", 'score' => $match['score']];
            }

            foreach ($linked as $showId) {
                $shows[] = $this->candidate($showId, "Anime title '{$match['title']}' of the linked anime.", $match['score']);
            }
        }

        return ['shows' => $shows, 'anime' => $anime];
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
     * @return array{kind: 'show', showId: int, why: string, score: int}
     */
    private function candidate(int $showId, string $why, int $score): array
    {
        return ['kind' => 'show', 'showId' => $showId, 'why' => $why, 'score' => $score];
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
