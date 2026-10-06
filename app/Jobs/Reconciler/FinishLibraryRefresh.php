<?php

declare(strict_types=1);

namespace App\Jobs\Reconciler;

use App\Services\Reconciler\DeliveryReconciler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The safety net for fix A (§17): if this refresh is still running when its
 * timeout passes (no library.changed came), treat it as finished.
 */
final class FinishLibraryRefresh implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $token) {}

    public function handle(DeliveryReconciler $reconciler): void
    {
        $reconciler->finishLibraryRefresh($this->token);
    }
}
