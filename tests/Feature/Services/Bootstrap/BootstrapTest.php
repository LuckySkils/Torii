<?php

declare(strict_types=1);

use App\Jobs\CompleteBootstrapTask;
use App\Jobs\RunArtisanCommand;
use App\Jobs\SyncAnimeAirings;
use App\Jobs\SyncAnimeSeasons;
use App\Models\BootstrapTask;
use App\Services\Bootstrap\BootstrapRunner;
use App\Services\Bootstrap\BootstrapTaskDefinition;
use App\Services\Bootstrap\BootstrapTasks;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Stands in for a real task's work: counts its runs, and fails while
 * `bootstrap-probe:fail:{name}` is set. The phpunit queue is `sync`, so a whole
 * chain runs inline during the dispatch.
 */
final class BootstrapProbeJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $name) {}

    public function handle(): void
    {
        if (Cache::get("bootstrap-probe:fail:{$this->name}")) {
            throw new RuntimeException("{$this->name} broke");
        }

        Cache::increment("bootstrap-probe:runs:{$this->name}");
    }
}

function probeRuns(string $name): int
{
    return (int) Cache::get("bootstrap-probe:runs:{$name}", 0);
}

/** A task list shaped like the real one: two independent roots, and dependents. */
function useProbeTasks(): void
{
    app()->instance(BootstrapTasks::class, new class extends BootstrapTasks
    {
        public function all(): array
        {
            $task = fn (string $key, array $after = []) => new BootstrapTaskDefinition($key, "Task {$key}", $after, fn () => [new BootstrapProbeJob($key)]);

            return [
                $task('feed'),
                $task('posters', ['feed']),
                $task('anime'),
                $task('airings', ['anime']),
                $task('covers', ['anime']),
            ];
        }
    });
}

function taskState(string $key): string
{
    return BootstrapTask::where('key', $key)->sole()->state();
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-29 08:00:00');
});

test('a fresh database runs every task, dependents after their dependency', function () {
    useProbeTasks();

    $this->artisan('torii:bootstrap')->expectsOutputToContain('every task has completed')->assertSuccessful();

    foreach (['feed', 'posters', 'anime', 'airings', 'covers'] as $key) {
        expect(probeRuns($key))->toBe(1, "{$key} ran once")
            ->and(taskState($key))->toBe('done');
    }
});

test('a completed task runs once and never again', function () {
    useProbeTasks();

    $this->artisan('torii:bootstrap');
    $this->artisan('torii:bootstrap');
    $this->artisan('torii:bootstrap');

    expect(probeRuns('feed'))->toBe(1)->and(probeRuns('covers'))->toBe(1);
});

test('a failing task records its error, blocks only its dependents, and retries on the next start', function () {
    useProbeTasks();
    Cache::put('bootstrap-probe:fail:feed', true);

    $this->artisan('torii:bootstrap')->assertSuccessful();

    $feed = BootstrapTask::where('key', 'feed')->sole();

    expect($feed->state())->toBe('failed')
        ->and($feed->error)->toContain('feed broke')
        ->and($feed->completed_at)->toBeNull()
        // Its dependent waits; the independent tasks are unaffected.
        ->and(taskState('posters'))->toBe('pending')
        ->and(probeRuns('posters'))->toBe(0)
        ->and(taskState('anime'))->toBe('done')
        ->and(taskState('airings'))->toBe('done')
        ->and(taskState('covers'))->toBe('done');

    // The next container start: the outage is over.
    Cache::forget('bootstrap-probe:fail:feed');
    $this->artisan('torii:bootstrap')->assertSuccessful();

    expect(taskState('feed'))->toBe('done')
        ->and(BootstrapTask::where('key', 'feed')->value('error'))->toBeNull()
        ->and(taskState('posters'))->toBe('done')
        ->and(probeRuns('feed'))->toBe(1)
        ->and(probeRuns('posters'))->toBe(1)
        // Nothing that had completed ran again.
        ->and(probeRuns('anime'))->toBe(1);
});

