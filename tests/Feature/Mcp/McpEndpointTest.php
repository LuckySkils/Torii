<?php

declare(strict_types=1);

use App\Mcp\Listener;
use App\Mcp\Servers\ToriiServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\Transport\HttpTransport;

function mcpOnListener(string $listener): void
{
    app()->instance(Listener::class, new class($listener) extends Listener
    {
        public function __construct(private readonly string $listener) {}

        public function current(): string
        {
            return $this->listener;
        }
    });
}

/**
 * @return array<int, string>
 */
function mcpToolNames(): array
{
    return array_column(mcpRequest('tools/list')->assertOk()->json('result.tools'), 'name');
}

const MCP_READ_TOOLS = ['search_anime', 'get_anime', 'list_schedule', 'list_shows', 'get_show', 'tracked_summary', 'suggest_link', 'suggest_anime', 'list_tags'];
const MCP_WRITE_TOOLS = ['track_show', 'untrack_show', 'queue_missing', 'download_release'];

beforeEach(function () {
    $this->withoutVite();
});

// ── Protocol ─────────────────────────────────────────────────────────────────

test('legacy clients: initialize negotiates 2025-11-25, then tools/call works over the route', function () {
    $init = mcpRequest('initialize', [
        'protocolVersion' => '2025-11-25',
        'capabilities' => new stdClass,
        'clientInfo' => ['name' => 'test', 'version' => '1'],
    ])->assertOk();

    expect($init->json('result.protocolVersion'))->toBe('2025-11-25')
        ->and($init->json('result.serverInfo.name'))->toBe('Torii')
        ->and($init->json('result.capabilities'))->toHaveKeys(['tools', 'resources']);

    metadataAnime(['title_romaji' => 'Kusuriya no Hitorigoto']);

    expect(mcpTool('search_anime', ['query' => 'kusuriya'])['results'])->toHaveCount(1);
});

