<?php

declare(strict_types=1);

use App\Mcp\Data\QueryTermMatcher;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    DB::table('genres')->insert(array_map(fn (string $name) => ['name' => $name], ['Romance', 'Slice of Life', 'Sci-Fi', 'Comedy']));
    DB::table('tags')->insert(array_map(fn (string $name) => ['name' => $name], [
        'Time Travel', 'Travel', 'Magic', 'Magic School', "Boys' Love", 'LGBTQ+ Themes', 'Cats', 'Cooking', 'AI',
    ]));
});

/**
 * @return array{genres: array<int, string>, tags: array<int, string>, unrecognised: array<int, string>}
 */
function terms(string $query): array
{
    $matched = app(QueryTermMatcher::class)->match($query);

    return [
        'genres' => array_column($matched['genres'], 'name'),
        'tags' => array_column($matched['tags'], 'name'),
        'unrecognised' => $matched['unrecognised'],
    ];
}

test('"time travel" is the tag Time Travel and never also Travel', function () {
    expect(terms('something with time travel and romance'))->toBe(['genres' => ['Romance'], 'tags' => ['Time Travel'], 'unrecognised' => []]);
});

test('the longest phrase claims its words first', function () {
    expect(terms('a magic school drama'))->toBe(['genres' => [], 'tags' => ['Magic School'], 'unrecognised' => ['drama']])
        ->and(terms('magic, school'))->toBe(['genres' => [], 'tags' => ['Magic'], 'unrecognised' => ['school']]);
});

test('names never match across punctuation', function () {
    expect(terms('time, travel'))->toBe(['genres' => [], 'tags' => ['Travel'], 'unrecognised' => ['time']]);
});

test('case, hyphens and apostrophes are ignored', function () {
    expect(terms('SCI FI with boys love; LGBTQ+ themes'))->toBe(['genres' => ['Sci-Fi'], 'tags' => ["Boys' Love", 'LGBTQ+ Themes'], 'unrecognised' => []])
        ->and(terms('sci-fi'))->toBe(['genres' => ['Sci-Fi'], 'tags' => [], 'unrecognised' => []])
        ->and(terms("boys' love"))->toBe(['genres' => [], 'tags' => ["Boys' Love"], 'unrecognised' => []]);
});

test('only whole words match, and names under three letters are never matched', function () {
    expect(terms('magical travelling'))->toBe(['genres' => [], 'tags' => [], 'unrecognised' => ['magical travelling']])
        ->and(terms('ai'))->toBe(['genres' => [], 'tags' => [], 'unrecognised' => ['ai']]);
});

test('leftover words come back as unrecognised phrases, split at punctuation, matches and filler', function () {
    expect(terms('cozy, wholesome slice of life with cats, something like Frieren'))->toBe([
        'genres' => ['Slice of Life'],
        'tags' => ['Cats'],
        'unrecognised' => ['cozy', 'wholesome', 'Frieren'],
    ])->and(terms('I want a really good comedy please'))->toBe(['genres' => ['Comedy'], 'tags' => [], 'unrecognised' => []]);
});

test('each name is reported once however often it appears', function () {
    expect(terms('cooking, more cooking'))->toBe(['genres' => [], 'tags' => ['Cooking'], 'unrecognised' => []]);
});

// ── Compound guard ───────────────────────────────────────────────────────────

test('without a Time Travel tag, "time travel" applies nothing and comes back whole', function () {
    DB::table('tags')->where('name', 'Time Travel')->delete();

    expect(terms('something with time travel and romance'))->toBe(['genres' => ['Romance'], 'tags' => [], 'unrecognised' => ['time travel']]);
});

test('a one-word match right after an unrecognised word is not applied', function () {
    DB::table('genres')->insert(['name' => 'Fantasy']);

    expect(terms('dark fantasy'))->toBe(['genres' => [], 'tags' => [], 'unrecognised' => ['dark fantasy']])
        ->and(terms('dark, fantasy'))->toBe(['genres' => ['Fantasy'], 'tags' => [], 'unrecognised' => ['dark']]);
});

test('the guard only looks back: a word after a match, filler before it, or a known neighbour do not block it', function () {
    expect(terms('magic academy'))->toBe(['genres' => [], 'tags' => ['Magic'], 'unrecognised' => ['academy']])
        ->and(terms('a really good comedy'))->toBe(['genres' => ['Comedy'], 'tags' => [], 'unrecognised' => []])
        ->and(terms('romance comedy'))->toBe(['genres' => ['Romance', 'Comedy'], 'tags' => [], 'unrecognised' => []]);
});

test('the guard never applies to multi-word names', function () {
    expect(terms('cozy slice of life'))->toBe(['genres' => ['Slice of Life'], 'tags' => [], 'unrecognised' => ['cozy']]);
});

test('a word after a guarded one is judged against the original matches', function () {
    DB::table('tags')->where('name', 'Time Travel')->delete();

    expect(terms('time travel romance'))->toBe(['genres' => ['Romance'], 'tags' => [], 'unrecognised' => ['time travel']]);
});

// ── Exact names (tags[]) ─────────────────────────────────────────────────────

test('lookup resolves exact names, ignoring case and punctuation, and reports the rest', function () {
    $found = app(QueryTermMatcher::class)->lookup(['sci fi', 'MAGIC SCHOOL', "boys' love", 'AI', 'Time Manipulation', 'magic school']);

    expect(array_column($found['genres'], 'name'))->toBe(['Sci-Fi'])
        ->and(array_column($found['tags'], 'name'))->toBe(['Magic School', "Boys' Love", 'AI'])
        ->and($found['unknown'])->toBe(['Time Manipulation']);
});
