<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * Runs an existing artisan command on the queue, so a bootstrap task can reuse
 * it without blocking container start. Unlike Artisan::queue(), a non-zero exit
 * code fails the job, which is what a bootstrap task's success depends on.
 */
final class RunArtisanCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public readonly string $command,
        public readonly array $parameters = [],
    ) {}

    public function handle(): void
    {
        $exitCode = Artisan::call($this->command, $this->parameters);

        if ($exitCode !== 0) {
            throw new RuntimeException("`{$this->command}` exited with {$exitCode}: ".trim(Artisan::output()));
        }
    }
}
