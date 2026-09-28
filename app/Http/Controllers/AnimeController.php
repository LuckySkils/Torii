<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AnimeSeason;
use App\Http\Resources\AnimeResource;
use App\Models\Anime;
use App\Models\AnimeAiring;
use App\Models\AnimeImage;
use App\Services\Metadata\AnimeSeasons;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class AnimeController extends Controller
{
    /**
     * Browsable season list. With no `season`/`year` params at all it opens on the
     * current season; an explicitly empty one means "any".
     */
    public function index(Request $request, AnimeSeasons $seasons): InertiaResponse
    {
        [$currentSeason, $currentYear] = $seasons->current();

        $season = $request->has('season') ? $this->nullableString($request->query('season')) : $currentSeason->value;
        $year = $request->has('year') ? $this->nullableInt($request->query('year')) : $currentYear;
        $status = $this->nullableString($request->query('status'));
        $genre = $this->nullableString($request->query('genre'));
        $linked = (string) $request->query('linked', 'all');

        $season = $season === null ? null : strtoupper($season);

        $query = Anime::query()
            ->with($this->listRelations())
            ->when($season !== null, fn (Builder $q) => $q->where('season', $season))
            ->when($year !== null, fn (Builder $q) => $q->where('season_year', $year))
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status))
            ->when($genre !== null, fn (Builder $q) => $q->whereJsonContains('genres', $genre))
            ->when($linked === 'yes', fn (Builder $q) => $q->whereHas('links'))
            ->when($linked === 'no', fn (Builder $q) => $q->whereDoesntHave('links'))
            ->orderByRaw('title_romaji ASC NULLS LAST')
            ->orderBy('id');

        return Inertia::render('Anime/Index', [
            'anime' => AnimeResource::collection($query->paginate(30)->withQueryString()),
            'filters' => [
                'season' => $season,
                'year' => $year,
                'status' => $status,
                'genre' => $genre,
                'linked' => $linked,
            ],
            'filterOptions' => [
                'seasons' => array_map(fn (AnimeSeason $s) => $s->value, AnimeSeason::cases()),
                'years' => Anime::query()->whereNotNull('season_year')->distinct()->orderByDesc('season_year')->pluck('season_year')->all(),
                'genres' => DB::query()
                    ->fromSub(Anime::query()->selectRaw('jsonb_array_elements_text(genres) as genre'), 'g')
                    ->distinct()
                    ->orderBy('genre')
                    ->pluck('genre')
                    ->all(),
                'statuses' => Anime::query()->whereNotNull('status')->distinct()->orderBy('status')->pluck('status')->all(),
            ],
        ]);
    }

    public function show(Anime $anime): InertiaResponse
    {
        $anime->load([...$this->listRelations(), 'externalIds', 'links.show.image' => fn ($q) => $q->select(['id', 'show_id', 'sha256'])]);

        return Inertia::render('Anime/Show', [
            'anime' => (new AnimeResource($anime))->detail()->resolve(),
            'airings' => $anime->airings()
                ->orderBy('episode')
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
