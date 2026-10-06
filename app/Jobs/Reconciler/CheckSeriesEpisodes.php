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

/** Check B, one attempt of the backoff (§17): does the episode's series list episodes? */
final class CheckSeriesEpisodes implements ShouldBeUnique, ShouldQueue
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
        $reconciler->checkB($this->deliveryId, $this->run, $this->phase, $this->attempt);
    }
}
