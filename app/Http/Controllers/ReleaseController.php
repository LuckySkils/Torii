<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DispatchStatus;
use App\Jobs\QueueReleases;
use App\Models\Release;
use Illuminate\Http\RedirectResponse;

class ReleaseController extends Controller
{
    public function download(Release $release): RedirectResponse
    {
        if (in_array($release->dispatch_status, [DispatchStatus::Sent, DispatchStatus::Exists], true)) {
            return back()->with('success', 'Nothing to queue.');
        }

        QueueReleases::dispatch([$release->id]);

        return back()->with('success', 'Queued 1 release.');
    }
}
