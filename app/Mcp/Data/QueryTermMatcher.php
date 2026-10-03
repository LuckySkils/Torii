<?php

declare(strict_types=1);

namespace App\Mcp\Data;

use Illuminate\Support\Facades\DB;

/**
 * Reads genre and tag names out of suggest_anime's free-text query, and resolves
 * the exact names passed in its tags[].
 *
 * Names match as whole phrases of words (case, hyphens and apostrophes ignored:
 * "sci fi" is Sci-Fi, "boys love" is Boys' Love), never across punctuation, and
 * longest first: a word claimed by a longer match can't match again. The
 * vocabulary is every name in `genres`/`tags`, which the vocabulary sync fills
 * with the provider's full lists.
 *
 * Compound guard: a one-word match directly after an unrecognised word (only
 * spaces between) is not applied, since the two likely form a phrase the
 * vocabulary doesn't have: "time travel" applies nothing rather than Travel, and
 * comes back whole as unrecognised. English compounds put the head word last, so
 * a word *after* a match doesn't block it ("magic academy" still applies Magic).
 * Leftover words that aren't filler come back as unrecognised phrases, so the
 * model knows that part of the request wasn't applied.
 */
final class QueryTermMatcher
{
    /** Filler words that are never reported as unrecognised and never trigger the guard. */
    private const STOPWORDS = [
        'a', 'about', 'after', 'all', 'also', 'an', 'and', 'any', 'anything', 'are', 'as', 'at', 'be', 'best', 'but', 'by',
        'can', 'could', 'do', 'does', 'find', 'for', 'from', 'get', 'give', 'good', 'great', 'have', 'i', 'im', 'in', 'into',
        'is', 'it', 'its', 'kind', 'kinds', 'like', 'likes', 'liked', 'looking', 'lot', 'lots', 'me', 'more', 'most', 'much',
        'my', 'new', 'of', 'on', 'one', 'ones', 'or', 'please', 'plus', 'recommend', 'recommendation', 'recommendations',
        'series', 'show', 'shows', 'similar', 'so', 'some', 'something', 'sort', 'stuff', 'suggest', 'that', 'the', 'there',
        'these', 'thing', 'things', 'this', 'those', 'to', 'type', 'very', 'want', 'wants', 'watch', 'watching', 'we',
        'what', 'where', 'which', 'with', 'would', 'you', 'anime', 'animes', 'really', 'maybe', 'just', 'than', 'then',
    ];

    /** Punctuation that ends a phrase: names never match across it. */
    private const BREAK = '/[,;:.!?()\[\]{}\/|"]/u';

    /** In free text, a name this short (normalized) would match too much to be useful; tags[] takes any. */
    private const MIN_NAME_LENGTH = 3;

    /** @var array<string, array{genre?: array{id: int, name: string}, tag?: array{id: int, name: string}}>|null */
    private ?array $vocabulary = null;

    /**
     * @return array{genres: array<int, array{id: int, name: string}>, tags: array<int, array{id: int, name: string}>, unrecognised: array<int, string>}
     */
    public function match(string $query): array
    {
        $tokens = $this->tokens($query);
        $vocabulary = $this->vocabulary();
        $longest = max(1, ...array_map(fn (string $key) => substr_count($key, ' ') + 1, array_keys($vocabulary) ?: ['']));

        // Every vocabulary phrase found in the query, as start + length.
        $found = [];
        foreach ($tokens as $start => $_) {
            for ($length = 1; $length <= $longest && $start + $length <= count($tokens); $length++) {
                if ($length > 1 && $tokens[$start + $length - 1]['breakBefore']) {
                    break;
                }

                $key = implode(' ', array_column(array_slice($tokens, $start, $length), 'word'));

                if (isset($vocabulary[$key]) && mb_strlen($key) >= self::MIN_NAME_LENGTH) {
                    $found[] = ['start' => $start, 'length' => $length, 'key' => $key];
                }
            }
        }

        // Longest first (then longer text, then earlier): a span claimed once is gone.
        usort($found, fn (array $a, array $b) => [$b['length'], strlen($b['key']), $a['start']] <=> [$a['length'], strlen($a['key']), $b['start']]);

        $claimed = [];
        $matches = [];
        foreach ($found as $match) {
            $span = range($match['start'], $match['start'] + $match['length'] - 1);

            if (array_intersect($span, $claimed) === []) {
                $claimed = [...$claimed, ...$span];
                $matches[] = $match;
            }
        }

        // The compound guard, against the claims above.
        $matches = array_values(array_filter($matches, fn (array $match) => ! $this->partOfUnknownPhrase($match, $tokens, $claimed)));
        $claimed = array_merge([], ...array_map(fn (array $match) => range($match['start'], $match['start'] + $match['length'] - 1), $matches));

        // Each name once, in the order the query names it.
        usort($matches, fn (array $a, array $b) => $a['start'] <=> $b['start']);
        $genres = $tags = [];
        foreach ($matches as $match) {
            $entry = $vocabulary[$match['key']];

            if (isset($entry['genre'])) {
                $genres[$entry['genre']['id']] ??= $entry['genre'];
            }

            if (isset($entry['tag'])) {
                $tags[$entry['tag']['id']] ??= $entry['tag'];
            }
        }

        return [
            'genres' => array_values($genres),
            'tags' => array_values($tags),
            'unrecognised' => $this->unrecognised($tokens, $claimed),
        ];
    }

