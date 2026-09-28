<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\MetadataProvider;
use App\Jobs\FetchAnimeCover;
use App\Models\Anime;
use App\Models\Show;
use App\Models\ShowAnimeLink;
use App\Models\ShowAnimeSuggestion;
use App\Services\Metadata\AnimeSyncer;
use App\Services\Metadata\Matching\AnimeMatcher;
use App\Services\Metadata\Matching\ShowAnimeLinker;
use App\Services\Metadata\Matching\TitleNormalizer;
use App\Services\Metadata\MetadataProviderException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Manual linking (§9.6): the instrument behind the frontend's "link manually" dialog.
 */
class AnimeLinkController extends Controller
{
    private const MIN_LOCAL_RESULTS = 5;

    private const MAX_RESULTS = 20;

    /**
     * Searches locally stored anime first; with fewer than 5 hits, or none in the
     * wanted season, falls back to one provider search (throttled, fail-fast),
     * whose results are stored so they can be linked by id. A provider failure
     * only means fewer results.
     *
     * Without `q`, the show name is searched minus its season marker ("Link Click
     * S3" → "Link Click": AniList finds nothing for the literal "S3"); a typed `q`
     * is sent to the provider exactly as typed. Either way the season number (the
     * typed one, else the show's) only ranks results; other seasons stay listed.
     */
    public function search(
        Request $request,
        Show $show,
        MetadataProvider $provider,
        AnimeSyncer $syncer,
        AnimeMatcher $matcher,
        TitleNormalizer $normalizer,
    ): JsonResponse {
        $typed = trim((string) $request->query('q', ''));
        $query = $typed === '' ? $show->name : $typed;
        $searchedQuery = $typed === '' ? $normalizer->withoutSeasonMarker($show->name) : $typed;
        $season = $normalizer->normalize($query)->seasonNumber();

        $results = $this->localSearch($normalizer->normalize($query)->base);
        $providerSearched = false;
        $providerError = null;

        // Plenty of local hits don't help if none is the wanted season (six stored
        // Link Click entries, none of them S3), so that also asks the provider.
        $localHasSeason = Anime::query()
            ->whereKey($results->pluck('id')->all())
            ->get(['id', 'title_romaji', 'title_english', 'title_native', 'synonyms'])
            ->contains(fn (Anime $entry) => $matcher->seasonOf($entry->titles()) === $season);

        if ($results->count() < self::MIN_LOCAL_RESULTS || ! $localHasSeason) {
            $providerSearched = true;

            try {
                foreach ($provider->search($searchedQuery, 10) as $entry) {
                    $results->push($syncer->upsert($entry)->anime);
                }
            } catch (MetadataProviderException|ConnectionException $e) {
                $providerError = $e->getMessage();
                logger()->warning('Metadata provider search failed', ['query' => $searchedQuery, 'error' => $e->getMessage()]);
            }
        }

        $rows = $this->withResultRelations(Anime::query()->whereKey($results->pluck('id')->unique()->all()))
            ->get()
            ->map(fn (Anime $entry) => $this->resultRow($entry, $matcher->score($show->name, $entry->titles()), $matcher->seasonOf($entry->titles())))
            ->sortBy([
                ['score', 'desc'],
                fn (array $a, array $b) => ($b['seasonNumber'] === $season) <=> ($a['seasonNumber'] === $season),
                ['seasonYear', 'desc'],
                ['id', 'asc'],
            ])
            ->take(self::MAX_RESULTS)
            ->values()
            ->all();

        return response()->json([
            'query' => $query,
            'searchedQuery' => $searchedQuery,
            'season' => $season,
            'results' => $rows,
            'providerSearched' => $providerSearched,
            'providerError' => $providerError,
        ]);
    }

