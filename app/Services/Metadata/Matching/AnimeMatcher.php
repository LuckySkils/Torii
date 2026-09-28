<?php

declare(strict_types=1);

namespace App\Services\Metadata\Matching;

use App\Enums\AnimeSeason;
use App\Enums\MatchRule;
use App\Enums\SuggestionReason;
use App\Models\Anime;
use App\Models\Show;

/**
 * Local, request-free matching of SubsPlease show names against stored anime.
 *
 * Score per title: exact normalized match (ignoring spaces) 100; the part before
 * the title's first colon matches, with the full title's season, 95 (SubsPlease
 * often drops AniList's ": subtitle"); same base with equal season numbers
 * (S2 vs 2nd Season) 90; same season and a Levenshtein ratio >= 0.9 on the base,
 * 70–85; anything else 0. An anime scores its best title (romaji, english,
 * native, every synonym).
 *
 * Auto-link needs >= 90 and a runner-up at least 10 lower. When candidates are
 * that close, nothing is linked and they become suggestions for manual review.
 *
 * Two gates then stand between a clear winner and an automatic link, without
 * changing any score: a candidate with fewer episodes than the show has
 * released (the wrong season, or SubsPlease numbering on from season 1), and a
 * show the user has rejected a pair for. Either makes it a suggestion instead.
 */
final class AnimeMatcher
{
    public const AUTO_LINK_MIN_SCORE = 90;

    public const AUTO_LINK_MIN_MARGIN = 10;

    public const MIN_SCORE = 70;

    public const SUBTITLE_PREFIX_SCORE = 95;

    /** Episodes a show may run past a candidate's total before it's suspect (specials, .5s). */
    public const EPISODE_TOLERANCE = 2;

    private const SIMILARITY_THRESHOLD = 0.9;

    public function __construct(private readonly TitleNormalizer $normalizer) {}

    /**
     * @param  array<int, string>  $titles
     */
    public function score(string $showName, array $titles): int
    {
        return $this->scoreCandidate(
            $this->normalizer->normalize($showName),
            $this->candidate(0, '', null, null, $titles),
        )->score;
    }

    /**
     * Normalizes every anime's titles once, for matching many shows against them.
     *
     * @param  iterable<int, Anime>  $anime
     * @return array<int, MatchCandidate>
     */
    public function index(iterable $anime): array
    {
        $index = [];

        foreach ($anime as $entry) {
            $index[] = $this->candidate(
                $entry->id,
                $entry->title_english ?? $entry->title_romaji ?? $entry->title_native ?? "#{$entry->id}",
                $entry->season !== null ? AnimeSeason::tryFrom($entry->season) : null,
                $entry->season_year,
                $entry->titles(),
                $entry->episodes_total,
            );
        }

        return $index;
    }

    /**
     * @param  array<int, MatchCandidate>  $index
     * @param  array<int, int>  $rejectedAnimeIds  pairs the user rejected for this show: never candidates, and any at all means no auto-link
     * @param  float|null  $highestEpisode  the show's highest released episode number, null when none is numbered
     */
    public function decide(Show $show, array $index, array $rejectedAnimeIds = [], ?float $highestEpisode = null): MatchDecision
    {
        $name = $this->normalizer->normalize($show->name);
        $rejected = array_flip($rejectedAnimeIds);
        $scored = [];

        foreach ($index as $candidate) {
            if (isset($rejected[$candidate->animeId])) {
                continue;
            }

            $match = $this->scoreCandidate($name, $candidate);

            if ($match->score >= self::MIN_SCORE) {
                $scored[] = new ScoredCandidate($candidate, $match->score, $match->rule);
            }
        }

        usort($scored, fn (ScoredCandidate $a, ScoredCandidate $b) => [$b->score, $a->candidate->animeId] <=> [$a->score, $b->candidate->animeId]);

        $pool = $this->preferSeasonConsistent($show, $scored);
        $top = $pool[0] ?? null;
        $runnerUp = $pool[1] ?? null;

        if ($top === null) {
            return new MatchDecision($show, MatchOutcome::None, null, $scored);
        }

        if ($top->score < self::AUTO_LINK_MIN_SCORE) {
            return new MatchDecision($show, MatchOutcome::Weak, null, $scored);
        }

        if ($runnerUp !== null && $top->score - $runnerUp->score < self::AUTO_LINK_MIN_MARGIN) {
            // Everything close enough to the top to have blocked it is worth a look.
            $suggestions = array_values(array_filter(
                $pool,
                fn (ScoredCandidate $c) => $top->score - $c->score < self::AUTO_LINK_MIN_MARGIN,
            ));

            return new MatchDecision($show, MatchOutcome::Ambiguous, null, $scored, $suggestions, SuggestionReason::Ambiguous);
        }

        // A clear winner. The gates decide whether it's linked or only suggested.
        if ($this->tooFewEpisodes($top->candidate, $highestEpisode)) {
            return new MatchDecision($show, MatchOutcome::Held, null, $scored, [$top], SuggestionReason::EpisodeCount);
        }

        if ($rejectedAnimeIds !== []) {
            return new MatchDecision($show, MatchOutcome::Held, null, $scored, [$top], SuggestionReason::ShowHasRejection);
        }

        return new MatchDecision($show, MatchOutcome::Link, $top, $scored);
    }

