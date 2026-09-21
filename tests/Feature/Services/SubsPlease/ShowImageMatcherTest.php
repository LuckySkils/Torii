<?php

declare(strict_types=1);

use App\Services\SubsPlease\SearchResultItem;
use App\Services\SubsPlease\ShowImageMatcher;

function searchResult(string $show, ?string $imageUrl): SearchResultItem
{
    return new SearchResultItem(
        show: $show,
        episode: '01',
        releaseDate: null,
        time: null,
        page: null,
        imageUrl: $imageUrl,
        downloads: [],
    );
}

test('matches a show whose name is exactly equal', function () {
    $items = [
        searchResult('Other Show', 'https://x.test/other.jpg'),
        searchResult('Grand Blue S3', 'https://x.test/gb.jpg'),
    ];

    $match = (new ShowImageMatcher)->match($items, 'Grand Blue S3');

    expect($match)->not->toBeNull()
        ->and($match->show)->toBe('Grand Blue S3');
});

test('falls back to a case-insensitive, trimmed match when no exact match exists', function () {
    $items = [
        searchResult(' grand blue s3 ', 'https://x.test/gb.jpg'),
    ];

    $match = (new ShowImageMatcher)->match($items, 'Grand Blue S3');

    expect($match)->not->toBeNull()
        ->and($match->show)->toBe(' grand blue s3 ');
});

test('returns null when nothing matches', function () {
    $items = [
        searchResult('Completely Different Show', 'https://x.test/x.jpg'),
    ];

    expect((new ShowImageMatcher)->match($items, 'Grand Blue S3'))->toBeNull();
});

test('skips exact matches with no image and does not fall back to a case-insensitive match', function () {
    $items = [
        searchResult('Grand Blue S3', null),
        searchResult('grand blue s3', 'https://x.test/gb.jpg'),
    ];

    expect((new ShowImageMatcher)->match($items, 'Grand Blue S3'))->toBeNull();
});

test('uses the first exact match that has a non-empty image URL', function () {
    $items = [
        searchResult('Grand Blue S3', null),
        searchResult('Grand Blue S3', 'https://x.test/gb-2.jpg'),
    ];

    $match = (new ShowImageMatcher)->match($items, 'Grand Blue S3');

    expect($match->imageUrl)->toBe('https://x.test/gb-2.jpg');
});
