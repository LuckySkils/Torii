<?php

declare(strict_types=1);

use App\Enums\ReconcilerEventType;
use App\Services\Reconciler\JellyfinEvents;
use App\Services\Reconciler\ShokoEvents;
use App\Services\Reconciler\SignalRReader;

// ── SignalR framing ──────────────────────────────────────────────────────────

test('records are split on 0x1E, several per frame or one across frames', function () {
    $reader = new SignalRReader;
    $rs = SignalRReader::SEPARATOR;

    expect($reader->feed('{}'.$rs.'{"type":6}'.$rs))->toBe([[], ['type' => 6]])
        ->and($reader->feed('{"type":1,"target":"ShokoEvent:Fi'))->toBe([])
        ->and($reader->feed('leMatched","arguments":[{}]}'.$rs.'{"type":6}'))->toBe([['type' => 1, 'target' => 'ShokoEvent:FileMatched', 'arguments' => [[]]]])
        ->and($reader->feed($rs))->toBe([['type' => 6]])
        ->and($reader->feed($rs.$rs.'not json'.$rs))->toBe([]);
});

test('the handshake and ping are the JSON protocol v1 records', function () {
    expect(SignalRReader::handshake())->toBe('{"protocol":"json","version":1}'."\x1e")
        ->and(SignalRReader::ping())->toBe('{"type":6}'."\x1e");
});

// ── Shoko events ─────────────────────────────────────────────────────────────

test('of the Psyren sequence only the file match and the two series additions are kept', function () {
    $kept = array_values(array_filter(array_map(
        fn (array $message) => ShokoEvents::normalize($message['target'], $message['arguments']),
        shokoCapture(),
    )));

    expect(array_map(fn (array $event) => $event['type'], $kept))->toBe([
        ReconcilerEventType::FileMatched,
        ReconcilerEventType::SeriesAdded,
        ReconcilerEventType::SeriesAdded,
    ])->and(array_column(array_column($kept, 'payload'), 'Source'))->toBe(['AniDB', 'Shoko']);

    // FileDetected, FileHashed, SeriesUpdated(ImageAdded) and EpisodeUpdated are dropped.
    expect(count(shokoCapture()) - count($kept))->toBe(5);
});

test('the file match carries everything correlation needs', function () {
    $matched = ShokoEvents::normalize(...array_values(shokoCapture()[2]))['payload'];

    expect(ShokoEvents::filename($matched))->toBe('[SubsPlease] PSYREN - 01 (1080p) [EB85F891].mkv')
        ->and(ShokoEvents::fileId($matched))->toBe(2553)
        ->and(ShokoEvents::folderId($matched))->toBe(1)
        ->and(ShokoEvents::firstReference($matched, 'AnidbAnimeID'))->toBe(19765)
        ->and(ShokoEvents::firstReference($matched, 'SeriesID'))->toBeNull();
});

test('an unmatched file is dropped', function () {
    $message = shokoCapture('file_not_matched.txt')[0];

    expect($message['target'])->toBe('ShokoEvent:FileNotMatched')
        ->and(ShokoEvents::normalize($message['target'], $message['arguments']))->toBeNull();
});

test('the newer Shoko naming and field names are understood too', function () {
    $file = ['FileID' => 7, 'ManagedFolderID' => 3, 'RelativePath' => 'Show/[SubsPlease] X - 01 (1080p) [AAAAAAAA].mkv', 'CrossRefs' => [['SeriesID' => null, 'AnidbAnimeID' => 42]]];

    expect(ShokoEvents::normalize('release:saved', [$file])['type'])->toBe(ReconcilerEventType::FileMatched)
        ->and(ShokoEvents::normalize('metadata:series.added', [['SeriesID' => 5]])['type'])->toBe(ReconcilerEventType::SeriesAdded)
        ->and(ShokoEvents::normalize('metadata:series.updated', [['SeriesID' => 5]]))->toBeNull()
        ->and(ShokoEvents::normalize('ShokoEvent:SeriesUpdated', [['Reason' => 'Updated']]))->toBeNull()
        ->and(ShokoEvents::folderId($file))->toBe(3)
        ->and(ShokoEvents::firstReference($file, 'AnidbAnimeID'))->toBe(42)
        ->and(ShokoEvents::filename($file))->toBe('[SubsPlease] X - 01 (1080p) [AAAAAAAA].mkv');
});

// ── Jellyfin events ──────────────────────────────────────────────────────────

test('LibraryChanged for the Anime library is kept, with its virtual-season noise left unread', function () {
    [$forceKeepAlive, $keepAlive, $libraryChanged] = jellyfinCapture();

    $event = JellyfinEvents::normalize($libraryChanged, ANIME_LIBRARY);

    expect(JellyfinEvents::normalize($forceKeepAlive, ANIME_LIBRARY))->toBeNull()
        ->and(JellyfinEvents::normalize($keepAlive, ANIME_LIBRARY))->toBeNull()
        ->and($event['type'])->toBe(ReconcilerEventType::LibraryChanged)
        // The noise: the same virtual seasons both added and removed.
        ->and(array_intersect($event['payload']['ItemsAdded'], $event['payload']['ItemsRemoved']))->toHaveCount(3);
});

test('LibraryChanged for other libraries only is dropped', function () {
    $message = jellyfinCapture()[2];
    $message['Data']['CollectionFolders'] = ['abebc196cc1b8bbf6f8bb5ca7b5ad6f1'];

    expect(JellyfinEvents::normalize($message, ANIME_LIBRARY))->toBeNull()
        ->and(JellyfinEvents::normalize(jellyfinCapture()[2], ''))->toBeNull();
});
