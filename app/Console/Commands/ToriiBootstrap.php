<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Bootstrap\BootstrapRunner;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ToriiBootstrap extends Command
{
    protected $signature = 'torii:bootstrap
        {--list : Show every task and its state, without running anything}
        {--force= : Run this task again (by key), even if it already completed}';

    protected $description = 'Queue the one-time data tasks that have not completed yet (runs on container start)';

    public function handle(BootstrapRunner $runner): int
    {
        if ($this->option('list')) {
            $this->table(
                ['Task', 'Description', 'State', 'After', 'Started', 'Completed', 'Error'],
                array_map(fn (array $entry) => [
                    $entry['task']->key,
                    $entry['task']->label,
                    $entry['row']?->state() ?? 'pending',
                    implode(', ', $entry['task']->dependsOn),
                    $entry['row']?->started_at?->toDateTimeString() ?? '',
                    $entry['row']?->completed_at?->toDateTimeString() ?? '',
                    (string) $entry['row']?->error,
                ], $runner->list()),
            );

            return self::SUCCESS;
        }

        if ($this->option('force') !== null) {
            try {
                $runner->force((string) $this->option('force'));
            } catch (InvalidArgumentException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $this->info("Queued bootstrap task [{$this->option('force')}] again.");

            return self::SUCCESS;
        }

        $report = $runner->run();

        foreach ($report as $key => $state) {
            $this->line(str_pad($key, 24).$state);
        }

        // Re-read: a queue that runs jobs inline may already have finished what was dispatched.
        $pending = count(array_filter($runner->list(), fn (array $entry) => $entry['row']?->state() !== 'done'));
        $this->info($pending === 0 ? 'Bootstrap: every task has completed.' : "Bootstrap: {$pending} task(s) queued or waiting.");

        return self::SUCCESS;
    }
}
