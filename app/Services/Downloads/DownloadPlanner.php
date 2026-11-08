<?php

declare(strict_types=1);

namespace App\Services\Downloads;

use App\Enums\DispatchStatus;
use App\Enums\TrackingMode;
use App\Models\Release;
use App\Models\Show;
use App\Services\Releases\ReleaseTitleParser;
use Illuminate\Database\Eloquent\Collection;

final class DownloadPlanner
{
    /**
     * The releases Torii would still queue for a show: distinct batches (newest per
     * range) and distinct single episodes (per episode number compared by value:
     * SubsPlease first, then highest version, then newest; every special
     * individually), excluding anything already sent or confirmed to exist, and
     * any single episode another group's release of the same number has already
     * covered (sent, exists or downloaded): one episode is never fetched twice
     * across groups (§6.4).
     *
     * @return Collection<int, Release>
     */
    public function downloadableSet(Show $show): Collection
    {
        $all = $show->releases()->get();

        $releases = $all->reject(fn (Release $release) => $this->isDispatched($release));

        $batches = $this->dedupeBatches($releases->where('is_batch', true));
        $singles = $this->dedupeSingles($this->withoutOtherGroupsEpisodes($releases->where('is_batch', false), $all));

        return $batches->merge($singles)->values();
    }

    public function plan(Show $show): DownloadPlan
    {
        $downloadable = $this->downloadableSet($show);

        $batches = $downloadable->where('is_batch', true)->values();
        $singles = $downloadable->where('is_batch', false)->values();

        if ($batches->isEmpty()) {
            return new DownloadPlan(
                releaseIds: $singles->pluck('id')->all(),
                createRule: true,
                trackingMode: TrackingMode::Rule,
            );
        }

        $coversEverythingKnown = $batches->contains(
            fn (Release $batch) => $batch->batch_from === null || $batch->batch_to === null
        );

        if ($coversEverythingKnown) {
            return new DownloadPlan(
                releaseIds: $batches->pluck('id')->all(),
                createRule: false,
                trackingMode: TrackingMode::Batch,
            );
        }

        $highestBatchTo = (int) $batches->max('batch_to');

        $laterSingles = $singles->filter(function (Release $release) use ($highestBatchTo) {
            if ($release->episode === null) {
                return true;
            }

            return is_numeric($release->episode) && (float) $release->episode > $highestBatchTo;
        });

        $createRule = $laterSingles->isNotEmpty();

        return new DownloadPlan(
            releaseIds: $batches->merge($laterSingles)->pluck('id')->all(),
            createRule: $createRule,
            trackingMode: $createRule ? TrackingMode::Rule : TrackingMode::Batch,
        );
    }

    /**
     * @param  Collection<int, Release>  $batches
     * @return Collection<int, Release>
     */
    private function dedupeBatches(Collection $batches): Collection
    {
        return $batches
            ->groupBy(fn (Release $release) => $release->batch_from.'-'.$release->batch_to)
            ->map(fn (Collection $group) => $this->newest($group))
            ->values();
    }

    /**
     * @param  Collection<int, Release>  $singles
     * @return Collection<int, Release>
     */
    private function dedupeSingles(Collection $singles): Collection
    {
        $numbered = $singles->filter(fn (Release $release) => $release->episode !== null);
        $specials = $singles->filter(fn (Release $release) => $release->episode === null);

        $dedupedNumbered = $numbered
            ->groupBy(fn (Release $release) => Release::episodeKey($release->episode))
            ->map(fn (Collection $group) => $this->bestVersion($group))
            ->values();

        return $dedupedNumbered->merge($specials)->values();
    }

    /**
     * Drops single episodes whose number another group's release already covers
     * (sent, exists or downloaded). The same group is left alone: a higher
     * version of an episode still counts as new.
     *
     * @param  Collection<int, Release>  $singles  the undispatched single episodes
     * @param  Collection<int, Release>  $all  every release of the show
     * @return Collection<int, Release>
     */
    private function withoutOtherGroupsEpisodes(Collection $singles, Collection $all): Collection
    {
        $held = [];

        foreach ($all as $release) {
            if (! $release->is_batch && $release->episode !== null && ($this->isDispatched($release) || $release->downloaded_at !== null)) {
                $held[Release::episodeKey($release->episode)][(string) $release->effectiveGroup()] = true;
            }
        }

        if ($held === []) {
            return $singles;
        }

        return $singles->reject(function (Release $release) use ($held) {
            if ($release->episode === null) {
                return false;
            }

            $groups = $held[Release::episodeKey($release->episode)] ?? [];
            unset($groups[(string) $release->effectiveGroup()]);

            return $groups !== [];
        });
    }

    private function isDispatched(Release $release): bool
    {
        return in_array($release->dispatch_status, [DispatchStatus::Sent, DispatchStatus::Exists], true);
    }

    /**
     * @param  Collection<int, Release>  $group
     */
    private function newest(Collection $group): Release
    {
        return $group->reduce(
            fn (?Release $carry, Release $release) => $carry === null || $release->published_at->gt($carry->published_at)
                ? $release
                : $carry
        );
    }

    /**
     * @param  Collection<int, Release>  $group
     */
    private function bestVersion(Collection $group): Release
    {
        return $group->reduce(function (?Release $carry, Release $release) {
            if ($carry === null) {
                return $release;
            }

            // SubsPlease first: the episode the rule would have fetched.
            $releaseIsSubsPlease = $release->effectiveGroup() === ReleaseTitleParser::SUBSPLEASE;
            $carryIsSubsPlease = $carry->effectiveGroup() === ReleaseTitleParser::SUBSPLEASE;

            if ($releaseIsSubsPlease !== $carryIsSubsPlease) {
                return $releaseIsSubsPlease ? $release : $carry;
            }

            $releaseVersion = $release->version ?? 0;
            $carryVersion = $carry->version ?? 0;

            if ($releaseVersion !== $carryVersion) {
                return $releaseVersion > $carryVersion ? $release : $carry;
            }

            return $release->published_at->gt($carry->published_at) ? $release : $carry;
        });
    }
}
