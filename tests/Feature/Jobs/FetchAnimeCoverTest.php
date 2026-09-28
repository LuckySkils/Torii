<?php

declare(strict_types=1);

use App\Jobs\FetchAnimeCover;
use App\Models\AnimeImage;
use App\Models\ShowAnimeLink;
use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-09-28 12:00:00');
});

function coverBytes(): string
{
    return file_get_contents(dirname(__DIR__, 2).'/Fixtures/subsplease_poster.jpg');
}

const TEST_COVER_URL = 'https://s4.anilist.co/file/anilistcdn/media/anime/cover/large/bx195516-MJpUZlOberqH.jpg';

test('downloads and stores the cover, base64 with its hash and size', function () {
    Http::fake(['s4.anilist.co/*' => Http::response(coverBytes(), 200, ['Content-Type' => 'image/jpeg'])]);
    $anime = metadataAnime(['cover_url' => TEST_COVER_URL]);

    (new FetchAnimeCover($anime->id))->handle();

    $image = AnimeImage::where('anime_id', $anime->id)->sole();

    expect(base64_decode($image->data))->toBe(coverBytes())
        ->and($image->sha256)->toBe(hash('sha256', coverBytes()))
        ->and($image->size)->toBe(strlen(coverBytes()))
        ->and($image->width)->toBe(225)
        ->and($image->height)->toBe(317)
        ->and($image->mime)->toBe('image/jpeg')
        ->and($image->source_url)->toBe(TEST_COVER_URL)
        ->and($image->url())->toBe("/anime/{$anime->id}/cover?v=".substr(hash('sha256', coverBytes()), 0, 8));
});

test('an unchanged cover is not rewritten', function () {
    Http::fake(['s4.anilist.co/*' => Http::response(coverBytes(), 200, ['Content-Type' => 'image/jpeg'])]);
    $anime = metadataAnime(['cover_url' => TEST_COVER_URL]);

    (new FetchAnimeCover($anime->id))->handle();
    $fetchedAt = AnimeImage::where('anime_id', $anime->id)->value('fetched_at');

    $this->travel(1)->days();
    (new FetchAnimeCover($anime->id))->handle();

    expect(AnimeImage::where('anime_id', $anime->id)->value('fetched_at'))->toEqual($fetchedAt);
});

test('a non-image, oversized, undecodable or 404 response stores nothing and is not retried', function (int $status, string $body, string $type) {
    Http::fake(['s4.anilist.co/*' => Http::response($body, $status, ['Content-Type' => $type])]);
    $anime = metadataAnime(['cover_url' => TEST_COVER_URL]);

    (new FetchAnimeCover($anime->id))->handle();

    expect(AnimeImage::count())->toBe(0);
})->with([
    'html' => [200, '<html>nope</html>', 'text/html'],
    'too big' => [200, str_repeat('a', 5 * 1024 * 1024 + 1), 'image/jpeg'],
    'not an image' => [200, 'definitely not a jpeg', 'image/jpeg'],
    'not found' => [404, '', 'text/html'],
]);

test('a server error throws so the job retries', function () {
    Http::fake(['s4.anilist.co/*' => Http::response('', 503)]);
    $anime = metadataAnime(['cover_url' => TEST_COVER_URL]);

    expect(fn () => (new FetchAnimeCover($anime->id))->handle())->toThrow(RequestException::class);
});

test('an anime with no cover URL is skipped without a request', function () {
    $anime = metadataAnime(['cover_url' => null]);

    (new FetchAnimeCover($anime->id))->handle();

    Http::assertNothingSent();
});

test('anime:fetch-images covers current, next and linked anime; --missing skips stored ones', function () {
    Queue::fake();

    $current = metadataAnime(['cover_url' => TEST_COVER_URL, 'season' => 'SUMMER', 'season_year' => 2026]);
    $next = metadataAnime(['cover_url' => TEST_COVER_URL, 'season' => 'FALL', 'season_year' => 2026]);
    $old = metadataAnime(['cover_url' => TEST_COVER_URL, 'season' => 'FALL', 'season_year' => 2019]);
    $oldLinked = metadataAnime(['cover_url' => TEST_COVER_URL, 'season' => 'FALL', 'season_year' => 1999]);
    ShowAnimeLink::create(['show_id' => metadataShow('One Piece')->id, 'anime_id' => $oldLinked->id, 'confidence' => 100, 'source' => 'auto', 'linked_at' => now()]);
    metadataAnime(['cover_url' => null, 'season' => 'SUMMER', 'season_year' => 2026]);

    AnimeImage::create(['anime_id' => $current->id, 'source_url' => TEST_COVER_URL, 'mime' => 'image/jpeg', 'data' => '', 'size' => 0, 'sha256' => str_repeat('0', 64), 'fetched_at' => now()]);

    $this->artisan('anime:fetch-images')->expectsOutput('Dispatched cover fetches for 3 anime.')->assertSuccessful();
    $this->artisan('anime:fetch-images --missing')->expectsOutput('Dispatched cover fetches for 2 anime.')->assertSuccessful();

    Queue::assertPushed(FetchAnimeCover::class, fn (FetchAnimeCover $job) => $job->animeId === $next->id);
    Queue::assertPushed(FetchAnimeCover::class, fn (FetchAnimeCover $job) => $job->animeId === $oldLinked->id);
    Queue::assertNotPushed(FetchAnimeCover::class, fn (FetchAnimeCover $job) => $job->animeId === $old->id);
});
