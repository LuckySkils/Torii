<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ReleaseDownloaded;
use App\Models\Delivery;

/**
 * The reconciler's entry point (§17): every single-episode release Torii marks
 * downloaded gets a delivery, keyed to the file name Shoko will report. Batches
 * hold many files and aren't tracked. Nothing happens with the reconciler off.
 */
final class CreateDelivery
{
    public function handle(ReleaseDownloaded $event): void
    {
        if (! config('subtracker.reconciler.enabled') || $event->release->is_batch) {
            return;
        }

        Delivery::firstOrCreate(
            ['release_id' => $event->release->id],
            ['filename' => basename(str_replace('\\', '/', $event->release->title))],
        );
    }
}
