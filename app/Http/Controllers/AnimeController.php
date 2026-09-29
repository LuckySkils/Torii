<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AnimeSeason;
use App\Http\Resources\AnimeResource;
use App\Jobs\FetchAnimeCover;
use App\Models\Anime;
use App\Models\AnimeAiring;
use App\Models\AnimeImage;
use App\Services\Metadata\AnimeFacets;
use App\Services\Metadata\AnimeSeasons;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class AnimeController extends Controller
{
    private const CARD_CACHE_SECONDS = 120;

    private const CARD_DESCRIPTION_LENGTH = 600;

    /**
     * Browsable season list. With no `season`/`year` params at all it opens on the
     * current season; an explicitly empty one means "any".
     *
     * Multi-select filters: `format[]` (any of them), `genres_include[]` (all of
     * them), `genres_exclude[]` (none of them); each also accepts a comma list.
     * The old single `genre=` still works and counts as an include.
     * `adult=hide` (default) | `include` | `only` for AniList's isAdult entries.
     *
     * Entries on the page without a stored cover get one fetched on demand.
     */
    public function index(Request $request, AnimeSeasons $seasons, AnimeFacets $facets): InertiaResponse
    {
        [$currentSeason, $currentYear] = $seasons->current();

        $search = trim((string) $request->query('q', ''));
        $season = $request->has('season') ? $this->nullableString($request->query('season')) : $currentSeason->value;
        $year = $request->has('year') ? $this->nullableInt($request->query('year')) : $currentYear;
        $status = $this->nullableString($request->query('status'));
        $linked = (string) $request->query('linked', 'all');
        $formats = $this->formatsParam($request);
        $include = array_values(array_unique([...$this->listParam($request, 'genres_include'), ...$this->listParam($request, 'genre')]));
        $exclude = $this->listParam($request, 'genres_exclude');
        $adult = in_array($request->query('adult'), ['include', 'only'], true) ? (string) $request->query('adult') : 'hide';

        $season = $season === null ? null : strtoupper($season);

        $query = Anime::query()
            ->with($this->listRelations())
            ->when($search !== '', fn (Builder $q) => $q->matchingTitle($search))
            ->when($season !== null, fn (Builder $q) => $q->where('season', $season))
            ->when($year !== null, fn (Builder $q) => $q->where('season_year', $year))
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status))
            ->when($formats !== [], fn (Builder $q) => $q->whereIn('format', $formats))
            ->withAllGenres($include)
            ->withoutGenres($exclude)
            ->when($linked === 'yes', fn (Builder $q) => $q->whereHas('links'))
            ->when($linked === 'no', fn (Builder $q) => $q->whereDoesntHave('links'))
            ->when($adult === 'hide', fn (Builder $q) => $q->where('is_adult', false))
            ->when($adult === 'only', fn (Builder $q) => $q->where('is_adult', true))
            ->orderByRaw('title_romaji ASC NULLS LAST')
            ->orderBy('id');

        $page = $query->paginate(30)->withQueryString();
        $this->requestMissingCovers($page->getCollection()->all());

        return Inertia::render('Anime/Index', [
            'anime' => AnimeResource::collection($page),
            'filters' => [
                'q' => $search,
                'season' => $season,
                'year' => $year,
                'status' => $status,
                'linked' => $linked,
                'format' => $formats,
                'genresInclude' => $include,
                'genresExclude' => $exclude,
                'adult' => $adult,
            ],
            'filterOptions' => [
                'seasons' => array_map(fn (AnimeSeason $s) => $s->value, AnimeSeason::cases()),
                'years' => Anime::query()->whereNotNull('season_year')->distinct()->orderByDesc('season_year')->pluck('season_year')->all(),
                'formats' => $facets->formats(),
                'genres' => $facets->genres(),
                'statuses' => Anime::query()->whereNotNull('status')->distinct()->orderBy('status')->pluck('status')->all(),
            ],
        ]);
    }

    /**
     * Queues a cover for each shown entry that has none and hasn't failed before;
     * repeated views within 10 minutes, or an already-queued fetch, add nothing.
     *
     * @param  array<int, Anime>  $anime  with `image` loaded
     */
    private function requestMissingCovers(array $anime): void
    {
        foreach ($anime as $entry) {
            if ($entry->image === null) {
                FetchAnimeCover::requestOnDemand($entry);
            }
        }
    }

    /**
     * @return array<int, string> known AniList formats only, uppercase
     */
    private function formatsParam(Request $request): array
    {
        return array_values(array_intersect(array_map('strtoupper', $this->listParam($request, 'format')), Anime::FORMATS));
    }

    public function show(Anime $anime): InertiaResponse
    {
        $anime->load([...$this->listRelations(), 'externalIds', 'links.show.image' => fn ($q) => $q->select(['id', 'show_id', 'sha256'])]);
        $this->requestMissingCovers([$anime]);

        return Inertia::render('Anime/Show', [
            'anime' => (new AnimeResource($anime))->detail()->resolve(),
            // Newest first (§11); the airingWindow on `anime` covers "what's around now".
            'airings' => $anime->airings()
                ->orderByDesc('episode')
                ->get()
                ->map(fn (AnimeAiring $airing) => [
                    'episode' => $airing->episode,
                    'airsAt' => $airing->airs_at->toIso8601String(),
                    'isEstimate' => $airing->is_estimate,
                    'provider' => $airing->provider,
                ])
                ->all(),
            'linkedShows' => $anime->links->map(fn ($link) => [
                'id' => $link->show->id,
                'name' => $link->show->name,
                'imageUrl' => $link->show->image?->url(),
                'isTracked' => $link->show->is_tracked,
                'linkSource' => $link->source->value,
                'confidence' => $link->confidence,
                'linkedAt' => $link->linked_at->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    /**
     * A small JSON summary for the schedule's hover cards; the schedule rows stay
     * light and this fills in the rest on demand. Cached for CARD_CACHE_SECONDS,
     * so repeated hovers don't query at all (hence an id, not route model binding).
     * `description` is AniList's HTML as stored, cut to about CARD_DESCRIPTION_LENGTH
     * characters; the frontend sanitizes it.
     */
    public function card(int $id): JsonResponse
    {
        $card = Cache::remember("anime:card:{$id}", self::CARD_CACHE_SECONDS, function () use ($id): ?array {
            $anime = Anime::query()
                ->select(['id', 'title_romaji', 'title_english', 'format', 'status', 'season', 'season_year', 'episodes_total', 'duration_minutes', 'description', 'genres', 'is_adult', 'site_url'])
                ->with([
                    // Only what the cover URL and dimensions need, never `data`.
                    'image' => fn ($q) => $q->select(['id', 'anime_id', 'sha256', 'width', 'height']),
                    'links.show' => fn ($q) => $q->select(['id', 'name', 'is_tracked']),
                ])
                ->find($id);

            if ($anime === null) {
                return null;
            }

            [$description, $truncated] = $this->truncateHtml($anime->description, self::CARD_DESCRIPTION_LENGTH);
            $show = $anime->primaryLinkedShow();

            return [
                'id' => $anime->id,
                'titleRomaji' => $anime->title_romaji,
                'titleEnglish' => $anime->title_english,
                'coverUrl' => $anime->image?->url(),
                'coverWidth' => $anime->image?->width,
                'coverHeight' => $anime->image?->height,
                'format' => $anime->format,
                'status' => $anime->status,
                'season' => $anime->season,
                'seasonYear' => $anime->season_year,
                'episodesTotal' => $anime->episodes_total,
                'durationMinutes' => $anime->duration_minutes,
                'description' => $description,
                'descriptionTruncated' => $truncated,
                'genres' => $anime->genres ?? [],
                'isAdult' => $anime->is_adult,
                'siteUrl' => $anime->site_url,
                'linkedShow' => $show === null ? null : ['id' => $show->id, 'name' => $show->name, 'isTracked' => $show->is_tracked],
            ];
        });

        if ($card === null) {
            abort(404);
        }

        return response()->json($card);
    }

    /**
     * Cuts HTML to about $length characters without leaving half a tag: back to
     * before an unclosed `<`, then to the last word break. Elements left open are
     * closed by the frontend's sanitizer.
     *
     * @return array{0: string|null, 1: bool}
     */
    private function truncateHtml(?string $html, int $length): array
    {
        if ($html === null || mb_strlen($html) <= $length) {
            return [$html, false];
        }

        $cut = mb_substr($html, 0, $length);

        $open = mb_strrpos($cut, '<');
        if ($open !== false && mb_strrpos($cut, '>') < $open) {
            $cut = mb_substr($cut, 0, $open);
        }

        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $length * 0.8) {
            $cut = mb_substr($cut, 0, $space);
        }

        return [rtrim($cut), true];
    }

    /** Serves the stored cover, exactly like ImageController::show serves posters. */
    public function cover(Request $request, Anime $anime): Response
    {
        $image = AnimeImage::where('anime_id', $anime->id)->first();

        if ($image === null) {
            abort(404);
        }

        $etag = '"'.$image->sha256.'"';

        if ($request->header('If-None-Match') === $etag) {
            return response('', 304)
                ->header('ETag', $etag)
                ->header('Cache-Control', 'public, max-age=31536000, immutable');
        }

        return response(base64_decode($image->data), 200)
            ->header('Content-Type', $image->mime)
            ->header('ETag', $etag)
            ->header('Cache-Control', 'public, max-age=31536000, immutable');
    }

    /**
     * @return array<int|string, mixed>
     */
    private function listRelations(): array
    {
        return [
            'image' => fn ($q) => $q->select(['id', 'anime_id', 'sha256', 'width', 'height']),
            'nextAiring',
            'links.show' => fn ($q) => $q->select(['id', 'name', 'is_tracked']),
        ];
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
}
