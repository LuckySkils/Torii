<?php

use App\Models\Anime;
use App\Models\Show;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * A real AniList response from tests/Fixtures/anilist/, decoded.
 *
 * @return array<string, mixed>
 */
function anilistFixture(string $name): array
{
    return json_decode(file_get_contents(__DIR__.'/Fixtures/anilist/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * One media entry from a real AniList season page in tests/Fixtures/anilist/.
 *
 * @return array<string, mixed>
 */
function anilistMedia(string $fixture, int $id): array
{
    foreach (anilistFixture($fixture)['data']['Page']['media'] as $media) {
        if ($media['id'] === $id) {
            return $media;
        }
    }

    throw new RuntimeException("No media {$id} in {$fixture}.");
}

/**
 * A stored anime with its AniList id, for metadata tests.
 *
 * @param  array<string, mixed>  $attributes
 */
function metadataAnime(array $attributes = [], ?string $anilistId = null): Anime
{
    $anime = Anime::create([
        'title_romaji' => 'Some Anime',
        'primary_provider' => 'anilist',
        'synced_at' => now(),
        ...$attributes,
    ]);

    $anime->externalIds()->create(['provider' => 'anilist', 'external_id' => $anilistId ?? (string) (100000 + $anime->id)]);

    return $anime;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function metadataShow(string $name, array $attributes = []): Show
{
    return Show::create([
        'name' => $name,
        'slug' => str($name)->slug(),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        ...$attributes,
    ]);
}

/**
 * One JSON-RPC request to the real /mcp route. Legacy-era (no _meta) unless
 * $modern, which adds the 2026-07-28 _meta and the matching headers.
 *
 * @param  array<string, mixed>  $params
 * @param  array<string, string>  $headers
 */
function mcpRequest(string $method, array $params = [], array $headers = [], bool $modern = false): TestResponse
{
    if ($modern) {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => new stdClass,
        ];
        $headers = ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => $method, ...$headers];

        if (isset($params['name'])) {
            $headers['Mcp-Name'] ??= $params['name'];
        }
    }

    return test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => $params === [] ? new stdClass : $params,
    ], ['Accept' => 'application/json, text/event-stream', ...$headers]);
}

/**
 * Calls a tool over /mcp and returns its decoded JSON output; fails on a tool error.
 *
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function mcpTool(string $name, array $arguments = []): array
{
    $result = mcpRequest('tools/call', ['name' => $name, 'arguments' => $arguments === [] ? new stdClass : $arguments])
        ->assertOk()
        ->json('result');

    expect($result['isError'])->toBeFalse("{$name} returned an error: ".($result['content'][0]['text'] ?? ''));

    return json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Calls a tool over /mcp expecting a tool error; returns its message.
 *
 * @param  array<string, mixed>  $arguments
 */
function mcpToolError(string $name, array $arguments = []): string
{
    $result = mcpRequest('tools/call', ['name' => $name, 'arguments' => $arguments === [] ? new stdClass : $arguments])
        ->assertOk()
        ->json('result');

    expect($result['isError'])->toBeTrue();

    return $result['content'][0]['text'];
}

// ── Delivery reconciler (§17): the handoff's raw captures in tests/Fixtures/reconciler/ ──

const ANIME_LIBRARY = '0c419071-40d8-02bb-5843-0fed7e2cd79e';

/**
 * The handoff's Shoko capture: one `time target [arguments]` line per message.
 *
 * @return array<int, array{target: string, arguments: array<int, mixed>}>
 */
function shokoCapture(string $fixture = 'psyren_shoko_signalr.txt'): array
{
    $lines = preg_split('/\R/', trim(file_get_contents(base_path("tests/Fixtures/reconciler/{$fixture}"))));

    return array_map(function (string $line): array {
        preg_match('/^(?:\S+\s+)?(\S+)\s+(\[.*\])$/', $line, $match);

        return ['target' => $match[1], 'arguments' => json_decode($match[2], true, flags: JSON_THROW_ON_ERROR)];
    }, $lines);
}

/**
 * @return array<int, array<string, mixed>>
 */
function jellyfinCapture(): array
{
    return array_map(fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), preg_split('/\R/', trim(file_get_contents(base_path('tests/Fixtures/reconciler/jellyfin_socket.jsonl')))));
}
