<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use App\Services\Reconciler\Listener\ConnectionSupervisor;
use App\Services\Reconciler\Listener\JellyfinSession;
use App\Services\Reconciler\Listener\ReconcilerSocket;
use App\Services\Reconciler\Listener\ShokoSession;
use App\Services\Reconciler\Listener\SocketConnector;
use App\Services\Reconciler\ListenerHeartbeat;
use Illuminate\Console\Command;
use Revolt\EventLoop;
use Throwable;

use function Amp\async;
use function Amp\delay;
use function Amp\Future\await;

/**
 * The reconciler's listener (§17), run in its own container from the Torii image.
 * It only listens: both connections in one event loop, each kept up with
 * backoff, every relevant event written to `reconciler_events` and handed to the
 * main worker. Exits cleanly when the reconciler is off, at the end of its
 * lifetime (Docker restarts it) and on SIGTERM.
 */
class ToriiListen extends Command
{
    protected $signature = 'torii:listen';

    protected $description = 'Delivery reconciler: hold the Shoko and Jellyfin connections and record their events';

    private bool $stopping = false;

    /** @var array<string, ReconcilerSocket|null> */
    private array $sockets = [];

    private ?DeferredCancellation $sleeping = null;

    public function handle(SocketConnector $connector, ShokoSession $shoko, JellyfinSession $jellyfin, ListenerHeartbeat $heartbeat): int
    {
        if (! config('subtracker.reconciler.enabled')) {
            $this->info('The reconciler is disabled (RECONCILER_ENABLED=false); nothing to listen to.');

            return self::SUCCESS;
        }

        $missing = array_keys(array_filter([
            'SHOKO_URL' => config('subtracker.reconciler.shoko_url'),
            'SHOKO_API_KEY' => config('subtracker.reconciler.shoko_api_key'),
            'JELLYFIN_URL' => config('subtracker.reconciler.jellyfin_url'),
            'JELLYFIN_API_KEY' => config('subtracker.reconciler.jellyfin_api_key'),
            'JELLYFIN_ANIME_LIBRARY_ID' => config('subtracker.reconciler.jellyfin_anime_library_id'),
        ], fn ($value) => (string) $value === ''));

        if ($missing !== []) {
            $this->error('The reconciler is enabled but not configured: set '.implode(', ', $missing).'.');

            return self::FAILURE;
        }

        $this->waitForSchema();

        $this->info('Reconciler listener starting'.(config('subtracker.reconciler.dry_run') ? ' (dry run: no fixes will be sent)' : '').'.');

        $watchers = [EventLoop::unreference(EventLoop::delay((float) config('subtracker.reconciler.listener_lifetime'), function (): void {
            $this->info('Reconciler listener lifetime reached; exiting for a fresh start.');
            $this->stop();
        }))];

        if (extension_loaded('pcntl')) {
            foreach ([SIGTERM, SIGINT] as $signal) {
                $watchers[] = EventLoop::unreference(EventLoop::onSignal($signal, fn () => $this->stop()));
            }
        }

        $supervisor = new ConnectionSupervisor($connector, $heartbeat, function (float $seconds): void {
            $this->sleeping = new DeferredCancellation;

            try {
                delay($seconds, cancellation: $this->sleeping->getCancellation());
            } catch (CancelledException) {
                // Stopping.
            }
        });

        try {
            await([
                async(fn () => $supervisor->run('shoko', ShokoSession::url(), [], $shoko->run(...), fn () => $this->stopping, fn (?ReconcilerSocket $socket) => $this->sockets['shoko'] = $socket)),
                async(fn () => $supervisor->run('jellyfin', JellyfinSession::url(), [], $jellyfin->run(...), fn () => $this->stopping, fn (?ReconcilerSocket $socket) => $this->sockets['jellyfin'] = $socket)),
            ]);
        } finally {
            array_map(EventLoop::cancel(...), $watchers);
        }

        $this->info('Reconciler listener stopped.');

        return self::SUCCESS;
    }

    private function stop(): void
    {
        $this->stopping = true;
        $this->sleeping?->cancel();

        foreach ($this->sockets as $socket) {
            $socket?->close();
        }
    }

    /**
     * The main container runs the migrations; this one waits until none is pending.
     */
    private function waitForSchema(): void
    {
        $announced = false;

        while (true) {
            try {
                $migrator = app('migrator');
                $pending = $migrator->repositoryExists()
                    ? array_diff(array_keys($migrator->getMigrationFiles([database_path('migrations')])), $migrator->getRepository()->getRan())
                    : ['(no migrations table yet)'];
            } catch (Throwable $e) {
                $pending = ['(database not reachable: '.$e->getMessage().')'];
            }

            if ($pending === []) {
                return;
            }

            if (! $announced) {
                $this->info('Waiting for the database schema to be current ('.count($pending).' pending)…');
                $announced = true;
            }

            sleep(5);
        }
    }
}
