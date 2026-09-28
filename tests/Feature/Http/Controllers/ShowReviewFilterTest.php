<?php

declare(strict_types=1);

use App\Models\Show;
use App\Models\ShowAnimeSuggestion;
use Inertia\Testing\AssertableInertia;

function suggestFor(Show $show): void
{
    ShowAnimeSuggestion::create([
        'show_id' => $show->id,
        'anime_id' => metadataAnime()->id,
        'score' => 95,
        'rule' => 'subtitle_prefix',
        'created_at' => now(),
    ]);
}

test('review=1 lists only shows with pending suggestions, and reports the filter', function () {
    $pendingA = metadataShow('A Pending');
    $pendingB = metadataShow('B Pending');
    metadataShow('C Quiet');
    suggestFor($pendingA);
    suggestFor($pendingA);
    suggestFor($pendingB);

    $this->get('/shows?review=1')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('shows.data', 2)
        ->where('shows.data.0.id', $pendingA->id)
        ->where('shows.data.1.id', $pendingB->id)
        ->where('filters.review', true)
        ->where('filterOptions.reviewCount', 2));
});

test('without review, every show is listed and the filter reads false', function () {
    suggestFor(metadataShow('A Pending'));
    metadataShow('B Quiet');

    $this->get('/shows')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('shows.data', 2)
        ->where('filters.review', false)
        ->where('filterOptions.reviewCount', 1));

    $this->get('/shows?review=0')->assertInertia(fn (AssertableInertia $page) => $page->has('shows.data', 2));
});

test('review combines with the other filters', function () {
    $tracked = metadataShow('Tracked Pending', ['is_tracked' => true, 'season' => 'summer', 'season_year' => 2026]);
    $untracked = metadataShow('Untracked Pending', ['season' => 'summer', 'season_year' => 2026]);
    metadataShow('Tracked Quiet', ['is_tracked' => true, 'season' => 'summer', 'season_year' => 2026]);
    $other = metadataShow('Pending Elsewhere', ['is_tracked' => true, 'season' => 'spring', 'season_year' => 2026]);
    foreach ([$tracked, $untracked, $other] as $show) {
        suggestFor($show);
    }

    $ids = fn (string $query) => collect($this->get('/shows?'.$query)->viewData('page')['props']['shows']['data'])->pluck('id')->all();

    expect($ids('review=1&tracked=yes&season=summer&year=2026'))->toBe([$tracked->id])
        ->and($ids('review=1&tracked=no'))->toBe([$untracked->id])
        ->and($ids('review=1&q=Elsewhere'))->toBe([$other->id])
        // The paginator keeps the filter in its links.
        ->and($this->get('/shows?review=1')->viewData('page')['props']['shows']['links']['first'])->toContain('review=1');
});

test('the review count ignores the other filters', function () {
    suggestFor(metadataShow('Tracked Pending', ['is_tracked' => true]));
    suggestFor(metadataShow('Untracked Pending'));

    $this->get('/shows?tracked=yes')->assertInertia(fn (AssertableInertia $page) => $page->where('filterOptions.reviewCount', 2));
});
