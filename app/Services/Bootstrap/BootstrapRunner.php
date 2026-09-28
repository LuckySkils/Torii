<?php

declare(strict_types=1);

namespace App\Services\Bootstrap;

use App\Jobs\CompleteBootstrapTask;
use App\Models\BootstrapTask;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use InvalidArgumentException;
use Throwable;

/**
 * Runs the one-time data tasks (§12). Each task is dispatched as a queue chain,
 * its jobs followed by CompleteBootstrapTask, so it counts as done only when the
 * work succeeded. Nothing here waits for a job: container start never blocks.
 */
final class BootstrapRunner
{
    public function __construct(private readonly BootstrapTasks $tasks) {}

    /**
     * On container start: queue every task that hasn't completed and whose
     * dependencies have. The rest wait for their dependency's completion.
     *
     * @return array<string, 'done'|'dispatched'|'waiting'|'failed'>
     */
    public function run(): array
    {
        $report = [];
        $runStartedAt = now()->startOfSecond(); // started_at is stored to the second

        foreach ($this->tasks->all() as $task) {
            // Fresh each time: a queue that runs jobs inline may have completed an
            // earlier task, and queued this one, since the loop started.
            $rows = $this->rows();
            $row = $rows[$task->key];

            if ($row->completed_at !== null) {
                $report[$task->key] = 'done';
            } elseif ($row->state() === 'running' && $row->started_at->greaterThanOrEqualTo($runStartedAt)) {
                // Queued moments ago by an earlier task's completion.
                $report[$task->key] = 'dispatched';
            } elseif (! $this->dependenciesDone($task, $rows)) {
                $row->forceFill(['started_at' => null, 'error' => null])->save();
                $report[$task->key] = 'waiting';
            } else {
                $report[$task->key] = $this->dispatch($task, $row) ? 'dispatched' : 'failed';
            }
        }

        return $report;
    }

    /**
     * Queues waiting tasks whose dependencies have now all completed. Called
     * after each completion, so dependents follow without a restart.
     *
     * @return array<int, string> the keys dispatched
     */
    public function dispatchReady(): array
    {
        $dispatched = [];

        foreach ($this->tasks->all() as $task) {
            // Fresh each time, as in run(): a dispatch here may complete tasks inline.
            $rows = $this->rows();
            $row = $rows[$task->key];

            if ($row->state() === 'pending' && $this->dependenciesDone($task, $rows) && $this->dispatch($task, $row)) {
                $dispatched[] = $task->key;
            }
        }

        return $dispatched;
    }

    /** Runs one task again, completed or not, regardless of its dependencies. */
    public function force(string $key): void
    {
        $task = collect($this->tasks->all())->firstWhere('key', $key)
            ?? throw new InvalidArgumentException("Unknown bootstrap task [{$key}].");

        $row = $this->rows()[$key];
        $row->forceFill(['completed_at' => null])->save();

        $this->dispatch($task, $row);
    }

    public function complete(string $key): void
    {
        BootstrapTask::where('key', $key)->update(['completed_at' => now(), 'error' => null]);
    }

    public function fail(string $key, string $error): void
    {
        BootstrapTask::where('key', $key)->update(['error' => $error]);

        logger()->warning('Bootstrap task failed; it will be retried on the next start', ['task' => $key, 'error' => $error]);
    }

    /**
     * For the dashboard: null once every task has completed.
     *
     * @return array{total: int, completed: int, tasks: array<int, array{key: string, label: string, state: string, error: string|null}>}|null
     */
    public function progress(): ?array
    {
        $rows = BootstrapTask::query()->get()->keyBy('key');
        $tasks = [];

        foreach ($this->tasks->all() as $task) {
            $row = $rows->get($task->key);
            $tasks[] = [
                'key' => $task->key,
                'label' => $task->label,
                'state' => $row?->state() ?? 'pending',
                'error' => $row?->error,
            ];
        }

        $completed = count(array_filter($tasks, fn (array $task) => $task['state'] === 'done'));

        return $completed === count($tasks) ? null : ['total' => count($tasks), 'completed' => $completed, 'tasks' => $tasks];
    }

    /**
     * @return array<int, array{task: BootstrapTaskDefinition, row: BootstrapTask|null}>
     */
    public function list(): array
    {
        $rows = BootstrapTask::query()->get()->keyBy('key');

        return array_map(fn (BootstrapTaskDefinition $task) => ['task' => $task, 'row' => $rows->get($task->key)], $this->tasks->all());
    }

    /**
     * A dispatch that throws (the queue unreachable, or a sync queue running a
     * failing job inline) is recorded and never stops the other tasks.
     */
    private function dispatch(BootstrapTaskDefinition $task, BootstrapTask $row): bool
    {
        $row->forceFill(['started_at' => now(), 'error' => null])->save();
        $key = $task->key;

        try {
            Bus::chain([...($task->jobs)(), new CompleteBootstrapTask($key)])
                ->catch(function (Throwable $e) use ($key) {
                    app(BootstrapRunner::class)->fail($key, $e->getMessage());
                })
                ->dispatch();
        } catch (Throwable $e) {
            $this->fail($key, $e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * @param  Collection<string, BootstrapTask>  $rows
     */
    private function dependenciesDone(BootstrapTaskDefinition $task, Collection $rows): bool
    {
        foreach ($task->dependsOn as $dependency) {
            if ($rows->get($dependency)?->completed_at === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every task's row, created on first sight (a task added in a later release).
     *
     * @return Collection<string, BootstrapTask>
     */
    private function rows(): Collection
    {
        return collect($this->tasks->all())
            ->mapWithKeys(fn (BootstrapTaskDefinition $task) => [$task->key => BootstrapTask::firstOrCreate(['key' => $task->key])]);
    }
}
