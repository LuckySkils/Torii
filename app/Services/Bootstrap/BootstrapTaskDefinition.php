<?php

declare(strict_types=1);

namespace App\Services\Bootstrap;

use Closure;

/**
 * One one-time data task. `key` is stable forever (it's the marker in
 * bootstrap_tasks); `jobs` builds the queued jobs when the task is dispatched.
 */
final readonly class BootstrapTaskDefinition
{
    /**
     * @param  array<int, string>  $dependsOn  keys that must have completed first (ordering only)
     * @param  Closure(): array<int, object>  $jobs
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $dependsOn,
        public Closure $jobs,
    ) {}
}
