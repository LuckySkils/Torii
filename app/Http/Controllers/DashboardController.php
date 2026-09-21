<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\LastPollResource;
use App\Http\Resources\ReleaseResource;
use App\Models\FeedPoll;
use App\Models\Release;
use App\Services\QBittorrent\QbitHealthCheck;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(QbitHealthCheck $healthCheck): Response
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
            ],
            'latestReleases' => Release::with('show')->latest('published_at')->take(30)->get()
                ->map(fn (Release $release) => (new ReleaseResource($release))->resolve())
                ->all(),
        ]);
    }
}
