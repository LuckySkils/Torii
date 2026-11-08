<?php

declare(strict_types=1);

use App\Services\Feed\SubsPleaseTitleParser;
use App\Services\Releases\ParsedRelease;
use App\Services\Releases\ReleaseTitleParser;

beforeEach(function () {
    $this->parser = new ReleaseTitleParser;
});

/**
 * @return array<string, mixed>
 */
function parsedFields(ParsedRelease $parsed): array
{
    return [
        'group' => $parsed->group,
        'name' => $parsed->name,
        'season' => $parsed->season,
        'episode' => $parsed->episode,
        'version' => $parsed->version,
        'isBatch' => $parsed->isBatch,
        'batchFrom' => $parsed->batchFrom,
        'batchTo' => $parsed->batchTo,
        'resolution' => $parsed->resolution,
        'crc' => $parsed->crc,
        'altTitles' => $parsed->altTitles,
    ];
}

// ── Real titles the owner gave (verbatim) ────────────────────────────────────

test('real: Erai-raws, " - 01" with a tag block, MultiSub and a trailing CRC', function () {
    $parsed = $this->parser->parse('[Erai-raws] Demons Crest - 01 [1080p AMZN WEBRip HEVC EAC3][MultiSub][B2E1DE46]');

    expect(parsedFields($parsed))->toBe([
        'group' => 'Erai-raws', 'name' => 'Demons Crest', 'season' => null, 'episode' => '01', 'version' => null,
        'isBatch' => false, 'batchFrom' => null, 'batchTo' => null, 'resolution' => '1080p', 'crc' => 'B2E1DE46', 'altTitles' => [],
    ])->and($parsed->suggestedShowName())->toBe('Demons Crest');
});

test('real: Judas, " - S02E06" with tag blocks and (Weekly)', function () {
    $parsed = $this->parser->parse('[Judas] Black Clover - S02E06 [1080p][HEVC x265 10bit][Multi-Subs] (Weekly)');

    expect(parsedFields($parsed))->toBe([
        'group' => 'Judas', 'name' => 'Black Clover', 'season' => 2, 'episode' => '06', 'version' => null,
        'isBatch' => false, 'batchFrom' => null, 'batchTo' => null, 'resolution' => '1080p', 'crc' => null, 'altTitles' => [],
    ])->and($parsed->suggestedShowName())->toBe('Black Clover S2');
});

test('real: ToonsHub, bare S01E06, loose technical tokens and alt titles in the trailing parenthesis', function () {
    $parsed = $this->parser->parse('[ToonsHub] TANK CHAIR S01E06 1080p AMZN WEB-DL DUAL DDP5.1 H.265 (TANK CHAIR: Sensha Isu, Dual-Audio, Multi-Subs)');

    expect(parsedFields($parsed))->toBe([
        'group' => 'ToonsHub', 'name' => 'TANK CHAIR', 'season' => 1, 'episode' => '06', 'version' => null,
        'isBatch' => false, 'batchFrom' => null, 'batchTo' => null, 'resolution' => '1080p', 'crc' => null,
        'altTitles' => ['TANK CHAIR: Sensha Isu', 'Sensha Isu'],
    ])->and($parsed->suggestedShowName())->toBe('TANK CHAIR');
});

test('real: every title of the Nyaa fixture parses exactly as SubsPlease\'s parser reads it', function () {
    $xml = simplexml_load_file(__DIR__.'/../../../Fixtures/nyaa/search_rss.xml');
    $subsPlease = new SubsPleaseTitleParser;
    $count = 0;

    foreach ($xml->channel->item as $item) {
        $title = (string) $item->title;
        $parsed = $this->parser->parse($title);
        $expected = $subsPlease->parse($title, null);
        $count++;

        expect($parsed->group)->toBe('SubsPlease')
            ->and($parsed->name)->toBe('Hanaori-san wa Tensei shitemo Kenka ga Shitai')
            ->and([$parsed->name, $parsed->episode, $parsed->version, $parsed->isBatch, $parsed->batchFrom, $parsed->batchTo, $parsed->resolution, $parsed->crc])
            ->toBe([$expected->name, $expected->episode, $expected->version, $expected->isBatch, $expected->batchFrom, $expected->batchTo, $expected->resolution, $expected->crc])
            ->and($parsed->season)->toBeNull()
            ->and($parsed->suggestedShowName())->toBe($expected->name);
    }

    expect($count)->toBe(12);
});

