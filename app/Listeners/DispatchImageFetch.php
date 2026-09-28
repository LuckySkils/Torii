<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ShowDiscovered;
use App\Jobs\FetchShowImage;
use App\Services\SubsPlease\PosterFetchSpacing;

final class DispatchImageFetch
{
    public function __construct(private readonly PosterFetchSpacing $spacing) {}

    /**
     * A lone new show is fetched right away; the shows of a big first poll are
     * spaced PosterFetchSpacing::SECONDS apart.
     */
    public function handle(ShowDiscovered $event): void
    {
        $delay = $this->spacing->nextDelay();

        FetchShowImage::dispatch($event->show->id)->delay($delay > 0 ? now()->addSeconds($delay) : null);
    }
}