    /**
     * Candidates automatic matching found too close to call, best first. The
     * manual-link dialog shows these before its search results.
     */
    public function suggestions(Show $show, AnimeMatcher $matcher): JsonResponse
    {
        $suggestions = ShowAnimeSuggestion::query()
            ->where('show_id', $show->id)
            ->with(['anime' => fn ($q) => $this->withResultRelations($q)])
            ->orderByDesc('score')
            ->orderBy('anime_id')
            ->get();

        return response()->json([
            'suggestions' => $suggestions
                ->map(fn (ShowAnimeSuggestion $suggestion) => [
                    ...$this->resultRow($suggestion->anime, $suggestion->score, $matcher->seasonOf($suggestion->anime->titles())),
                    'rule' => $suggestion->rule->value,
                    'reason' => $suggestion->reason->value,
                    'createdAt' => $suggestion->created_at->toIso8601String(),
                ])
                ->values()
                ->all(),
        ]);
    }

    /** Rejects one suggestion: it's removed and the pair is never suggested or auto-linked again. */
    public function rejectSuggestion(Show $show, Anime $anime, ShowAnimeLinker $linker): RedirectResponse
    {
        if (! ShowAnimeSuggestion::where('show_id', $show->id)->where('anime_id', $anime->id)->exists()) {
            abort(404);
        }

        $linker->reject($show, $anime);

        $title = $anime->title_english ?? $anime->title_romaji ?? "#{$anime->id}";

        return back()->with('success', "\"{$title}\" won't be suggested for \"{$show->name}\" again.");
    }

    public function store(Request $request, Show $show, ShowAnimeLinker $linker): RedirectResponse
    {
        $validated = $request->validate(['anime_id' => ['required', 'integer', 'exists:anime,id']]);
        $anime = Anime::findOrFail($validated['anime_id']);

        $linker->linkManually($show, $anime);
        FetchAnimeCover::dispatchIfMissing($anime->id);

        $title = $anime->title_english ?? $anime->title_romaji ?? "#{$anime->id}";

        return back()->with('success', "Linked \"{$show->name}\" to \"{$title}\".");
    }

    public function destroy(Show $show, ShowAnimeLinker $linker): RedirectResponse
    {
        return $linker->unlink($show)
            ? back()->with('success', "Unlinked \"{$show->name}\". That match won't be made automatically again.")
            : back()->with('success', "\"{$show->name}\" wasn't linked.");
    }

    /**
     * @param  Builder<Anime>|Relation<Anime, *, *>  $query
     * @return Builder<Anime>|Relation<Anime, *, *>
     */
    private function withResultRelations(Builder|Relation $query): Builder|Relation
    {
        return $query->with([
            'image' => fn ($q) => $q->select(['id', 'anime_id', 'sha256', 'width', 'height']),
            'links.show' => fn ($q) => $q->select(['id', 'name']),
        ]);
    }

    /**
     * One search result or suggestion. Expects `image` and `links.show` loaded.
     *
     * @return array<string, mixed>
     */
    private function resultRow(Anime $anime, int $score, int $seasonNumber): array
    {
        return [
            'id' => $anime->id,
            'titleRomaji' => $anime->title_romaji,
            'titleEnglish' => $anime->title_english,
            'titleNative' => $anime->title_native,
            'season' => $anime->season,
            'seasonYear' => $anime->season_year,
            'format' => $anime->format,
            'episodesTotal' => $anime->episodes_total,
            'coverUrl' => $anime->image?->url(),
            'coverWidth' => $anime->image?->width,
            'coverHeight' => $anime->image?->height,
            // The season number the titles imply (1 when none says otherwise): "S4" badges, ranking.
            'seasonNumber' => $seasonNumber,
            'score' => $score,
            'linkedShows' => $anime->links->map(fn (ShowAnimeLink $link) => ['id' => $link->show->id, 'name' => $link->show->name])->values()->all(),
        ];
    }

    /**
     * Every word of the (marker-free) query must appear in some title or synonym.
     *
     * @return Collection<int, Anime>
     */
    private function localSearch(string $normalizedBase): Collection
    {
        $words = array_filter(explode(' ', $normalizedBase), fn (string $word) => $word !== '');

        if ($words === []) {
            return new Collection;
        }

        $query = Anime::query()->select('id');

        foreach ($words as $word) {
            // Words are letters/digits only after normalization, so no LIKE escaping is needed.
            $query->whereRaw(
                "lower(concat_ws(' ', title_romaji, title_english, title_native, synonyms::text)) like ?",
                ['%'.$word.'%'],
            );
        }

        return $query->limit(50)->get();
    }
}