test('an older initialize version gets the newest one Torii speaks before the stateless era', function () {
    expect(mcpRequest('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass, 'clientInfo' => ['name' => 't', 'version' => '1']])
        ->json('result.protocolVersion'))->toBe('2025-06-18');
});

test('2026-07-28 clients: stateless requests with _meta and matching headers', function () {
    $discover = mcpRequest('server/discover', modern: true)->assertOk();
    expect($discover->json('result.supportedVersions'))->toContain('2026-07-28');

    $list = mcpRequest('tools/list', modern: true)->assertOk();
    expect(array_column($list->json('result.tools'), 'name'))->toEqualCanonicalizing(MCP_READ_TOOLS);

    metadataAnime(['title_romaji' => 'Frieren']);
    $call = mcpRequest('tools/call', ['name' => 'search_anime', 'arguments' => ['query' => 'frieren']], modern: true)->assertOk();
    expect(json_decode($call->json('result.content.0.text'), true)['total'])->toBe(1);
});

test('a modern request whose headers disagree with the body is rejected', function () {
    mcpRequest('tools/list', headers: ['Mcp-Method' => 'tools/call'], modern: true)
        ->assertStatus(400)
        ->assertJsonPath('error.code', -32020);
});

test('GET and DELETE on /mcp are 405', function () {
    $this->get('/mcp')->assertStatus(405);
    $this->delete('/mcp')->assertStatus(405);
});

// ── Enabled / writes ─────────────────────────────────────────────────────────

test('with MCP disabled the route does not exist', function () {
    $set = function (string $value): void {
        putenv("MCP_ENABLED={$value}");
        $_ENV['MCP_ENABLED'] = $_SERVER['MCP_ENABLED'] = $value;
    };

    $set('false');

    try {
        $this->refreshApplication();
        $this->withoutVite();

        expect(config('subtracker.mcp.enabled'))->toBeFalse()
            ->and(Route::has('mcp'))->toBeFalse();
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertNotFound();
        $this->get('/mcp')->assertNotFound();
    } finally {
        $set('true');
    }
});

test('write tools are absent from tools/list, and uncallable, unless MCP_ALLOW_WRITES', function () {
    config(['subtracker.mcp.allow_writes' => false]);
    expect(mcpToolNames())->toEqualCanonicalizing(MCP_READ_TOOLS);

    mcpRequest('tools/call', ['name' => 'track_show', 'arguments' => ['show_id' => 1]])
        ->assertStatus(400)
        ->assertJsonPath('error.message', 'Tool [track_show] not found.');

    config(['subtracker.mcp.allow_writes' => true]);
    expect(mcpToolNames())->toEqualCanonicalizing([...MCP_READ_TOOLS, ...MCP_WRITE_TOOLS]);
});

test('every list is one page of up to 50: tools/list never needs a cursor', function () {
    config(['subtracker.mcp.allow_writes' => true]);

    $result = mcpRequest('tools/list')->assertOk()->json('result');
    $context = (new ToriiServer(new HttpTransport(request())))->createContext();

    expect($result['tools'])->toHaveCount(count(MCP_READ_TOOLS) + count(MCP_WRITE_TOOLS))
        ->and($result)->not->toHaveKey('nextCursor')
        ->and($context->perPage())->toBe(50)
        ->and($context->perPage(500))->toBe(50);
});

test('read tools are annotated read-only, write tools are not', function () {
    config(['subtracker.mcp.allow_writes' => true]);
    $tools = collect(mcpRequest('tools/list')->json('result.tools'))->keyBy('name');

    foreach (MCP_READ_TOOLS as $name) {
        expect($tools[$name]['annotations']['readOnlyHint'] ?? null)->toBeTrue();
    }

    foreach (MCP_WRITE_TOOLS as $name) {
        expect($tools[$name]['annotations']['readOnlyHint'])->toBeFalse()
            ->and($tools[$name]['annotations']['destructiveHint'])->toBeFalse()
            ->and($tools[$name]['description'])->toStartWith('CHANGES STATE');
    }
});

// ── Token and origin ─────────────────────────────────────────────────────────

test('without MCP_TOKEN no Authorization is needed', function () {
    config(['subtracker.mcp.token' => '']);

    mcpRequest('tools/list')->assertOk();
});

test('with MCP_TOKEN, a request without it or with a wrong one is 401; the right one passes', function () {
    config(['subtracker.mcp.token' => 'correct-horse-battery-staple']);

    $response = mcpRequest('tools/list')->assertUnauthorized()->assertJsonPath('error.code', -32001);
    expect($response->headers->get('WWW-Authenticate'))->toStartWith('Bearer realm="mcp"');
    mcpRequest('tools/list', headers: ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
    mcpRequest('tools/list', headers: ['Authorization' => 'Bearer correct-horse-battery-stapl'])->assertUnauthorized();
    mcpRequest('tools/list', headers: ['Authorization' => 'Basic correct-horse-battery-staple'])->assertUnauthorized();

    mcpRequest('tools/list', headers: ['Authorization' => 'Bearer correct-horse-battery-staple'])->assertOk();
});

test('a browser request from a foreign Origin is refused; Torii\'s own origin and no Origin pass', function () {
    config(['app.url' => 'http://torii.lan:8080']);

    mcpRequest('tools/list', headers: ['Origin' => 'https://evil.example'])->assertForbidden();
    mcpRequest('tools/list', headers: ['Origin' => 'http://torii.lan'])->assertForbidden();
    mcpRequest('tools/list', headers: ['Origin' => 'http://torii.lan:8080'])->assertOk();
    mcpRequest('tools/list')->assertOk();
});

test('the endpoint is rate limited per client', function () {
    config(['subtracker.mcp.rate_limit_per_minute' => 2]);

    mcpRequest('tools/list')->assertOk();
    mcpRequest('tools/list')->assertOk();
    mcpRequest('tools/list')->assertTooManyRequests();
});

// ── Port isolation ───────────────────────────────────────────────────────────

test('without MCP_PORT, MCP and the UI share the main port', function () {
    config(['subtracker.mcp.port' => null]);
    mcpOnListener(Listener::MAIN);

    mcpRequest('tools/list')->assertOk();
    $this->get('/up')->assertOk();
});

test('with MCP_PORT set, /mcp is a 404 on the main listener while the UI works', function () {
    config(['subtracker.mcp.port' => 7099]);
    mcpOnListener(Listener::MAIN);

    mcpRequest('tools/list')->assertNotFound();
    $this->get('/mcp')->assertNotFound();
    $this->get('/up')->assertOk();
    $this->get('/')->assertOk();
});

test('with MCP_PORT set, the MCP listener serves only /mcp', function () {
    config(['subtracker.mcp.port' => 7099]);
    mcpOnListener(Listener::MCP);

    mcpRequest('tools/list')->assertOk();

    foreach (['/', '/up', '/schedule', '/shows', '/build/manifest.json', '/favicon.ico', '/anime/1/card', '/mcp/extra'] as $path) {
        $this->get($path)->assertNotFound();
    }
    $this->postJson('/shows/1/track', ['tracked' => true])->assertNotFound();
});

test('the listener comes from the process, not from anything the client sends', function () {
    config(['subtracker.mcp.port' => 7099]);
    mcpOnListener(Listener::MAIN);

    mcpRequest('tools/list', headers: ['Host' => 'localhost:7099', 'X-Forwarded-Port' => '7099', 'Torii-Listener' => 'mcp'])->assertNotFound();

    $_SERVER['HTTP_TORII_LISTENER'] = 'mcp';

    try {
        expect((new Listener)->current())->toBe(Listener::MAIN);
    } finally {
        unset($_SERVER['HTTP_TORII_LISTENER']);
    }
});

test('Listener reads TORII_LISTENER from the server environment', function () {
    expect((new Listener)->current())->toBe(Listener::MAIN);

    $_SERVER['TORII_LISTENER'] = 'mcp';

    try {
        expect((new Listener)->current())->toBe(Listener::MCP);
    } finally {
        unset($_SERVER['TORII_LISTENER']);
    }
});
