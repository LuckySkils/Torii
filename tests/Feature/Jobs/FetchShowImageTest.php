<?php

declare(strict_types=1);

use App\Enums\ImageStatus;
use App\Enums\PremiereSource;
use App\Jobs\FetchShowImage;
use App\Models\Release;
use App\Models\Show;
use App\Models\ShowImage;
use App\Services\Premiere\PremiereCalculator;
use App\Services\SubsPlease\ShowImageMatcher;
use App\Services\SubsPlease\SubsPleaseApiClient;
use App\Services\SubsPlease\SubsPleaseApiException;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Http::preventStrayRequests();
});

function imageJobShow(string $name = 'Digimon Beatbreak'): Show
{
    return Show::create([
        'name' => $name,
        'slug' => str($name)->slug(),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
}

function posterBytes(): string
{
    return file_get_contents(dirname(__DIR__, 2).'/Fixtures/subsplease_poster.jpg');
}

function fakeSearchAndImage(): void
{
    Http::fake([
        'subsplease.org/api/*' => Http::response(
            file_get_contents(dirname(__DIR__, 2).'/Fixtures/subsplease_search_digimon-beatbreak.json'),
            200,
            ['Content-Type' => 'application/json'],
        ),
        'subsplease.org/wp-content/*' => Http::response(posterBytes(), 200, ['Content-Type' => 'image/jpeg']),
    ]);
}

test('a matched show downloads and stores the image, and marks found', function () {
    fakeSearchAndImage();

    $show = imageJobShow();

    (new FetchShowImage($show->id))->handle(new SubsPleaseApiClient, new ShowImageMatcher, new PremiereCalculator);

    $show->refresh();
    $image = ShowImage::where('show_id', $show->id)->first();

    expect($show->image_status)->toBe(ImageStatus::Found)
        ->and($show->image_checked_at)->not->toBeNull()
        ->and($show->image_error)->toBeNull()
        ->and($image)->not->toBeNull()
        ->and(base64_decode($image->data))->toBe(posterBytes())
        ->and($image->sha256)->toBe(hash('sha256', posterBytes()))
        ->and($image->size)->toBe(strlen(posterBytes()))
        ->and($image->width)->toBe(225)
        ->and($image->height)->toBe(317)
        ->and($image->mime)->toBe('image/jpeg');
});

test('a successful fetch also computes and stores the subsplease-sourced premiere date', function () {
    fakeSearchAndImage();

    $show = imageJobShow();

    (new FetchShowImage($show->id))->handle(new SubsPleaseApiClient, new ShowImageMatcher, new PremiereCalculator);

    $show->refresh();

    $expectedEarliest = collect(json_decode(file_get_contents(dirname(__DIR__, 2).'/Fixtures/subsplease_search_digimon-beatbreak.json'), true))
        ->pluck('release_date')
        ->map(fn (string $date) => Carbon::parse($date)->utc())
        ->sort()
        ->first();

    expect($show->premiere_source)->toBe(PremiereSource::SubsPlease)
        ->and($show->premiered_at->equalTo($expectedEarliest))->toBeTrue();
});

test('logs the API release_date minus stored published_at for releases it also has, without extra requests', function () {
    fakeSearchAndImage();
    Log::spy();

    $show = imageJobShow();
    $release = fn (string $guid, array $attributes) => Release::create(array_merge([
        'show_id' => $show->id,
        'guid' => $guid,
        'title' => $guid,
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:'.$guid,
        'first_seen_at' => '2026-09-21 00:00:00',
    ], $attributes));

    // The fixture's API release_date for ep 48 is 03:02:01 and for ep 28v2 10:17:29.
    $release('EP48', ['episode' => '48', 'published_at' => '2026-09-20 03:02:01']);
    $release('EP28V2', ['episode' => '28', 'version' => 2, 'published_at' => '2026-04-26 03:17:29']);
    $release('EP28', ['episode' => '28', 'published_at' => '2026-04-19 00:00:00']);

    (new FetchShowImage($show->id))->handle(new SubsPleaseApiClient, new ShowImageMatcher, new PremiereCalculator);

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => str_contains($message, 'release_date minus stored published_at')
        && $context['show'] === 'Digimon Beatbreak'
        && $context['diff_minutes'] === ['48' => 0, '28v2' => 420]);
    Http::assertSentCount(2); // the search and the poster, nothing more
});

test('a re-fetch with the same image bytes updates the check time but does not rewrite the row', function () {
    fakeSearchAndImage();

    $show = imageJobShow();

    (new FetchShowImage($show->id))->handle(new SubsPleaseApiClient, new ShowImageMatcher, new PremiereCalculator);
    $firstImage = ShowImage::where('show_id', $show->id)->first();

    (new FetchShowImage($show->id))->handle(new SubsPleaseApiClient, new ShowImageMatcher, new PremiereCalculator);
    $secondImage = ShowImage::where('show_id', $show->id)->first();

    expect(ShowImage::count())->toBe(1)
        ->and($secondImage->id)->toBe($firstImage->id)
        ->and($secondImage->updated_at->equalTo($firstImage->updated_at))->toBeTrue()
        ->and($show->refresh()->image_status)->toBe(ImageStatus::Found);
});

test('no match marks the show missing and keeps any existing stored image', function () {
    Http::fake([
        'subsplease.org/api/*' => Http::response(
            file_get_contents(dirname(__DIR__, 2).'/Fixtures/subsplease_search_no_results.json'),
            200,
            ['Content-Type' => 'application/json'],
        ),
    ]);

    $show = imageJobShow('No Such Show');

    $existing = ShowImage::create([
        'show_id' => $show->id,
        'source_url' => 'https://subsplease.org/old.jpg',
        'mime' => 'image/jpeg',
        'data' => base64_encode('old bytes'),
        'size' => 9,
        'width' => 1,
        'height' => 1,
        'sha256' => hash('sha256', 'old bytes'),
        'fetched_at' => now()->subDay(),
    ]);

    (new FetchShowImage($show->id))->handle(new SubsPleaseApiClient, new ShowImageMatcher, new PremiereCalculator);

    $show->refresh();
    $stillThere = ShowImage::find($existing->id);

    expect($show->image_status)->toBe(ImageStatus::Missing)
        ->and($show->image_checked_at)->not->toBeNull()
        ->and($stillThere)->not->toBeNull()
        ->and($stillThere->sha256)->toBe($existing->sha256);
});

test('propagates the failure so the queue retries when the search API is unreachable', function () {
    Http::fake(function () {
        throw new ConnectionException('Connection refused');
    });

    $show = imageJobShow();

    (new FetchShowImage($show->id))->handle(new SubsPleaseApiClient, new ShowImageMatcher, new PremiereCalculator);
})->throws(ConnectionException::class);

test('a repeated Cloudflare failure propagates too', function () {
    Http::fake([
        'subsplease.org/api/*' => Http::response('<html>challenge</html>', 200, ['Content-Type' => 'text/html']),
    ]);

    $show = imageJobShow();

    (new FetchShowImage($show->id))->handle(new SubsPleaseApiClient, new ShowImageMatcher, new PremiereCalculator);
})->throws(SubsPleaseApiException::class);

test('failed() marks the show as error with the final exception message', function () {
    $show = imageJobShow();

    (new FetchShowImage($show->id))->failed(new RuntimeException('SubsPlease is down.'));

    $show->refresh();

    expect($show->image_status)->toBe(ImageStatus::Error)
        ->and($show->image_error)->toBe('SubsPlease is down.')
        ->and($show->image_checked_at)->not->toBeNull();
});

test('a non-image content type is rejected without creating a stored image', function () {
    Http::fake([
        'subsplease.org/api/*' => Http::response(
            file_get_contents(dirname(__DIR__, 2).'/Fixtures/subsplease_search_digimon-beatbreak.json'),
            200,
            ['Content-Type' => 'application/json'],
        ),
        'subsplease.org/wp-content/*' => Http::response('<html>not an image</html>', 200, ['Content-Type' => 'text/html']),
    ]);

    $show = imageJobShow();

    (new FetchShowImage($show->id))->handle(new SubsPleaseApiClient, new ShowImageMatcher, new PremiereCalculator);

    $show->refresh();

    expect($show->image_status)->toBe(ImageStatus::Error)
        ->and($show->image_error)->not->toBeNull()
        ->and(ShowImage::where('show_id', $show->id)->exists())->toBeFalse();
});

test('an image over 5 MB is rejected without creating a stored image', function () {
    Http::fake([
        'subsplease.org/api/*' => Http::response(
            file_get_contents(dirname(__DIR__, 2).'/Fixtures/subsplease_search_digimon-beatbreak.json'),
            200,
            ['Content-Type' => 'application/json'],
        ),
        'subsplease.org/wp-content/*' => Http::response(str_repeat('a', 6 * 1024 * 1024), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $show = imageJobShow();

    (new FetchShowImage($show->id))->handle(new SubsPleaseApiClient, new ShowImageMatcher, new PremiereCalculator);

    $show->refresh();

    expect($show->image_status)->toBe(ImageStatus::Error)
        ->and($show->image_error)->not->toBeNull()
        ->and(ShowImage::where('show_id', $show->id)->exists())->toBeFalse();
});
