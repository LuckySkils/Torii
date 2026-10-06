<?php

declare(strict_types=1);

namespace App\Jobs\Reconciler;

use App\Models\Delivery;
use App\Services\Reconciler\DeliveryReconciler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A new delivery catches up on the events Shoko sent before Torii noticed the
 * download (§17). Idempotent: events already applied to it are skipped.
 */
final class ReplayDeliveryEvents implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $deliveryId) {}

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    public function handle(DeliveryReconciler $reconciler): void
    {
        $delivery = Delivery::find($this->deliveryId);

        if ($delivery !== null) {
            $reconciler->replayStoredEvents($delivery);
        }
    }
}
