<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DownloadRelease;
use App\Models\Release;
use Illuminate\Http\RedirectResponse;

class ReleaseController extends Controller
{
    public function download(Release $release, DownloadRelease $download): RedirectResponse
    {
        return $download($release)
            ? back()->with('success', 'Queued 1 release.')
            : back()->with('success', 'Nothing to queue.');
    }
}