test('real: every title of the SubsPlease feed fixture reads as SubsPlease\'s parser reads it', function () {
    $xml = simplexml_load_file(__DIR__.'/../../../Fixtures/subsplease_1080.xml');
    $subsPlease = new SubsPleaseTitleParser;

    foreach ($xml->channel->item as $item) {
        $title = (string) $item->title;
        $parsed = $this->parser->parse($title);
        $expected = $subsPlease->parse($title, null);

        expect([$parsed->group, $parsed->name, $parsed->episode, $parsed->version, $parsed->isBatch, $parsed->batchFrom, $parsed->batchTo, $parsed->resolution, $parsed->crc])
            ->toBe(['SubsPlease', $expected->name, $expected->episode, $expected->version, $expected->isBatch, $expected->batchFrom, $expected->batchTo, $expected->resolution, $expected->crc]);
    }
});

// ── Constructed titles: general shapes (no real sample yet) ──────────────────

test('constructed: SubsPlease batches, v2s and later seasons keep SubsPlease\'s reading', function () {
    $batch = $this->parser->parse('[SubsPlease] Ao Ashi S2 (01-12) (1080p) [Batch]');
    $v2 = $this->parser->parse('[SubsPlease] Ao Ashi S2 - 06v2 (1080p) [ABCDEF12].mkv');

    expect([$batch->group, $batch->name, $batch->season, $batch->isBatch, $batch->batchFrom, $batch->batchTo, $batch->suggestedShowName()])
        ->toBe(['SubsPlease', 'Ao Ashi S2', null, true, 1, 12, 'Ao Ashi S2'])
        ->and([$v2->name, $v2->episode, $v2->version, $v2->suggestedShowName()])->toBe(['Ao Ashi S2', '06', 2, 'Ao Ashi S2']);
});

test('constructed: an unrecognised SubsPlease title stays unparsed, as today', function () {
    $parsed = $this->parser->parse('[SubsPlease] Something odd');

    expect($parsed->name)->toBeNull()->and($parsed->group)->toBe('SubsPlease')->and($parsed->episode)->toBeNull();
});

test('constructed: episode shapes', function (string $title, array $expected) {
    $parsed = $this->parser->parse($title);

    expect([$parsed->group, $parsed->name, $parsed->season, $parsed->episode, $parsed->version, $parsed->resolution, $parsed->crc, $parsed->isBatch])
        ->toBe($expected);
})->with([
    'dash episode with extension' => ['[Erai-raws] Demons Crest - 01 [1080p][MultiSub][B2E1DE46].mkv', ['Erai-raws', 'Demons Crest', null, '01', null, '1080p', 'B2E1DE46', false]],
    'dash v2' => ['[Erai-raws] Demons Crest - 06v2 [1080p][MultiSub]', ['Erai-raws', 'Demons Crest', null, '06', 2, '1080p', null, false]],
    'dash .5' => ['[Erai-raws] Demons Crest - 12.5 [720p]', ['Erai-raws', 'Demons Crest', null, '12.5', null, '720p', null, false]],
    'parenthesised resolution' => ['[ASW] Demons Crest - 03 (1080p) [HEVC x265 10Bit][AAC]', ['ASW', 'Demons Crest', null, '03', null, '1080p', null, false]],
    'S02E06 v2' => ['[Judas] Black Clover - S02E07v2 [2160p][HEVC]', ['Judas', 'Black Clover', 2, '07', 2, '2160p', null, false]],
    'lowercase s02e06' => ['[Grp] Black Clover s02e06 [480p]', ['Grp', 'Black Clover', 2, '06', null, '480p', null, false]],
    'bare E06' => ['[Grp] Demons Crest E06 [1080p]', ['Grp', 'Demons Crest', null, '06', null, '1080p', null, false]],
    'tags before the name' => ['[Grp][1080p] Demons Crest - 04', ['Grp', 'Demons Crest', null, '04', null, '1080p', null, false]],
    'dashes in the name' => ['[Erai-raws] Re:Zero - Starting Life in Another World - 05 [1080p]', ['Erai-raws', 'Re:Zero - Starting Life in Another World', null, '05', null, '1080p', null, false]],
    'season words stay in the name' => ['[Erai-raws] Ao Ashi 2nd Season - 05 [1080p]', ['Erai-raws', 'Ao Ashi 2nd Season', null, '05', null, '1080p', null, false]],
    'a number in the name' => ['[Grp] Mob Psycho 100 - 02 [1080p]', ['Grp', 'Mob Psycho 100', null, '02', null, '1080p', null, false]],
    'no group' => ['Black Clover - S02E06 [1080p]', [null, 'Black Clover', 2, '06', null, '1080p', null, false]],
    'no resolution' => ['[Grp] Demons Crest - 08', ['Grp', 'Demons Crest', null, '08', null, null, null, false]],
]);

