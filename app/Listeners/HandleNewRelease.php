<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Contracts\DownloadDriver;
use App\Events\NewReleaseDetected;

final class HandleNewRelease
{
    public function __construct(
        private readonly DownloadDriver $driver,
    ) {}

    public function handle(NewReleaseDetected $event): void
    {
        $this->driver->onNewRelease($event->release);
    }
}
