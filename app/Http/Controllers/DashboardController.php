<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\LastPollResource;
use App\Http\Resources\LatestReleaseResource;
use App\Models\FeedPoll;
use App\Models\Release;
use App\Services\QBittorrent\QbitHealthCheck;
use App\Services\Stats\PollingDelay;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(QbitHealthCheck $healthCheck, PollingDelay $pollingDelay): Response
    {
        $qbit = $healthCheck->check();
        $lastPoll = FeedPoll::query()->latest('id')->first();
        $nextPollAt = Cache::get('feed:next_poll_at');

        return Inertia::render('Dashboard', [
            'health' => [
                'qbit' => [
                    'reachable' => $qbit->reachable,
                    'version' => $qbit->version,
                    'webapi' => $qbit->webapi,
                    'auth' => $qbit->auth,
                    'feed' => $qbit->feed,
                    'prefs' => $qbit->prefs,
                    'category' => $qbit->category,
                ],
                'lastPoll' => $lastPoll === null ? null : (new LastPollResource($lastPoll))->resolve(),
                'nextPollAt' => $nextPollAt?->toIso8601String(),
                'pollMode' => 'base',
                'driver' => config('subtracker.qbittorrent.mode'),
                'delay' => $pollingDelay->recent(),
            ],
            'latestReleases' => $this->latestReleases(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function latestReleases(): array
    {
        $releases = Release::query()
            ->with(['show.image' => fn ($query) => $query->select(['id', 'show_id', 'sha256'])])
            ->orderByDesc('published_at')
            ->orderByDesc('first_seen_at')
            ->take(30)
            ->get();

        // One grouped query for every show on the page, instead of one per release.
        $earliestByShow = Release::query()
            ->whereIn('show_id', $releases->pluck('show_id')->filter()->unique())
            ->groupBy('show_id')
            ->selectRaw('show_id, min(published_at) as earliest')
            ->pluck('earliest', 'show_id');

        return $releases
            ->map(fn (Release $release) => (new LatestReleaseResource(
                $release,
                isset($earliestByShow[$release->show_id]) ? Carbon::parse($earliestByShow[$release->show_id]) : null,
            ))->resolve())
            ->all();
    }
}
