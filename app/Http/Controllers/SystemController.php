<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\DownloadDriver;
use App\Models\FeedPoll;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class SystemController extends Controller
{
    public function pollFeed(): RedirectResponse
    {
        Artisan::call('feed:poll', ['--force' => true]);

        $poll = FeedPoll::query()->latest('id')->first();

        if ($poll?->error !== null) {
            return back()->with('error', "Feed poll failed: {$poll->error}");
        }

        if ($poll?->not_modified) {
            return back()->with('success', 'Feed poll ran: not modified since last poll.');
        }

        return back()->with('success', "Feed poll ran: {$poll?->items_new} new releases.");
    }

    public function reconcile(DownloadDriver $driver): RedirectResponse
    {
        try {
            $report = $driver->reconcile();
        } catch (Throwable $e) {
            return back()->with('error', "Reconcile failed: {$e->getMessage()}");
        }

        $created = count($report->created);
        $updated = count($report->updated);
        $disabled = count($report->disabled);
        $orphaned = count($report->orphaned);

        return back()->with(
            'success',
            "Reconciled: {$created} created, {$updated} updated, {$disabled} disabled, {$orphaned} orphaned rules left alone."
        );
    }
}
