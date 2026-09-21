<?php

declare(strict_types=1);

use App\Services\SubsPlease\SubsPleaseApiClient;
use App\Services\SubsPlease\SubsPleaseApiException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

function subsPleaseFixture(string $name): string
{
    return file_get_contents(dirname(__DIR__, 3).'/Fixtures/'.$name);
}

test('parses a real search response and resolves relative image URLs to absolute ones', function () {
    Http::fake([
        'subsplease.org/api/*' => Http::response(subsPleaseFixture('subsplease_search_digimon-beatbreak.json'), 200, ['Content-Type' => 'application/json']),
    ]);

    $items = (new SubsPleaseApiClient)->search('Digimon Beatbreak');

    expect($items)->not->toBeEmpty();

    foreach ($items as $item) {
        expect($item->show)->toBe('Digimon Beatbreak')
            ->and($item->imageUrl)->toBe('https://subsplease.org/wp-content/uploads/2025/10/151242.jpg')
            ->and($item->page)->toBe('digimon-beatbreak')
            ->and($item->downloads)->not->toBeEmpty()
            ->and($item->downloads[0])->toHaveKeys(['res', 'magnet']);
    }
});

test('leaves an already-absolute image URL untouched', function () {
    Http::fake([
        'subsplease.org/api/*' => Http::response(json_encode([
            'Show - 01' => [
                'show' => 'Show',
                'episode' => '01',
                'release_date' => 'Mon, 21 Sep 2026 08:00:00 +0000',
                'time' => '09/21/26',
                'page' => 'show',
                'image_url' => 'https://cdn.example.test/show.jpg',
                'downloads' => [],
            ],
        ]), 200, ['Content-Type' => 'application/json']),
    ]);

    $items = (new SubsPleaseApiClient)->search('Show');

    expect($items[0]->imageUrl)->toBe('https://cdn.example.test/show.jpg');
});

test('an empty array result gives no items', function () {
    Http::fake([
        'subsplease.org/api/*' => Http::response(subsPleaseFixture('subsplease_search_no_results.json'), 200, ['Content-Type' => 'application/json']),
    ]);

    expect((new SubsPleaseApiClient)->search('ZzzzNoSuchShowAtAllXyz'))->toBe([]);
});

test('an empty object result also gives no items', function () {
    Http::fake([
        'subsplease.org/api/*' => Http::response('{}', 200, ['Content-Type' => 'application/json']),
    ]);

    expect((new SubsPleaseApiClient)->search('Nothing'))->toBe([]);
});

test('parses a genuinely-JSON body even when SubsPlease mislabels it as text/html', function () {
    Http::fake([
        'subsplease.org/api/*' => Http::response(subsPleaseFixture('subsplease_search_digimon-beatbreak.json'), 200, ['Content-Type' => 'text/html; charset=UTF-8']),
    ]);

    $items = (new SubsPleaseApiClient)->search('Digimon Beatbreak');

    expect($items)->not->toBeEmpty()
        ->and($items[0]->show)->toBe('Digimon Beatbreak');
});

test('a Cloudflare HTML body where JSON was expected throws, never a "no image" result', function () {
    Http::fake([
        'subsplease.org/api/*' => Http::response('<html><body>Just a moment...</body></html>', 200, ['Content-Type' => 'text/html']),
    ]);

    expect(fn () => (new SubsPleaseApiClient)->search('Show'))->toThrow(SubsPleaseApiException::class);
});

test('a 403 response throws', function () {
    Http::fake([
        'subsplease.org/api/*' => Http::response('Forbidden', 403),
    ]);

    expect(fn () => (new SubsPleaseApiClient)->search('Show'))->toThrow(SubsPleaseApiException::class);
});

test('a 503 response throws', function () {
    Http::fake([
        'subsplease.org/api/*' => Http::response('Service Unavailable', 503),
    ]);

    expect(fn () => (new SubsPleaseApiClient)->search('Show'))->toThrow(SubsPleaseApiException::class);
});
