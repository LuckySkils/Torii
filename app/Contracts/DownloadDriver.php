<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Release;
use App\Models\Show;
use App\Services\QBittorrent\ReconcileReport;

interface DownloadDriver
{
    public function track(Show $show): void;

    public function untrack(Show $show): void;

    public function onNewRelease(Release $release): void;

    public function reconcile(): ReconcileReport;
}
