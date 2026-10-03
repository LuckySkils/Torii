<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use App\Models\Anime;
use App\Models\AnimeAiring;
use App\Models\Release;
use App\Services\Metadata\AnimeFilters;
use App\Services\Metadata\Matching\AnimeMatcher;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The planned schedule from `anime_airings` (filled by the daily airing sync), no
 * external calls. Shared by /schedule and the MCP list_schedule tool. Every anime
 * with airings in the range appears, linked or not, unless a filter says otherwise.
 */
final class ScheduleBuilder
{
    public const MAX_RANGE_DAYS = 45;

    public const DEFAULT_RANGE_DAYS = 7;

    /** An anime that started this recently counts as a new series. */
    private const NEW_SERIES_DAYS = 14;

    /**
     * `from`/`to` as ISO 8601. Absent: now to now + 7 days; a missing `to` is
     * `from` + 7 days. Ranges over 45 days, reversed or unparseable are rejected.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     *
     * @throws ValidationException
     */
    public function range(mixed $from, mixed $to): array
    {
        $start = $this->instant($from, 'from') ?? CarbonImmutable::now();
        $end = $this->instant($to, 'to') ?? $start->addDays(self::DEFAULT_RANGE_DAYS);

        if ($end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages(['to' => 'The end of the range must be after its start.']);
        }

        if ($start->diffInSeconds($end) > self::MAX_RANGE_DAYS * 86400) {
            throw ValidationException::withMessages(['to' => 'The range can be at most '.self::MAX_RANGE_DAYS.' days.']);
        }

        return [$start->utc(), $end->utc()];
    }

    /**
     * Airings in [from, to), ascending; consecutive ranges never share one. Rows
     * from a fixed set of queries, whatever their number.
     *
     * @return array{total: int, rows: array<int, array<string, mixed>>}
     */
    public function airings(CarbonInterface $from, CarbonInterface $to, AnimeFilters $filters, ?int $limit = null, int $offset = 0): array
    {
        $query = AnimeAiring::query()
            ->where('airs_at', '>=', $from)
            ->where('airs_at', '<', $to)
            ->whereHas('anime', fn (Builder $anime) => $filters->apply($anime));

        $total = $limit === null ? null : (clone $query)->count();

        $airings = $query
            ->with([
                'anime' => fn ($q) => $q->select(['id', 'title_romaji', 'title_english', 'format', 'status', 'episodes_total', 'season', 'season_year', 'is_adult', 'start_date']),
                // Only what the cover URL and dimensions need, never `data`.
                'anime.image' => fn ($q) => $q->select(['id', 'anime_id', 'sha256', 'width', 'height']),
                'anime.links.show' => fn ($q) => $q->select(['id', 'name', 'is_tracked']),
            ])
            ->orderBy('airs_at')
            ->orderBy('anime_id')
            ->orderBy('episode')
            ->when($limit !== null, fn ($q) => $q->offset($offset)->limit((int) $limit))
            ->get(['id', 'anime_id', 'episode', 'airs_at', 'is_estimate']);

        $showIds = $airings
            ->flatMap(fn (AnimeAiring $airing) => $airing->anime->links->pluck('show_id'))
            ->unique()
            ->values()
            ->all();
        $releases = $this->releasesByShow($showIds);
        $highestEpisodes = Release::highestEpisodes($showIds);
        $now = now();

        return [
            'total' => $total ?? $airings->count(),
            'rows' => $airings->map(fn (AnimeAiring $airing) => $this->row($airing, $releases, $highestEpisodes, $now))->values()->all(),
        ];
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
    public function numberingDiffers(Anime $anime, ?float $highestEpisode): bool
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
}
