<?php

declare(strict_types=1);

namespace App\Services\Premiere;

use App\Enums\PremiereSource;
use App\Enums\Season;
use App\Models\Release;
use App\Models\Show;
use App\Services\SubsPlease\SearchResultItem;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Throwable;

final class PremiereCalculator
{
    private const SEASON_TIMEZONE = 'Asia/Tokyo';

    /**
     * Source 2/3: an episode "01"/"1" release beats the earliest release seen at all.
     */
    public function fromEpisode1OrEarliest(Show $show): ?PremiereResult
    {
        $releases = $show->releases()->get();

        $episode1 = $releases->first(fn (Release $release) => $this->isEpisodeOne($release->episode));

        if ($episode1 !== null) {
            return new PremiereResult($episode1->published_at, PremiereSource::Episode1);
        }

        $earliest = $releases->sortBy('published_at')->first();

        if ($earliest === null) {
            return null;
        }

        return new PremiereResult($earliest->published_at, PremiereSource::EarliestSeen);
    }

    /**
     * Source 1: SubsPlease's own search results for the matched show, which carry
     * a release_date per episode. Episode "01"/"1" wins; otherwise the earliest.
     *
     * @param  array<int, SearchResultItem>  $candidates  items already filtered to the matched show
     */
    public function fromSubsPlease(array $candidates): ?PremiereResult
    {
        $episode1Date = null;
        $earliestDate = null;

        foreach ($candidates as $item) {
            $date = $this->parseReleaseDate($item->releaseDate);

            if ($date === null) {
                continue;
            }

            if ($episode1Date === null && $this->isEpisodeOne($item->episode)) {
                $episode1Date = $date;
            }

            if ($earliestDate === null || $date->lessThan($earliestDate)) {
                $earliestDate = $date;
            }
        }

        $premieredAt = $episode1Date ?? $earliestDate;

        if ($premieredAt === null) {
            return null;
        }

        return new PremiereResult($premieredAt, PremiereSource::SubsPlease);
    }

    /**
     * Writes the result to the show, unless it already has a strictly better source.
     * Returns whether the show was updated.
     */
    public function apply(Show $show, ?PremiereResult $result): bool
    {
        if ($result === null) {
            return false;
        }

        if ($show->premiere_source !== null && $show->premiere_source->rank() > $result->source->rank()) {
            return false;
        }

        [$season, $year] = $this->seasonFor($result->premieredAt);

        $show->update([
            'premiered_at' => $result->premieredAt,
            'premiere_source' => $result->source,
            'season' => $season,
            'season_year' => $year,
        ]);

        return true;
    }

    /**
     * @return array{0: Season, 1: int}
     */
    public function seasonFor(CarbonInterface $premieredAt): array
    {
        $tokyo = $premieredAt->copy()->setTimezone(self::SEASON_TIMEZONE);

        $season = match (true) {
            $tokyo->month <= 3 => Season::Winter,
            $tokyo->month <= 6 => Season::Spring,
            $tokyo->month <= 9 => Season::Summer,
            default => Season::Autumn,
        };

        return [$season, $tokyo->year];
    }

    private function isEpisodeOne(?string $episode): bool
    {
        return $episode === '01' || $episode === '1';
    }

    private function parseReleaseDate(?string $raw): ?CarbonInterface
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
