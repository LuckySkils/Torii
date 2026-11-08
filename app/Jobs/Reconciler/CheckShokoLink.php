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

/** Before fix A, one attempt of the link schedule (§17): has Shoko linked the file to its series? */
final class CheckShokoLink implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 7200;

    public function __construct(
        public readonly int $deliveryId,
        public readonly int $run,
        public readonly int $attempt,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->deliveryId}:{$this->run}:{$this->attempt}";
    }

    public function handle(DeliveryReconciler $reconciler): void
    {
        $reconciler->checkShokoLink($this->deliveryId, $this->run, $this->attempt);
    }
}
