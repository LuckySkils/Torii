<?php

declare(strict_types=1);

use App\Services\Feed\Infohash;

function base32EncodeForTest(string $bytes): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';

    foreach (str_split($bytes) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }

    $output = '';

    foreach (str_split($bits, 5) as $chunk) {
        $output .= $alphabet[bindec($chunk)];
    }

    return $output;
}

test('round-trips a random 20-byte hash through base32 back to its original hex', function () {
    $bytes = random_bytes(20);
    $hex = bin2hex($bytes);
    $base32 = base32EncodeForTest($bytes);

    expect(Infohash::normalize($base32))->toBe($hex);
});

test('a 40-char hex hash is lowercased', function () {
    expect(Infohash::normalize('DEADBEEFDEADBEEFDEADBEEFDEADBEEFDEADBEEF'))
        ->toBe('deadbeefdeadbeefdeadbeefdeadbeefdeadbeef');
});

test('normalizes every real base32 infohash from the feed fixture to 40-char lowercase hex', function () {
    $xml = file_get_contents(dirname(__DIR__, 3).'/Fixtures/subsplease_1080.xml');
    preg_match_all('/xt=urn:btih:([0-9A-Za-z]+)/', $xml, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $raw) {
        expect(strlen($raw))->toBe(32); // confirms the fixture uses base32, not 40-char hex

        $normalized = Infohash::normalize($raw);

        expect($normalized)->not->toBeNull()
            ->and($normalized)->toMatch('/^[0-9a-f]{40}$/');
    }
});

test('returns null for input that is neither 40-char hex nor 32-char base32', function () {
    expect(Infohash::normalize('not-a-hash'))->toBeNull()
        ->and(Infohash::normalize(''))->toBeNull()
        ->and(Infohash::normalize(null))->toBeNull()
        ->and(Infohash::normalize('AAAA1'))->toBeNull();
});

test('returns null for a 32-character string with a character outside the base32 alphabet', function () {
    $invalid = str_repeat('01', 16); // exactly 32 chars; '0' and '1' aren't in the base32 alphabet

    expect(strlen($invalid))->toBe(32)
        ->and(Infohash::normalize($invalid))->toBeNull();
});