test('--force reruns one completed task and nothing else', function () {
    useProbeTasks();
    $this->artisan('torii:bootstrap');

    $this->artisan('torii:bootstrap --force=airings')->expectsOutput('Queued bootstrap task [airings] again.')->assertSuccessful();

    expect(probeRuns('airings'))->toBe(2)
        ->and(probeRuns('anime'))->toBe(1)
        ->and(probeRuns('covers'))->toBe(1)
        ->and(taskState('airings'))->toBe('done');
});

test('--force with an unknown key fails', function () {
    useProbeTasks();

    $this->artisan('torii:bootstrap --force=nope')->expectsOutputToContain('Unknown bootstrap task [nope]')->assertFailed();
});

test('a task added in a later release runs on the next start of an established install', function () {
    useProbeTasks();
    $this->artisan('torii:bootstrap');

    app()->instance(BootstrapTasks::class, new class extends BootstrapTasks
    {
        public function all(): array
        {
            return [
                new BootstrapTaskDefinition('feed', 'Task feed', [], fn () => [new BootstrapProbeJob('feed')]),
                new BootstrapTaskDefinition('shiny-new', 'A new feature', [], fn () => [new BootstrapProbeJob('shiny-new')]),
            ];
        }
    });

    $this->artisan('torii:bootstrap');

    expect(probeRuns('shiny-new'))->toBe(1)->and(probeRuns('feed'))->toBe(1);
});

test('--list shows every task and its state without running anything', function () {
    useProbeTasks();
    Cache::put('bootstrap-probe:fail:anime', true);
    $this->artisan('torii:bootstrap');
    $runs = probeRuns('feed');

    Artisan::call('torii:bootstrap', ['--list' => true]);
    $output = Artisan::output();

    expect($output)->toMatch('/\| anime\s+\| Task anime\s+\| failed\s+\|.*\| anime broke/')
        ->and($output)->toMatch('/\| posters\s+\| Task posters\s+\| done\s+\| feed\s+\|/');

    expect(probeRuns('feed'))->toBe($runs);
});

// ── The real task list ───────────────────────────────────────────────────────

test('the real tasks, in order, with their dependencies', function () {
    expect(array_map(fn (BootstrapTaskDefinition $t) => [$t->key, $t->dependsOn], app(BootstrapTasks::class)->all()))->toBe([
        ['feed.initial-poll', []],
        ['images.initial-fetch', ['feed.initial-poll']],
        ['anime.initial-sync', []],
        ['anime.initial-airings', ['anime.initial-sync']],
        ['anime.initial-covers', ['anime.initial-sync']],
        ['anime.full-covers', ['anime.initial-covers']],
    ]);
});

test('on a fresh database the real bootstrap only queues jobs: the roots now, dependents later', function () {
    Queue::fake();

    $this->artisan('torii:bootstrap')->assertSuccessful();

    Queue::assertPushedWithChain(RunArtisanCommand::class, [CompleteBootstrapTask::class], fn (RunArtisanCommand $job) => $job->command === 'feed:poll' && $job->parameters === ['--force' => true]);
    Queue::assertPushedWithChain(SyncAnimeSeasons::class, [CompleteBootstrapTask::class], fn (SyncAnimeSeasons $job) => $job->seasons === [
        ['season' => 'SPRING', 'year' => 2026], ['season' => 'SUMMER', 'year' => 2026], ['season' => 'FALL', 'year' => 2026],
    ] && $job->allLinked);
    Queue::assertNotPushed(SyncAnimeAirings::class);
    Queue::assertNotPushed(RunArtisanCommand::class, fn (RunArtisanCommand $job) => $job->command !== 'feed:poll');

    expect(taskState('feed.initial-poll'))->toBe('running')
        ->and(taskState('anime.initial-sync'))->toBe('running')
        ->and(taskState('anime.initial-airings'))->toBe('pending');
});