    /**
     * Exact names (as list_tags returns them; case, hyphens and apostrophes
     * ignored), each resolved to a genre and/or tag. Names that are neither come
     * back in `unknown`, as given.
     *
     * @param  array<int, string>  $names
     * @return array{genres: array<int, array{id: int, name: string}>, tags: array<int, array{id: int, name: string}>, unknown: array<int, string>}
     */
    public function lookup(array $names): array
    {
        $vocabulary = $this->vocabulary();
        $genres = $tags = $unknown = [];

        foreach ($names as $name) {
            $entry = $vocabulary[$this->key((string) $name)] ?? null;

            if ($entry === null) {
                $unknown[] = (string) $name;

                continue;
            }

            if (isset($entry['genre'])) {
                $genres[$entry['genre']['id']] = $entry['genre'];
            }

            if (isset($entry['tag'])) {
                $tags[$entry['tag']['id']] = $entry['tag'];
            }
        }

        return ['genres' => array_values($genres), 'tags' => array_values($tags), 'unknown' => array_values(array_unique($unknown))];
    }

    /**
     * @param  array{start: int, length: int, key: string}  $match
     * @param  array<int, array{word: string, text: string, breakBefore: bool}>  $tokens
     * @param  array<int, int>  $claimed
     */
    private function partOfUnknownPhrase(array $match, array $tokens, array $claimed): bool
    {
        $before = $match['start'] - 1;

        return $match['length'] === 1
            && $before >= 0
            && ! $tokens[$match['start']]['breakBefore']
            && ! in_array($before, $claimed, true)
            && ! in_array($tokens[$before]['word'], self::STOPWORDS, true);
    }

    /**
     * Runs of unclaimed, non-filler words, split at punctuation, claimed phrases
     * and filler: "cozy, wholesome slice of life" → ["cozy", "wholesome"].
     *
     * @param  array<int, array{word: string, text: string, breakBefore: bool}>  $tokens
     * @param  array<int, int>  $claimed
     * @return array<int, string>
     */
    private function unrecognised(array $tokens, array $claimed): array
    {
        $phrases = [];
        $run = [];

        foreach ($tokens as $index => $token) {
            $ends = $token['breakBefore'] || in_array($index, $claimed, true) || in_array($token['word'], self::STOPWORDS, true);

            if ($ends && $run !== []) {
                $phrases[] = implode(' ', $run);
                $run = [];
            }

            if (! in_array($index, $claimed, true) && ! in_array($token['word'], self::STOPWORDS, true)) {
                $run[] = $token['text'];
            }
        }

        if ($run !== []) {
            $phrases[] = implode(' ', $run);
        }

        return array_values(array_unique($phrases));
    }

    /**
     * Words in order, each normalized for matching, with its original text and
     * whether punctuation separates it from the previous word.
     *
     * @return array<int, array{word: string, text: string, breakBefore: bool}>
     */
    private function tokens(string $query): array
    {
        preg_match_all("/[\\p{L}\\p{N}'’]+/u", $query, $matches, PREG_OFFSET_CAPTURE);

        $tokens = [];
        $previousEnd = 0;

        foreach ($matches[0] as [$text, $offset]) {
            $word = $this->normalize($text);

            if ($word === '') {
                $previousEnd = $offset + strlen($text);

                continue;
            }

            $tokens[] = [
                'word' => $word,
                'text' => trim($text, "'’"),
                'breakBefore' => $tokens !== [] && preg_match(self::BREAK, substr($query, $previousEnd, $offset - $previousEnd)) === 1,
            ];
            $previousEnd = $offset + strlen($text);
        }

        return $tokens;
    }

    /**
     * Every genre and tag name, keyed by its normalized words ("slice of life",
     * "sci fi", "boys love").
     *
     * @return array<string, array{genre?: array{id: int, name: string}, tag?: array{id: int, name: string}}>
     */
    private function vocabulary(): array
    {
        if ($this->vocabulary !== null) {
            return $this->vocabulary;
        }

        $vocabulary = [];

        foreach (['genre' => 'genres', 'tag' => 'tags'] as $kind => $table) {
            foreach (DB::table($table)->get(['id', 'name']) as $row) {
                $key = $this->key($row->name);

                if ($key !== '') {
                    $vocabulary[$key][$kind] = ['id' => (int) $row->id, 'name' => $row->name];
                }
            }
        }

        return $this->vocabulary = $vocabulary;
    }

    /** A name's matching key: its normalized words, space-separated. */
    private function key(string $name): string
    {
        return implode(' ', array_filter(array_map(
            fn (string $part) => $this->normalize($part),
            preg_split('/[^\p{L}\p{N}\'’]+/u', $name) ?: [],
        )));
    }

    private function normalize(string $word): string
    {
        return mb_strtolower(str_replace(["'", '’'], '', $word));
    }
}
