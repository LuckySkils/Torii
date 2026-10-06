<?php

declare(strict_types=1);

use App\Enums\ReconcilerEventType;
use App\Jobs\Reconciler\ProcessReconcilerEvent;
use App\Models\ReconcilerEvent;
use App\Services\Reconciler\Listener\ConnectionSupervisor;
use App\Services\Reconciler\Listener\JellyfinSession;
use App\Services\Reconciler\Listener\ReconcilerSocket;
use App\Services\Reconciler\Listener\ShokoSession;
use App\Services\Reconciler\Listener\SocketConnector;
use App\Services\Reconciler\ListenerHeartbeat;
use App\Services\Reconciler\SignalRReader;
use Illuminate\Support\Facades\Queue;

/** A socket that hands out scripted frames, then reports itself closed. */
final class ScriptedSocket implements ReconcilerSocket
{
    /** @var array<int, string> */
    public array $sent = [];

    public bool $closed = false;

    /**
     * @param  array<int, string>  $frames
     */
    public function __construct(private array $frames) {}

    public function send(string $text): void
    {
        $this->sent[] = $text;
    }

    public function receive(): ?string
    {
        return $this->closed ? null : array_shift($this->frames);
    }

    public function close(): void
    {
        $this->closed = true;
    }
}

beforeEach(function () {
    Queue::fake();
    config([
        'subtracker.reconciler.enabled' => true,
        'subtracker.reconciler.shoko_url' => 'http://192.168.1.95:8111',
        'subtracker.reconciler.shoko_api_key' => 'shoko-key',
        'subtracker.reconciler.jellyfin_url' => 'https://jellyfin.example',
        'subtracker.reconciler.jellyfin_api_key' => 'jf-key',
        'subtracker.reconciler.jellyfin_anime_library_id' => ANIME_LIBRARY,
    ]);
});

function shokoFrame(array $message): string
{
    return json_encode(['type' => 1, 'target' => $message['target'], 'arguments' => $message['arguments']]).SignalRReader::SEPARATOR;
}

// ── Shoko ────────────────────────────────────────────────────────────────────

test('the Shoko URL skips negotiate: aggregate feeds, the key as access_token, ws for http', function () {
    expect(ShokoSession::url())->toBe('ws://192.168.1.95:8111/signalr/aggregate?feeds=shoko,metadata,file,release&access_token=shoko-key');

    config(['subtracker.reconciler.shoko_url' => 'https://shoko.example']);
    expect(ShokoSession::url())->toStartWith('wss://shoko.example/signalr/aggregate?');
});

test('the Shoko session handshakes, answers pings and records only the kept events', function () {
    $capture = shokoCapture();
    $allInOneFrame = implode('', array_map(shokoFrame(...), array_slice($capture, 0, 5)));
    $split = shokoFrame($capture[6]);
    $socket = new ScriptedSocket([
        '{}'.SignalRReader::SEPARATOR, // handshake reply
        '{"type":6}'.SignalRReader::SEPARATOR, // server ping
        $allInOneFrame,
        substr($split, 0, 40), // one record across two frames
        substr($split, 40),
        shokoFrame($capture[7]),
        '{"type":7}'.SignalRReader::SEPARATOR, // close
        shokoFrame($capture[2]), // never read: closed
    ]);

    app(ShokoSession::class)->run($socket);

    expect($socket->sent)->toBe([SignalRReader::handshake(), SignalRReader::ping()])
        ->and($socket->closed)->toBeTrue()
        ->and(ReconcilerEvent::orderBy('id')->get()->map(fn ($event) => [$event->source, $event->type, $event->raw_target])->all())->toBe([
            ['shoko', ReconcilerEventType::FileMatched, 'ShokoEvent:FileMatched'],
            ['shoko', ReconcilerEventType::SeriesAdded, 'ShokoEvent:SeriesUpdated'],
            ['shoko', ReconcilerEventType::SeriesAdded, 'ShokoEvent:SeriesUpdated'],
        ])
        ->and(ReconcilerEvent::first()->payload['FileID'])->toBe(2553)
        ->and(app(ListenerHeartbeat::class)->get('shoko'))->toMatchArray(['connected' => true]);
    Queue::assertPushed(ProcessReconcilerEvent::class, 3);
});