test('constructed: batch shapes', function (string $title, array $expected) {
    $parsed = $this->parser->parse($title);

    expect([$parsed->group, $parsed->name, $parsed->season, $parsed->episode, $parsed->isBatch, $parsed->batchFrom, $parsed->batchTo, $parsed->resolution])
        ->toBe($expected);
})->with([
    'parenthesised range' => ['[Erai-raws] Demons Crest (01-12) [1080p][Batch]', ['Erai-raws', 'Demons Crest', null, null, true, 1, 12, '1080p']],
    'dash tilde range' => ['[Judas] Black Clover - 01 ~ 12 [1080p][Batch]', ['Judas', 'Black Clover', null, null, true, 1, 12, '1080p']],
    'dash hyphen range' => ['[Grp] Black Clover - 13-24 [720p]', ['Grp', 'Black Clover', null, null, true, 13, 24, '720p']],
    'S01E01-E12' => ['[ToonsHub] TANK CHAIR S01E01-E12 1080p WEB-DL', ['ToonsHub', 'TANK CHAIR', 1, null, true, 1, 12, '1080p']],
    'S02E01-12' => ['[Judas] Black Clover - S02E01-12 [1080p]', ['Judas', 'Black Clover', 2, null, true, 1, 12, '1080p']],
    'season pack' => ['[Judas] Black Clover S02 [1080p][HEVC x265 10bit][Multi-Subs]', ['Judas', 'Black Clover', 2, null, true, null, null, '1080p']],
    'Batch token' => ['[Grp] Demons Crest [1080p][Batch]', ['Grp', 'Demons Crest', null, null, true, null, null, '1080p']],
    'loose Batch word' => ['[Grp] Demons Crest Batch 1080p', ['Grp', 'Demons Crest', null, null, true, null, null, '1080p']],
]);

test('constructed: a season pack suggests the later-season show name', function () {
    expect($this->parser->parse('[Judas] Black Clover S02 [1080p]')->suggestedShowName())->toBe('Black Clover S2')
        ->and($this->parser->parse('[ToonsHub] TANK CHAIR S01E01-E12 1080p')->suggestedShowName())->toBe('TANK CHAIR');
});

test('constructed: dot-separated scene names', function () {
    $parsed = $this->parser->parse('Tank.Chair.S01E06.1080p.WEB-DL.H.264-SCENE.mkv');

    expect([$parsed->group, $parsed->name, $parsed->season, $parsed->episode, $parsed->resolution])
        ->toBe(['SCENE', 'Tank Chair', 1, '06', '1080p']);
});

test('constructed: alt titles drop technical tokens and the name itself', function () {
    expect($this->parser->parse('[Grp] Demons Crest - 01 [1080p] (Akuma no Monshou, Dual Audio, English Dub, HEVC)')->altTitles)
        ->toBe(['Akuma no Monshou'])
        ->and($this->parser->parse('[Grp] Demons Crest - 01 (Weekly) (Multi-Subs)')->altTitles)->toBe([]);
});

test('unrecognised shapes are unparsed: no name, no guessed episode, never an exception', function (string $title, ?string $group) {
    $parsed = $this->parser->parse($title);

    expect($parsed->name)->toBeNull()
        ->and($parsed->episode)->toBeNull()
        ->and($parsed->isParsed())->toBeFalse()
        ->and($parsed->suggestedShowName())->toBeNull()
        ->and($parsed->group)->toBe($group);
})->with([
    'no episode marker' => ['[Erai-raws] Demons Crest [1080p][MultiSub]', 'Erai-raws'],
    'a movie' => ['[Grp] Some Movie (2024) [1080p]', 'Grp'],
    'only a group' => ['[Erai-raws]', 'Erai-raws'],
    'empty' => ['', null],
    'free text' => ['just some words', null],
    'only a marker' => ['[Grp] - 05 [1080p]', 'Grp'],
]);
