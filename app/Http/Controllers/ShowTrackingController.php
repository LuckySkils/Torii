<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\TrackShow;
use App\Actions\UntrackShow;
use App\Enums\RuleState;
use App\Jobs\QueueReleases;
use App\Models\Show;
use App\Services\Downloads\DownloadPlanner;
use App\Services\QBittorrent\RulesDriver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class ShowTrackingController extends Controller
{
    public function track(Request $request, Show $show, TrackShow $trackShow, UntrackShow $untrackShow): RedirectResponse
    {
        $data = $request->validate(['tracked' => 'required|boolean']);

        if ($data['tracked']) {
            $trackShow($show);

            return back()->with('success', "Now tracking \"{$show->name}\".");
        }

        $untrackShow($show);

        return back()->with('success', "Stopped tracking \"{$show->name}\".");
    }

    public function trackBulk(Request $request, TrackShow $trackShow, UntrackShow $untrackShow): RedirectResponse
    {
        $data = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:shows,id',
            'tracked' => 'required|boolean',
        ]);

        $shows = Show::whereIn('id', $data['ids'])->get();

        foreach ($shows as $show) {
            if ($data['tracked']) {
                $trackShow($show);
            } else {
                $untrackShow($show);
            }
        }

        $verb = $data['tracked'] ? 'Tracking' : 'Untracking';

        return back()->with('success', "{$verb} {$shows->count()} shows.");
    }

    public function deleteRule(Show $show, RulesDriver $driver): RedirectResponse
    {
        try {
            $driver->deleteRule($show);
        } catch (Throwable $e) {
            return back()->with('error', "Failed to delete the qBit rule for \"{$show->name}\": {$e->getMessage()}");
        }

        $show->update([
            'rule_state' => RuleState::None,
            'rule_synced_at' => null,
            'rule_error' => null,
        ]);

        return back()->with('success', "Deleted the qBit rule for \"{$show->name}\".");
    }

    public function matches(Show $show, RulesDriver $driver): JsonResponse
    {
        return response()->json($driver->matchingArticles($show));
    }

    public function queueMissing(Show $show, DownloadPlanner $planner): RedirectResponse
    {
        $releaseIds = $planner->downloadableSet($show)->pluck('id')->all();

        if ($releaseIds === []) {
            return back()->with('success', 'Nothing to queue.');
        }

        QueueReleases::dispatch($releaseIds);

        return back()->with('success', 'Queued '.count($releaseIds).' releases.');
    }
}