    /**
     * The show is past the candidate's last episode: a later season, or absolute
     * numbering (Hyakkano at 36 against 12). Unknown on either side passes.
     */
    public function tooFewEpisodes(MatchCandidate $candidate, ?float $highestEpisode): bool
    {
        return $candidate->episodesTotal !== null
            && $highestEpisode !== null
            && $highestEpisode > $candidate->episodesTotal + self::EPISODE_TOLERANCE;
    }

    public function scoreCandidate(NormalizedTitle $show, MatchCandidate $candidate): TitleScore
    {
        $best = new TitleScore(0, null);

        foreach ($candidate->titles as $title) {
            $score = $this->scorePair($show, $title);

            if ($score->score > $best->score) {
                $best = $score;
            }
        }

        if ($best->score < self::SUBTITLE_PREFIX_SCORE) {
            foreach ($candidate->prefixes as $prefix) {
                if ($show->plain !== '' && $show->sameBase($prefix) && $show->seasonNumber() === $prefix->seasonNumber()) {
                    return new TitleScore(self::SUBTITLE_PREFIX_SCORE, MatchRule::SubtitlePrefix);
                }
            }
        }

        return $best;
    }

    public function scorePair(NormalizedTitle $show, NormalizedTitle $title): TitleScore
    {
        if ($show->plain === '' || $title->plain === '') {
            return new TitleScore(0, null);
        }

        if ($show->samePlain($title)) {
            return new TitleScore(100, MatchRule::Exact);
        }

        // A different season is a different anime, however similar the name.
        if ($show->seasonNumber() !== $title->seasonNumber()) {
            return new TitleScore(0, null);
        }

        if ($show->sameBase($title)) {
            return new TitleScore(90, MatchRule::SeasonMarker);
        }

        $ratio = $this->similarity($show->base, $title->base);

        if ($ratio < self::SIMILARITY_THRESHOLD) {
            return new TitleScore(0, null);
        }

        return new TitleScore(
            min(85, 70 + (int) round(($ratio - self::SIMILARITY_THRESHOLD) / (1 - self::SIMILARITY_THRESHOLD) * 15)),
            MatchRule::Similarity,
        );
    }

    /** Levenshtein ratio: 1 for identical strings, 0 for nothing in common. */
    public function similarity(string $a, string $b): float
    {
        $longest = max(strlen($a), strlen($b));

        if ($longest === 0) {
            return 1.0;
        }

        // Cheap bound first: the length difference alone can rule it out.
        if (abs(strlen($a) - strlen($b)) / $longest > 1 - self::SIMILARITY_THRESHOLD) {
            return 0.0;
        }

        return 1 - levenshtein($a, $b) / $longest;
    }