test('a refused Shoko handshake is an error', function () {
    $socket = new ScriptedSocket(['{"error":"Unauthorized"}'.SignalRReader::SEPARATOR]);

    expect(fn () => app(ShokoSession::class)->run($socket))->toThrow(RuntimeException::class, 'Shoko refused the SignalR handshake: Unauthorized');
});

// ── Jellyfin ─────────────────────────────────────────────────────────────────

test('the Jellyfin URL carries the API key and a stable device id', function () {
    expect(JellyfinSession::url())->toBe('wss://jellyfin.example/socket?api_key=jf-key&deviceId=torii-reconciler');
});

test('the Jellyfin session records LibraryChanged for the Anime library only', function () {
    [$forceKeepAlive, $keepAlive, $libraryChanged] = jellyfinCapture();
    $otherLibrary = $libraryChanged;
    $otherLibrary['Data']['CollectionFolders'] = ['abebc196cc1b8bbf6f8bb5ca7b5ad6f1'];
    $socket = new ScriptedSocket(array_map(json_encode(...), [$forceKeepAlive, $keepAlive, $libraryChanged, $otherLibrary]));

    app(JellyfinSession::class)->run($socket);

    expect(ReconcilerEvent::pluck('type')->all())->toBe([ReconcilerEventType::LibraryChanged])
        ->and(ReconcilerEvent::first()->payload['CollectionFolders'])->toContain('0c41907140d802bb58430fed7e2cd79e')
        ->and(app(ListenerHeartbeat::class)->get('jellyfin')['lastMessageAt'])->not->toBeNull();
});

// ── Reconnecting ─────────────────────────────────────────────────────────────

test('a dropped connection is reconnected with a doubling delay, reset after a successful connect', function () {
    $sleeps = [];
    $connector = new class implements SocketConnector
    {
        public int $attempts = 0;

        public function connect(string $url, array $headers = []): ReconcilerSocket
        {
            $this->attempts++;

            // Fails twice, connects (and drops at once), fails again, then connects for good.
            return match ($this->attempts) {
                1, 2, 4 => throw new RuntimeException('connection refused'),
                default => new ScriptedSocket([]),
            };
        }
    };
    $sessions = 0;
    $supervisor = new ConnectionSupervisor($connector, app(ListenerHeartbeat::class), function (float $seconds) use (&$sleeps): void {
        $sleeps[] = $seconds;
    });

    $supervisor->run('shoko', 'ws://x', [], function () use (&$sessions): void {
        $sessions++;
    }, fn () => $connector->attempts >= 5, fn () => null);

    // Fail, fail (1, 2), connect then drop (delay reset: 1), fail (2), connect.
    expect($sleeps)->toBe([1.0, 2.0, 1.0, 2.0])
        ->and($sessions)->toBe(2)
        ->and(app(ListenerHeartbeat::class)->get('shoko')['connected'])->toBeFalse();
});

test('the delay stops doubling at 60 s', function () {
    $sleeps = new ArrayObject;
    $connector = new class implements SocketConnector
    {
        public function connect(string $url, array $headers = []): ReconcilerSocket
        {
            throw new RuntimeException('down');
        }
    };
    $supervisor = new ConnectionSupervisor($connector, app(ListenerHeartbeat::class), function (float $seconds) use ($sleeps): void {
        $sleeps[] = $seconds;
    });

    $supervisor->run('jellyfin', 'ws://x', [], fn () => null, fn () => count($sleeps) >= 8, fn () => null);
    $sleeps = $sleeps->getArrayCopy();

    expect($sleeps)->toBe([1.0, 2.0, 4.0, 8.0, 16.0, 32.0, 60.0, 60.0]);
});

// ── The command and its health ───────────────────────────────────────────────

test('torii:listen exits cleanly when the reconciler is off, and with an error when it is on but unconfigured', function () {
    config(['subtracker.reconciler.enabled' => false]);
    $this->artisan('torii:listen')->expectsOutputToContain('The reconciler is disabled')->assertExitCode(0);

    config(['subtracker.reconciler.enabled' => true, 'subtracker.reconciler.shoko_api_key' => '', 'subtracker.reconciler.jellyfin_anime_library_id' => '']);
    $this->artisan('torii:listen')->expectsOutputToContain('set SHOKO_API_KEY, JELLYFIN_ANIME_LIBRARY_ID')->assertExitCode(1);
});
