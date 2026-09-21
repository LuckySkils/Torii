<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ShowDiscovered;
use App\Jobs\FetchShowImage;

final class DispatchImageFetch
{
    public function handle(ShowDiscovered $event): void
    {
        FetchShowImage::dispatch($event->show->id);
    }
}
