<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AnimeSeason;
use App\Models\Anime;
use App\Models\AnimeAiring;
use App\Models\Release;
use App\Services\Metadata\AnimeFacets;
use App\Services\Metadata\Matching\AnimeMatcher;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The planned schedule, from `anime_airings` (filled by the daily airing sync);
 * no external calls. Every anime with airings in the range appears, linked or
 * not; `linked` and `tracked` are filters the user applies, never implied.
 */
class ScheduleController extends Controller
{
    private const MAX_RANGE_DAYS = 45;

    private const DEFAULT_RANGE_DAYS = 7;

    /** An anime that started this recently counts as a new series. */
    private const NEW_SERIES_DAYS = 14;

    public function index(Request $request, AnimeFacets $facets): Response
    {
        [$from, $to] = $this->range($request);

        // Absent means "any" here (unlike /anime, which opens on the current season).
        $season = $this->nullableUpper($request->query('season'));
        $year = is_numeric($request->query('year')) ? (int) $request->query('year') : null;
        $formats = array_values(array_intersect(array_map('strtoupper', $this->listParam($request, 'format')), Anime::FORMATS));
        $linked = in_array($request->query('linked'), ['linked', 'unlinked'], true) ? (string) $request->query('linked') : 'all';
        $tracked = $request->query('tracked') === 'tracked' ? 'tracked' : 'all';
        $adult = in_array($request->query('adult'), ['include', 'only'], true) ? (string) $request->query('adult') : 'hide';

        $airings = AnimeAiring::query()
            // [from, to): consecutive days or weeks never share an airing.
            ->where('airs_at', '>=', $from)
            ->where('airs_at', '<', $to)
            ->whereHas('anime', function (Builder $anime) use ($season, $year, $formats, $linked, $tracked, $adult) {
                $anime
                    ->when($season !== null, fn (Builder $q) => $q->where('season', $season))
                    ->when($year !== null, fn (Builder $q) => $q->where('season_year', $year))
                    ->when($formats !== [], fn (Builder $q) => $q->whereIn('format', $formats))
                    ->when($linked === 'linked', fn (Builder $q) => $q->whereHas('links'))
                    ->when($linked === 'unlinked', fn (Builder $q) => $q->whereDoesntHave('links'))
                    ->when($tracked === 'tracked', fn (Builder $q) => $q->whereHas('links.show', fn (Builder $show) => $show->where('is_tracked', true)))
                    ->when($adult === 'hide', fn (Builder $q) => $q->where('is_adult', false))
                    ->when($adult === 'only', fn (Builder $q) => $q->where('is_adult', true));
            })
            ->with([
                'anime' => fn ($q) => $q->select(['id', 'title_romaji', 'title_english', 'format', 'status', 'episodes_total', 'season', 'season_year', 'is_adult', 'start_date']),
                // Only what the cover URL and dimensions need, never `data`.
                'anime.image' => fn ($q) => $q->select(['id', 'anime_id', 'sha256', 'width', 'height']),
                'anime.links.show' => fn ($q) => $q->select(['id', 'name', 'is_tracked']),
            ])
            ->orderBy('airs_at')
            ->orderBy('anime_id')
            ->orderBy('episode')
            ->get(['id', 'anime_id', 'episode', 'airs_at', 'is_estimate']);

        $showIds = $airings
            ->flatMap(fn (AnimeAiring $airing) => $airing->anime->links->pluck('show_id'))
            ->unique()
            ->values()
            ->all();
        $releases = $this->releasesByShow($showIds);
        $highestEpisodes = Release::highestEpisodes($showIds);
        $now = now();

        return Inertia::render('Schedule/Index', [
            'airings' => $airings->map(fn (AnimeAiring $airing) => $this->row($airing, $releases, $highestEpisodes, $now))->values()->all(),
            'filters' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'season' => $season,
                'year' => $year,
                'format' => $formats,
                'linked' => $linked,
                'tracked' => $tracked,
                'adult' => $adult,
            ],
            'filterOptions' => [
                'seasons' => array_map(fn (AnimeSeason $s) => $s->value, AnimeSeason::cases()),
                'years' => Anime::query()->whereNotNull('season_year')->distinct()->orderByDesc('season_year')->pluck('season_year')->all(),
                'formats' => $facets->formats(),
            ],
        ]);
    }

    /**
     * @param  Collection<int, Collection<int, Release>>  $releases  keyed by show id
     * @param  array<int, float>  $highestEpisodes  keyed by show id
     * @return array<string, mixed>
     */
    private function row(AnimeAiring $airing, Collection $releases, array $highestEpisodes, CarbonInterface $now): array
    {
        $anime = $airing->anime;
        $show = $anime->primaryLinkedShow();

        return [
            'episode' => $airing->episode,
            'airsAt' => $airing->airs_at->toIso8601String(),
            'isEstimate' => $airing->is_estimate,
            'anime' => [
                'id' => $anime->id,
                'titleRomaji' => $anime->title_romaji,
                'titleEnglish' => $anime->title_english,
                'coverUrl' => $anime->image?->url(),
                'coverWidth' => $anime->image?->width,
                'coverHeight' => $anime->image?->height,
                'format' => $anime->format,
                'status' => $anime->status,
                'episodesTotal' => $anime->episodes_total,
                'season' => $anime->season,
                'seasonYear' => $anime->season_year,
                'isAdult' => $anime->is_adult,
            ],
            'show' => $show === null ? null : ['id' => $show->id, 'name' => $show->name, 'isTracked' => $show->is_tracked],
            'releaseState' => $show === null || $airing->airs_at->isAfter($now) || $this->numberingDiffers($anime, $highestEpisodes[$show->id] ?? null)
                ? null
                : $this->releaseState($releases->get($show->id) ?? new Collection, $airing->episode),
            'isNewSeries' => $airing->episode === 1
                || ($anime->start_date !== null && $anime->start_date->betweenIncluded($now->copy()->subDays(self::NEW_SERIES_DAYS)->startOfDay(), $now)),
        ];
    }

    /**
     * The show's episodes run past the anime's total (beyond the same tolerance
     * matching uses): SubsPlease numbers on from season 1, Hyakkano at 36 against
     * 12. AniList's episode numbers then say nothing about the show's releases, so
     * every state for that anime is unknown rather than a false `waiting`.
     */
    private function numberingDiffers(Anime $anime, ?float $highestEpisode): bool
    {
        return $anime->episodes_total !== null
            && $highestEpisode !== null
            && $highestEpisode > $anime->episodes_total + AnimeMatcher::EPISODE_TOLERANCE;
    }

    /**
     * `downloaded` when a release of this episode (a single one, or a batch whose
     * range covers it) has been downloaded; `released` when one exists; otherwise
     * `waiting`, since the airing is already past.
     *
     * @param  Collection<int, Release>  $releases
     * @return 'downloaded'|'released'|'waiting'
     */
    private function releaseState(Collection $releases, int $episode): string
    {
        $matching = $releases->filter(fn (Release $release) => $release->is_batch
            ? $release->batch_from !== null && $release->batch_to !== null && $episode >= $release->batch_from && $episode <= $release->batch_to
            : is_numeric($release->episode) && (float) $release->episode === (float) $episode);

        return match (true) {
            $matching->contains(fn (Release $release) => $release->downloaded_at !== null) => 'downloaded',
            $matching->isNotEmpty() => 'released',
            default => 'waiting',
        };
    }

    /**
     * Every linked show's releases, in one query.
     *
     * @param  array<int, int>  $showIds
     * @return Collection<int, Collection<int, Release>>
     */
    private function releasesByShow(array $showIds): Collection
    {
        if ($showIds === []) {
            return new Collection;
        }

        return Release::query()
            ->whereIn('show_id', $showIds)
            ->get(['id', 'show_id', 'episode', 'is_batch', 'batch_from', 'batch_to', 'downloaded_at'])
            ->groupBy('show_id');
    }

    /**
     * `from`/`to` as ISO 8601 (the frontend sends its local day or week in UTC).
     * Absent: now to now + 7 days; a missing `to` is `from` + 7 days.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     *
     * @throws ValidationException
     */
    private function range(Request $request): array
    {
        $from = $this->instant($request->query('from'), 'from') ?? CarbonImmutable::now();
        $to = $this->instant($request->query('to'), 'to') ?? $from->addDays(self::DEFAULT_RANGE_DAYS);

        if ($to->lessThanOrEqualTo($from)) {
            throw ValidationException::withMessages(['to' => 'The end of the range must be after its start.']);
        }

        if ($from->diffInSeconds($to) > self::MAX_RANGE_DAYS * 86400) {
            throw ValidationException::withMessages(['to' => 'The range can be at most '.self::MAX_RANGE_DAYS.' days.']);
        }

        return [$from->utc(), $to->utc()];
    }

    private function instant(mixed $value, string $field): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            throw ValidationException::withMessages([$field => "The {$field} date isn't a valid ISO 8601 time."]);
        }
    }

    private function nullableUpper(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : strtoupper($value);
    }
}