    /**
     * @param  array<int, string>  $titles
     */
    private function candidate(int $animeId, string $label, ?AnimeSeason $season, ?int $seasonYear, array $titles, ?int $episodesTotal = null): MatchCandidate
    {
        $normalized = [];
        $prefixes = [];
        $variants = array_map(fn (string $title) => $this->normalizer->normalize($title), $titles);
        $animeSeason = $this->declaredSeason($variants);

        foreach ($titles as $i => $title) {
            $full = $this->withAnimeSeason($variants[$i], $animeSeason);
            $normalized[] = $full;

            $parts = preg_split('/\s*[:：]\s*/u', $title, 2);

            if ($parts !== false && count($parts) === 2 && trim($parts[0]) !== '' && trim($parts[1]) !== '') {
                $prefix = $this->normalizer->normalize($parts[0]);

                // The season marker often sits in the subtitle ("Hell Mode: … 2nd Season"):
                // the whole title's season counts, so a season-1 show can't match it.
                $prefixes[] = new NormalizedTitle($prefix->plain, $prefix->base, $full->season ?? $prefix->season);
            }
        }

        return new MatchCandidate($animeId, $label, $season, $seasonYear, $normalized, $prefixes, $episodesTotal);
    }

    /**
     * The anime's season number, as its explicitly marked variants state it.
     * Only one variant may carry the marker ("Welcome to Demon School! Iruma-kun
     * Season 4" next to the romaji "Mairimashita! Iruma-kun 4"), so the anime is
     * the unit, not the title. Variants that disagree give no season.
     *
     * @param  array<int, NormalizedTitle>  $variants
     */
    public function declaredSeason(array $variants): ?int
    {
        $seasons = array_values(array_unique(array_filter(
            array_map(fn (NormalizedTitle $variant) => $variant->season, $variants),
            fn (?int $season) => $season !== null,
        )));

        return count($seasons) === 1 ? $seasons[0] : null;
    }

    /**
     * The season number this anime's titles imply (1 when none says otherwise).
     *
     * @param  array<int, string>  $titles
     */
    public function seasonOf(array $titles): int
    {
        return $this->declaredSeason(array_map(fn (string $title) => $this->normalizer->normalize($title), $titles)) ?? 1;
    }

    /**
     * An unmarked variant takes the anime's season. A bare trailing number equal
     * to that season is its marker ("Iruma-kun 4", "Tensura 4"); without that
     * confirmation a trailing number stays part of the name ("Mob Psycho 100").
     */
    private function withAnimeSeason(NormalizedTitle $variant, ?int $animeSeason): NormalizedTitle
    {
        if ($variant->season !== null || $animeSeason === null) {
            return $variant;
        }

        $base = preg_replace('/ '.$animeSeason.'$/', '', $variant->base) ?? $variant->base;

        return new NormalizedTitle($variant->plain, $base === '' ? $variant->base : $base, $animeSeason);
    }

    /**
     * §9.5 rule 5. Only a tie-breaker among strong (>= 90) candidates: when some of
     * them are within one season of the show's premiere and some aren't, the
     * inconsistent ones drop out. It never blocks a lone strong match, since
     * `earliest_seen` premieres of long-running shows (One Piece) are meaningless.
     *
     * @param  array<int, ScoredCandidate>  $scored
     * @return array<int, ScoredCandidate>
     */
    private function preferSeasonConsistent(Show $show, array $scored): array
    {
        if ($show->season === null || $show->season_year === null) {
            return $scored;
        }

        $showOrdinal = AnimeSeason::ordinal(AnimeSeason::fromShowSeason($show->season), $show->season_year);

        $isInconsistent = fn (ScoredCandidate $c) => $c->candidate->season !== null
            && $c->candidate->seasonYear !== null
            && abs(AnimeSeason::ordinal($c->candidate->season, $c->candidate->seasonYear) - $showOrdinal) > 1;

        $strong = array_filter($scored, fn (ScoredCandidate $c) => $c->score >= self::AUTO_LINK_MIN_SCORE);
        $strongConsistent = array_filter($strong, fn (ScoredCandidate $c) => ! $isInconsistent($c));

        if ($strongConsistent === [] || count($strongConsistent) === count($strong)) {
            return $scored;
        }

        return array_values(array_filter(
            $scored,
            fn (ScoredCandidate $c) => $c->score < self::AUTO_LINK_MIN_SCORE || ! $isInconsistent($c),
        ));
    }
}