test('completing a real task queues its dependents with their own chains', function () {
    Queue::fake();
    $this->artisan('torii:bootstrap');

    (new CompleteBootstrapTask('anime.initial-sync'))->handle(app(BootstrapRunner::class));

    Queue::assertPushedWithChain(SyncAnimeAirings::class, [CompleteBootstrapTask::class]);
    Queue::assertPushedWithChain(RunArtisanCommand::class, [CompleteBootstrapTask::class], fn (RunArtisanCommand $job) => $job->command === 'anime:fetch-images' && $job->parameters === ['--missing' => true]);
    Queue::assertNotPushed(RunArtisanCommand::class, fn (RunArtisanCommand $job) => $job->command === 'images:fetch');
});

test('with every task done, a start queues nothing and changes nothing', function () {
    Queue::fake();
    foreach (app(BootstrapTasks::class)->all() as $task) {
        BootstrapTask::create(['key' => $task->key, 'started_at' => now()->subMonth(), 'completed_at' => now()->subMonth()]);
    }
    $before = BootstrapTask::orderBy('id')->get()->toArray();

    $this->travel(1)->days();
    $this->artisan('torii:bootstrap')->expectsOutputToContain('every task has completed')->assertSuccessful();

    Queue::assertNothingPushed();
    expect(BootstrapTask::orderBy('id')->get()->toArray())->toBe($before);
});

test('a job failing on the queue marks its task failed via the chain', function () {
    Queue::fake();
    $this->artisan('torii:bootstrap');

    // What the worker does when a chained job exhausts its tries.
    app(BootstrapRunner::class)->fail('anime.initial-sync', 'AniList returned errors: boom');

    expect(taskState('anime.initial-sync'))->toBe('failed');
});

test('RunArtisanCommand fails the job on a non-zero exit, so a failed poll is a failed task', function () {
    Artisan::command('bootstrap-test:fails', fn () => 1);
    Artisan::command('bootstrap-test:works', fn () => 0);

    (new RunArtisanCommand('bootstrap-test:works'))->handle();

    expect(fn () => (new RunArtisanCommand('bootstrap-test:fails'))->handle())->toThrow(RuntimeException::class, 'exited with 1');
});

// ── Dashboard and schedule ───────────────────────────────────────────────────

test('the dashboard health shows bootstrap progress while tasks are pending, and null when done', function () {
    useProbeTasks();
    Cache::put('bootstrap-probe:fail:anime', true);
    Http::fake(fn () => throw new ConnectionException('offline'));
    $this->artisan('torii:bootstrap');

    $this->get('/')->assertInertia(fn ($page) => $page
        ->where('health.bootstrap.total', 5)
        ->where('health.bootstrap.completed', 2)
        ->where('health.bootstrap.tasks.2.key', 'anime')
        ->where('health.bootstrap.tasks.2.state', 'failed')
        ->where('health.bootstrap.tasks.2.error', 'anime broke')
        ->where('health.bootstrap.tasks.3.state', 'pending'));

    Cache::forget('bootstrap-probe:fail:anime');
    $this->artisan('torii:bootstrap');

    $this->get('/')->assertInertia(fn ($page) => $page->where('health.bootstrap', null));
});

test('the schedule runs the weekly season sync and the daily airing sync as commands', function () {
    Artisan::call('schedule:list');
    $output = Artisan::output();

    expect($output)->toContain('anime:sync-season --weekly')
        ->and($output)->toContain('anime:sync-airings');
});

test('anime:sync-season --weekly queues the previous, current and next season plus linked anime', function () {
    Queue::fake();

    $this->artisan('anime:sync-season --weekly')->assertSuccessful();

    Queue::assertPushed(SyncAnimeSeasons::class, fn (SyncAnimeSeasons $job) => count($job->seasons) === 3 && $job->allLinked);
});
