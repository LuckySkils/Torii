<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ReleaseResource;
use App\Http\Resources\ShowResource;
use App\Models\Anime;
use App\Models\Release;
use App\Models\Show;
use App\Models\ShowAnimeSuggestion;
use App\Services\Metadata\AnimeFacets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShowController extends Controller
{
    /**
     * Besides its own filters, `format[]`, `genres_include[]` and `genres_exclude[]`
     * filter through the linked anime, exactly as on /anime. Format and include
     * need a link; exclude drops only shows whose linked anime has an excluded
     * genre, so unlinked shows stay listed.
     */
    public function index(Request $request, AnimeFacets $facets): Response
    {
        $search = trim((string) $request->query('q', ''));
        $tracked = (string) $request->query('tracked', 'all');
        $sort = (string) $request->query('sort', 'name');
        $season = $this->nullableString($request->query('season'));
        $year = $this->nullableInt($request->query('year'));
        // review=1: only shows with link suggestions waiting for a decision.
        $review = $request->boolean('review');
        $formats = array_values(array_intersect(array_map('strtoupper', $this->listParam($request, 'format')), Anime::FORMATS));
        $include = $this->listParam($request, 'genres_include');
        $exclude = $this->listParam($request, 'genres_exclude');

        $query = Show::query()->with([
            'latestRelease',
            'animeLink.anime.image' => fn ($query) => $query->select(['id', 'anime_id', 'sha256', 'width', 'height']),
        ])->withExists('animeSuggestions');

        if ($search !== '') {
            $query->whereLike('name', '%'.$this->escapeLikeValue($search).'%');
        }

        if ($tracked === 'yes') {
            $query->where('is_tracked', true);
        } elseif ($tracked === 'no') {
            $query->where('is_tracked', false);
        }

        if ($season !== null) {
            $query->where('season', $season);
        }

        if ($year !== null) {
            $query->where('season_year', $year);
        }

        if ($review) {
            $query->whereHas('animeSuggestions');
        }

        if ($formats !== []) {
            $query->whereHas('animeLink.anime', fn ($anime) => $anime->whereIn('format', $formats));
        }

        if ($include !== []) {
            $query->whereHas('animeLink.anime', fn ($anime) => $anime->withAllGenres($include));
        }

        if ($exclude !== []) {
            $query->whereDoesntHave('animeLink.anime', fn ($anime) => $anime->withAnyGenre($exclude));
        }

        match ($sort) {
            'last_seen' => $query->orderByDesc('last_seen_at'),
            'premiered' => $query->orderByRaw('premiered_at DESC NULLS LAST'),
            default => $query->orderBy('name'),
        };

        return Inertia::render('Shows/Index', [
            'shows' => ShowResource::collection($query->paginate(25)->withQueryString()),
            'filters' => [
                'q' => $search,
                'tracked' => $tracked,
                'sort' => $sort,
                'season' => $season,
                'year' => $year,
                'review' => $review,
                'format' => $formats,
                'genresInclude' => $include,
                'genresExclude' => $exclude,
            ],
            'filterOptions' => [
                'years' => Show::query()
                    ->whereNotNull('season_year')
                    ->distinct()
                    ->orderByDesc('season_year')
                    ->pluck('season_year')
                    ->all(),
                // How many shows the review filter would show, whatever the other filters.
                'reviewCount' => ShowAnimeSuggestion::query()->distinct()->count('show_id'),
                // Counted over shows' linked anime.
                'formats' => $facets->formats(linkedShowsOnly: true),
                'genres' => $facets->genres(linkedShowsOnly: true),
            ],
        ]);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = $this->nullableString($value);

        return $value === null ? null : (int) $value;
    }

    private function escapeLikeValue(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    public function show(Show $show): Response
    {
        return Inertia::render('Shows/Show', [
            'show' => (new ShowResource($show))->withFullAnime()->resolve(),
            'releases' => $show->releases()->latest('published_at')->get()
                ->map(fn (Release $release) => (new ReleaseResource($release))->resolve())
                ->all(),
        ]);
    }
}
