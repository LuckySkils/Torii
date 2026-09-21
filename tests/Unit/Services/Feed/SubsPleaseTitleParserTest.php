<?php

declare(strict_types=1);

use App\Services\Feed\SubsPleaseTitleParser;

beforeEach(function () {
    $this->parser = new SubsPleaseTitleParser;
});

test('parses a standard episode title using the category as the name source', function () {
    $parsed = $this->parser->parse(
        '[SubsPlease] Grand Blue S3 - 12 (1080p) [68C3DEDA].mkv',
        'Grand Blue S3 - 1080',
    );

    expect($parsed->name)->toBe('Grand Blue S3')
        ->and($parsed->episode)->toBe('12')
        ->and($parsed->version)->toBeNull()
        ->and($parsed->isBatch)->toBeFalse()
        ->and($parsed->resolution)->toBe('1080p')
        ->and($parsed->crc)->toBe('68C3DEDA');
});

test('falls back to the title regex for the name when no category is given', function () {
    $parsed = $this->parser->parse(
        '[SubsPlease] Grand Blue S3 - 12 (1080p) [68C3DEDA].mkv',
        null,
    );

    expect($parsed->name)->toBe('Grand Blue S3')
        ->and($parsed->episode)->toBe('12');
});

test('parses a batch release with no episode or crc', function () {
    $parsed = $this->parser->parse(
        '[SubsPlease] Mujikaku Seijo wa Kyou mo Muishiki ni Chikara wo Tare Nagasu (01-12) (1080p) [Batch]',
        'Mujikaku Seijo wa Kyou mo Muishiki ni Chikara wo Tare Nagasu - 1080',
    );

    expect($parsed->name)->toBe('Mujikaku Seijo wa Kyou mo Muishiki ni Chikara wo Tare Nagasu')
        ->and($parsed->episode)->toBeNull()
        ->and($parsed->version)->toBeNull()
        ->and($parsed->isBatch)->toBeTrue()
        ->and($parsed->batchFrom)->toBe(1)
        ->and($parsed->batchTo)->toBe(12)
        ->and($parsed->resolution)->toBe('1080p')
        ->and($parsed->crc)->toBeNull();
});

test('leaves the batch range null when it does not parse as <number>-<number>', function () {
    $parsed = $this->parser->parse(
        '[SubsPlease] Some Show (Season 2) (1080p) [Batch]',
        'Some Show - 1080',
    );

    expect($parsed->name)->toBe('Some Show')
        ->and($parsed->isBatch)->toBeTrue()
        ->and($parsed->batchFrom)->toBeNull()
        ->and($parsed->batchTo)->toBeNull();
});

test('parses a v2 repack and captures the version number', function () {
    $parsed = $this->parser->parse(
        '[SubsPlease] Tenmaku no Jaadugar - 12v2 (1080p) [008D3FCF].mkv',
        'Tenmaku no Jaadugar - 1080',
    );

    expect($parsed->name)->toBe('Tenmaku no Jaadugar')
        ->and($parsed->episode)->toBe('12')
        ->and($parsed->version)->toBe(2)
        ->and($parsed->isBatch)->toBeFalse();
});

test('splits on the last " - <number>" for names that contain their own hyphenated subtitle', function () {
    $parsed = $this->parser->parse(
        '[SubsPlease] Kaijuu 8-gou - Narumi no Heijitsu - 03 (1080p) [44506493].mkv',
        'Kaijuu 8-gou - Narumi no Heijitsu - 1080',
    );

    expect($parsed->name)->toBe('Kaijuu 8-gou - Narumi no Heijitsu')
        ->and($parsed->episode)->toBe('03');
});

test('splits on the last " - <number>" even without a category to guide it', function () {
    $parsed = $this->parser->parse(
        '[SubsPlease] Kaijuu 8-gou - Narumi no Heijitsu - 03 (1080p) [44506493].mkv',
        null,
    );

    expect($parsed->name)->toBe('Kaijuu 8-gou - Narumi no Heijitsu')
        ->and($parsed->episode)->toBe('03');
});

test('parses multi-digit episode numbers', function () {
    $parsed = $this->parser->parse(
        '[SubsPlease] One Piece - 1179 (1080p) [A02F35FE].mkv',
        'One Piece - 1080',
    );

    expect($parsed->name)->toBe('One Piece')
        ->and($parsed->episode)->toBe('1179');
});

test('parses decimal episode numbers', function () {
    $parsed = $this->parser->parse(
        '[SubsPlease] Show Name - 12.5 (1080p) [ABCD1234].mkv',
        'Show Name - 1080',
    );

    expect($parsed->name)->toBe('Show Name')
        ->and($parsed->episode)->toBe('12.5')
        ->and($parsed->version)->toBeNull();
});

test('keeps the show name with a null episode when the title has no episode number', function () {
    $parsed = $this->parser->parse(
        '[SubsPlease] Show Name (1080p) [ABCD1234].mkv',
        'Show Name - 1080',
    );

    expect($parsed->name)->toBe('Show Name')
        ->and($parsed->episode)->toBeNull()
        ->and($parsed->isBatch)->toBeFalse()
        ->and($parsed->crc)->toBe('ABCD1234');
});

test('returns a null name for a title that matches none of the known shapes', function () {
    $parsed = $this->parser->parse('Not a SubsPlease release at all', null);

    expect($parsed->name)->toBeNull()
        ->and($parsed->episode)->toBeNull()
        ->and($parsed->isBatch)->toBeFalse();
});

test('every item in the real feed fixture parses with a name, and the category always agrees', function () {
    $xml = simplexml_load_file(dirname(__DIR__, 3).'/Fixtures/subsplease_1080.xml');

    expect($xml)->not->toBeFalse();

    $items = $xml->channel->item;
    expect(count($items))->toBeGreaterThan(0);

    foreach ($items as $item) {
        $title = (string) $item->title;
        $category = isset($item->category) ? (string) $item->category : null;

        $parsed = $this->parser->parse($title, $category);

        expect($parsed->name)
            ->not->toBeNull("Failed to parse title: {$title}")
            ->and($parsed->resolution)->toBe('1080p');

        if ($category !== null) {
            $expectedName = preg_replace('/\s+-\s+1080$/', '', $category);
            expect($parsed->name)->toBe($expectedName);
        }

        if (str_contains($title, '[Batch]')) {
            expect($parsed->isBatch)->toBeTrue()
                ->and($parsed->episode)->toBeNull()
                ->and($parsed->batchFrom)->not->toBeNull("Failed to parse batch range: {$title}")
                ->and($parsed->batchTo)->toBeGreaterThan($parsed->batchFrom);
        } else {
            expect($parsed->isBatch)->toBeFalse();
        }
    }
});
