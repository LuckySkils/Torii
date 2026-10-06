<?php

declare(strict_types=1);

namespace App\Jobs\Reconciler;

use App\Enums\ReconcilerEventType;
use App\Models\ReconcilerEvent;
use App\Services\Reconciler\DeliveryReconciler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** One recorded listener event, handed to the reconciler's decisions (§17). */
final class ProcessReconcilerEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $eventId) {}

    public function handle(DeliveryReconciler $reconciler): void
    {
        $event = ReconcilerEvent::find($this->eventId);

        if ($event === null || $event->processed_at !== null) {
            return;
        }

        match ($event->type) {
            ReconcilerEventType::FileMatched => $reconciler->fileMatched($event->payload, $event->id),
            ReconcilerEventType::SeriesAdded => $reconciler->seriesAdded($event->payload, $event->id),
            ReconcilerEventType::LibraryChanged => $reconciler->libraryChanged(),
        };

        $event->update(['processed_at' => now()]);
    }
}
