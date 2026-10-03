<?php

declare(strict_types=1);

use App\Contracts\MetadataProvider;
use App\Jobs\SyncAnimeVocabulary;
use App\Models\Anime;
use App\Services\Metadata\AnimeTaxonomyWriter;
use App\Services\Metadata\MetadataProviderException;
use App\Services\Metadata\ProviderTag;
use App\Services\Metadata\ProviderTagDefinition;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-10-03 12:00:00');
    Sleep::fake(syncWithCarbon: true);
});

/**
 * A real `{ GenreCollection MediaTagCollection { name description category isAdult } }`
 * response: every genre, and 14 of the 428 tags (tests/Fixtures/anilist/vocabulary.json).
 *
 * @param  callable(array<string, mixed>): array<string, mixed>|null  $change
 */
function fakeVocabulary(?callable $change = null): void
{
    $response = anilistFixture('vocabulary');

    Http::fake(['graphql.anilist.co' => Http::response($change === null ? $response : $change($response))]);
}

test('AniList: the full genre and tag lists, with category, description and adult flag, in one request', function () {
    fakeVocabulary();
    $fixture = anilistFixture('vocabulary')['data'];

    $vocabulary = app(MetadataProvider::class)->vocabulary();
    $tags = collect($vocabulary->tags)->keyBy('name');

    expect($vocabulary->genres)->toBe($fixture['GenreCollection'])
        ->and($vocabulary->genres)->toHaveCount(19)
        ->and($vocabulary->tags)->toHaveCount(14)
        ->and($tags['Time Manipulation'])->toEqual(new ProviderTagDefinition('Time Manipulation', 'Theme-Sci-Fi', 'Prominently features time-traveling or other time-warping phenomena.', false))
        ->and($tags["Boys' Love"]->category)->toBe('Theme-Romance')
        ->and($tags['Sweat']->isAdult)->toBeTrue()
        ->and($tags->where('isAdult', true)->keys()->all())->toBe(['Sweat']);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_contains($request->body(), 'GenreCollection') && str_contains($request->body(), 'MediaTagCollection')
        && str_contains($request->body(), 'category') && str_contains($request->body(), 'description'));
});

test('AniList: blank, nameless and repeated entries are skipped, the first of a repeated tag kept', function () {
    fakeVocabulary(function (array $response) {
        $response['data']['GenreCollection'] = [...$response['data']['GenreCollection'], 'Comedy', '', '  ', null, 7];
        $response['data']['MediaTagCollection'] = [
            ...$response['data']['MediaTagCollection'],
            ['name' => 'Isekai', 'description' => 'A second Isekai.', 'category' => 'Other', 'isAdult' => true],
            ['name' => '  ', 'category' => 'Other'], ['category' => 'Other'], 'junk',
        ];

        return $response;
    });

    $vocabulary = app(MetadataProvider::class)->vocabulary();

    expect($vocabulary->genres)->toHaveCount(19)
        ->and($vocabulary->tags)->toHaveCount(14)
        ->and(collect($vocabulary->tags)->firstWhere('name', 'Isekai')->category)->toBe('Theme-Fantasy');
});

test('the job adds every name, used or not, with category and description, leaves anime tags alone and is idempotent', function () {
    fakeVocabulary();
    $anime = metadataAnime(['title_romaji' => 'Tagged', 'genres' => ['Drama']]);
    app(AnimeTaxonomyWriter::class)->replace($anime, ['Drama'], [new ProviderTag('Isekai', 80, false)], []);

    app()->call([new SyncAnimeVocabulary, 'handle']);
    app()->call([new SyncAnimeVocabulary, 'handle']);

    expect(DB::table('genres')->count())->toBe(19)
        ->and(DB::table('tags')->count())->toBe(14)
        ->and(DB::table('tags')->where('name', 'Isekai')->first(['category', 'is_adult']))->toEqual((object) ['category' => 'Theme-Fantasy', 'is_adult' => false])
        ->and(DB::table('tags')->where('name', 'Time Loop')->value('description'))->toStartWith('A character is stuck in a repetitive cycle')
        ->and(DB::table('tags')->where('name', 'Sweat')->value('is_adult'))->toBeTrue()
        ->and(DB::table('anime_tag')->count())->toBe(1)
        ->and(Anime::find($anime->id)->tags()->first()->pivot->rank)->toBe(80);
});

test('a later sync refreshes category and description of known tags, including ones first seen on an anime', function () {
    $anime = metadataAnime(['title_romaji' => 'Tagged']);
    app(AnimeTaxonomyWriter::class)->replace($anime, [], [new ProviderTag('Isekai', 80, false)], []);
    expect(DB::table('tags')->where('name', 'Isekai')->value('category'))->toBeNull();

    $first = anilistFixture('vocabulary');
    $second = $first;
    foreach ($second['data']['MediaTagCollection'] as $i => $tag) {
        if ($tag['name'] === 'Isekai') {
            $second['data']['MediaTagCollection'][$i]['category'] = 'Setting-Universe';
        }
    }
    Http::fake(['graphql.anilist.co' => Http::sequence()->push($first)->push($second)]);

    app()->call([new SyncAnimeVocabulary, 'handle']);
    expect(DB::table('tags')->where('name', 'Isekai')->value('category'))->toBe('Theme-Fantasy');

    app()->call([new SyncAnimeVocabulary, 'handle']);

    expect(DB::table('tags')->where('name', 'Isekai')->value('category'))->toBe('Setting-Universe')
        ->and(DB::table('tags')->where('name', 'Isekai')->count())->toBe(1);
});

test('a rate-limited vocabulary request is released, not failed', function () {
    $this->mock(MetadataProvider::class)->shouldReceive('vocabulary')->andThrow(new MetadataProviderException('slow down', rateLimited: true, retryAfter: 42));

    $job = (new SyncAnimeVocabulary)->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertReleased(42);
    expect(DB::table('tags')->count())->toBe(0);
});
