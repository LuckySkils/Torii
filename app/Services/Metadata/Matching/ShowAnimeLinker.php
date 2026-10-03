<?php

declare(strict_types=1);

namespace App\Services\Metadata\Matching;

use App\Enums\LinkSource;
use App\Enums\SuggestionReason;
use App\Models\Anime;
use App\Models\Release;
use App\Models\Show;
use App\Models\ShowAnimeLink;
use App\Models\ShowAnimeRejection;
use App\Models\ShowAnimeSuggestion;
use Illuminate\Support\Facades\DB;

/**
 * Plans and writes show → anime links, suggestions and rejections. A link, never
 * a merge; a manual link is never touched by automatic matching, and a rejected
 * pair is never auto-linked or suggested again.
 */
final class ShowAnimeLinker
{
    public function __construct(private readonly AnimeMatcher $matcher) {}

    /**
     * Decisions for every show without a manual link (or just $showId).
     *
     * @return array<int, MatchDecision>
     */
    public function plan(?int $showId = null): array
    {
        $shows = Show::query()
            ->whereDoesntHave('animeLink', fn ($query) => $query->where('source', LinkSource::Manual))
            ->when($showId !== null, fn ($query) => $query->whereKey($showId))
            ->orderBy('name')
            ->get(['id', 'name', 'season', 'season_year']);

        if ($shows->isEmpty()) {
            return [];
        }

        $index = $this->matcher->index(
            Anime::query()->get(['id', 'title_romaji', 'title_english', 'title_native', 'synonyms', 'season', 'season_year', 'episodes_total']),
        );

        $rejections = ShowAnimeRejection::query()
            ->whereIn('show_id', $shows->modelKeys())
            ->get(['show_id', 'anime_id'])
            ->groupBy('show_id');

        $highestEpisodes = Release::highestEpisodes($shows->modelKeys());

        return $shows
            ->map(fn (Show $show) => $this->matcher->decide(
                $show,
                $index,
                $rejections->get($show->id)?->pluck('anime_id')->all() ?? [],
                $highestEpisodes[$show->id] ?? null,
            ))
            ->all();
    }

    /**
     * What matching would decide for one show right now, manual link or not.
     * Read-only: nothing is linked, suggested or rejected (the MCP suggest_link tool).
     */
    public function decisionFor(Show $show): MatchDecision
    {
        $index = $this->matcher->index(
            Anime::query()->get(['id', 'title_romaji', 'title_english', 'title_native', 'synonyms', 'season', 'season_year', 'episodes_total']),
        );

        return $this->matcher->decide(
            $show,
            $index,
            ShowAnimeRejection::where('show_id', $show->id)->pluck('anime_id')->all(),
            Release::highestEpisodes([$show->id])[$show->id] ?? null,
        );
    }

    /**
     * Writes one decision. A `link` decision creates or updates the auto link; for
     * an unlinked show an `ambiguous` or `held` one replaces its suggestions (with
     * the reason), anything else clears them. An existing auto link is kept rather than removed on a weaker
     * run, and a linked show never has suggestions.
     *
     * @return 'created'|'updated'|'unchanged'|'suggested'|'skipped'
     */
    public function apply(MatchDecision $decision): string
    {
        $show = $decision->show;
        $existing = ShowAnimeLink::where('show_id', $show->id)->first();

        if ($existing?->source === LinkSource::Manual) {
            return 'skipped';
        }

        if ($decision->outcome !== MatchOutcome::Link || $decision->link === null) {
            if ($existing !== null) {
                $this->clearSuggestions($show);

                return 'skipped';
            }

            $suggests = $decision->reason !== null && $decision->suggestions !== [];
            $this->replaceSuggestions($show, $suggests ? $decision->suggestions : [], $decision->reason);

            return $suggests ? 'suggested' : 'skipped';
        }

        $this->clearSuggestions($show);

        $animeId = $decision->link->candidate->animeId;
        $confidence = $decision->link->score;

        if ($existing !== null && $existing->anime_id === $animeId && $existing->confidence === $confidence) {
            return 'unchanged';
        }

        ShowAnimeLink::updateOrCreate(
            ['show_id' => $show->id],
            [
                'anime_id' => $animeId,
                'confidence' => $confidence,
                'source' => LinkSource::Auto,
                'linked_at' => now(),
            ],
        );

        return $existing === null ? 'created' : 'updated';
    }

    /** Links by hand: clears the show's suggestions and any rejection of this pair. */
    public function linkManually(Show $show, Anime $anime): ShowAnimeLink
    {
        return DB::transaction(function () use ($show, $anime): ShowAnimeLink {
            ShowAnimeRejection::where('show_id', $show->id)->where('anime_id', $anime->id)->delete();
            $this->clearSuggestions($show);

            return ShowAnimeLink::updateOrCreate(
                ['show_id' => $show->id],
                [
                    'anime_id' => $anime->id,
                    'confidence' => 100,
                    'source' => LinkSource::Manual,
                    'linked_at' => now(),
                ],
            );
        });
    }

    /** Removes the link, auto or manual, and rejects the pair so matching can't restore it. */
    public function unlink(Show $show): bool
    {
        return DB::transaction(function () use ($show): bool {
            $link = ShowAnimeLink::where('show_id', $show->id)->first();

            if ($link === null) {
                return false;
            }

            $this->recordRejection($show->id, $link->anime_id);
            $link->delete();

            return true;
        });
    }

    /** Rejects one suggested candidate: the suggestion goes, the rejection stays. */
    public function reject(Show $show, Anime $anime): void
    {
        DB::transaction(function () use ($show, $anime): void {
            $this->recordRejection($show->id, $anime->id);
            ShowAnimeSuggestion::where('show_id', $show->id)->where('anime_id', $anime->id)->delete();
        });
    }

    private function recordRejection(int $showId, int $animeId): void
    {
        ShowAnimeRejection::firstOrCreate(
            ['show_id' => $showId, 'anime_id' => $animeId],
            ['rejected_at' => now()],
        );
    }

    private function clearSuggestions(Show $show): void
    {
        ShowAnimeSuggestion::where('show_id', $show->id)->delete();
    }

    /**
     * Makes the show's suggestions exactly $candidates. A suggestion that survives
     * keeps its created_at; its score and rule are refreshed.
     *
     * @param  array<int, ScoredCandidate>  $candidates
     */
    private function replaceSuggestions(Show $show, array $candidates, ?SuggestionReason $reason): void
    {
        $animeIds = array_map(fn (ScoredCandidate $c) => $c->candidate->animeId, $candidates);

        ShowAnimeSuggestion::where('show_id', $show->id)->whereNotIn('anime_id', $animeIds)->delete();

        if ($candidates === []) {
            return;
        }

        ShowAnimeSuggestion::upsert(
            array_map(fn (ScoredCandidate $c) => [
                'show_id' => $show->id,
                'anime_id' => $c->candidate->animeId,
                'score' => $c->score,
                'rule' => $c->rule->value,
                'reason' => ($reason ?? SuggestionReason::Ambiguous)->value,
                'created_at' => now(),
            ], $candidates),
            ['show_id', 'anime_id'],
            ['score', 'rule', 'reason'],
        );
    }
}
