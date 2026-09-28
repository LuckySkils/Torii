<?php

use App\Models\Anime;
use App\Models\Show;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
