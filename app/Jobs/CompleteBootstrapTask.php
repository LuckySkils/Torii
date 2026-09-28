<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Bootstrap\BootstrapRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The last link of a bootstrap task's chain: reached only when every job before
 * it succeeded, so it's what marks the task completed. Then queues the tasks
 * that were waiting on this one.
 */
final class CompleteBootstrapTask implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $key) {}

    public function handle(BootstrapRunner $runner): void
    {
        $runner->complete($this->key);
        $runner->dispatchReady();
    }
}
