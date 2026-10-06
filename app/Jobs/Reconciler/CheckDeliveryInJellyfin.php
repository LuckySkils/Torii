<?php

declare(strict_types=1);

namespace App\Jobs\Reconciler;

use App\Services\Reconciler\DeliveryReconciler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Check A, one attempt of the backoff (§17): is the episode in Jellyfin? */
final class CheckDeliveryInJellyfin implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $deliveryId,
        public readonly int $run,
        public readonly string $phase,
        public readonly int $attempt,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->deliveryId}:{$this->run}:{$this->phase}:{$this->attempt}";
    }

    public function handle(DeliveryReconciler $reconciler): void
    {
        $reconciler->checkA($this->deliveryId, $this->run, $this->phase, $this->attempt);
    }
}
