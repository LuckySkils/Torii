<?php

declare(strict_types=1);

use App\Services\Reconciler\ShokoClient;

/**
 * Production capture (2026-11-08) of `GET /api/v3/File/2721?include=XRefs&includeXRefs=true`:
 * delivery #6 (Shinja Zero 01), linked to Shoko series 255 a month earlier.
 *
 * @return array<string, mixed>
 */
function shokoFile2721(): array
{
    return json_decode(file_get_contents(__DIR__.'/../../../Fixtures/reconciler/shoko_file_2721_xrefs.json'), true, flags: JSON_THROW_ON_ERROR);
}

test('a linked file, as Shoko really answers: its series id', function () {
    expect(ShokoClient::seriesIds(shokoFile2721()))->toBe([255]);
});

/*
 * The unlinked shape has never been captured. These are constructed from the
 * real response by editing only the series part: every guess at "matched to
 * AniDB, not linked to a Shoko series yet" must read as not linked, since a
 * delivery whose Shoko series is unknown counts any series as linked.
 */
test('constructed unlinked shapes read as not linked', function (Closure $edit) {
    $file = shokoFile2721();
    $edit($file);

    expect(ShokoClient::seriesIds($file))->toBe([]);
})->with([
    'constructed: SeriesID.ID is 0' => [function (array &$file) {
        $file['SeriesIDs'][0]['SeriesID']['ID'] = 0;
    }],
    'constructed: SeriesID.ID is null' => [function (array &$file) {
        $file['SeriesIDs'][0]['SeriesID']['ID'] = null;
    }],
    'constructed: SeriesID.ID is missing (AniDB id only)' => [function (array &$file) {
        unset($file['SeriesIDs'][0]['SeriesID']['ID']);
    }],
    'constructed: SeriesID.ID is negative' => [function (array &$file) {
        $file['SeriesIDs'][0]['SeriesID']['ID'] = -1;
    }],
    'constructed: SeriesID.ID is not numeric' => [function (array &$file) {
        $file['SeriesIDs'][0]['SeriesID']['ID'] = 'pending';
    }],
    'constructed: SeriesID is null' => [function (array &$file) {
        $file['SeriesIDs'][0]['SeriesID'] = null;
    }],
    'constructed: SeriesID is 0' => [function (array &$file) {
        $file['SeriesIDs'][0]['SeriesID'] = 0;
    }],
    'constructed: SeriesID is missing (episode xrefs only)' => [function (array &$file) {
        unset($file['SeriesIDs'][0]['SeriesID']);
    }],
    'constructed: SeriesIDs is empty' => [function (array &$file) {
        $file['SeriesIDs'] = [];
    }],
    'constructed: SeriesIDs is null' => [function (array &$file) {
        $file['SeriesIDs'] = null;
    }],
    'constructed: SeriesIDs is missing' => [function (array &$file) {
        unset($file['SeriesIDs']);
    }],
]);

test('constructed: an unlinked entry next to a linked one keeps only the linked series', function () {
    $file = shokoFile2721();
    $file['SeriesIDs'][] = ['SeriesID' => ['ID' => 0, 'AniDB' => 19822], 'EpisodeIDs' => $file['SeriesIDs'][0]['EpisodeIDs']];

    expect(ShokoClient::seriesIds($file))->toBe([255]);
});

test('constructed: the event-style shape with series 0 reads as not linked', function () {
    expect(ShokoClient::seriesIds(['CrossReferences' => [['SeriesID' => 0, 'AnidbAnimeID' => 19822]]]))->toBe([]);
});
