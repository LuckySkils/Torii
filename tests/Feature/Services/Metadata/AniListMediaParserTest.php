<?php

declare(strict_types=1);

use App\Services\Metadata\AniList\AniListMediaParser;

test('parses a full entry from a real season page', function () {
    $media = anilistMedia('season_fall_2026_page1', 195516);

    $anime = (new AniListMediaParser)->parse($media);

    expect($anime->provider)->toBe('anilist')
        ->and($anime->externalId)->toBe('195516')
        ->and($anime->otherExternalIds)->toBe(['mal' => '61987'])
        ->and($anime->titleRomaji)->toBe('Kusuriya no Hitorigoto 3rd Season')
        ->and($anime->titleEnglish)->toBe('The Apothecary Diaries Season 3')
        ->and($anime->titleNative)->toBe('薬屋のひとりごと 第3期')
        ->and($anime->synonyms)->toBe([])
        ->and($anime->description)->toStartWith('In the aftermath of the Shi Clan’s rebellion')
        ->and($anime->description)->toContain('<br>')
        ->and($anime->genres)->toBe(['Drama', 'Mystery'])
        ->and($anime->format)->toBe('TV')
        ->and($anime->status)->toBe('NOT_YET_RELEASED')
        ->and($anime->episodesTotal)->toBeNull()
        ->and($anime->durationMinutes)->toBeNull()
        ->and($anime->season)->toBe('FALL')
        ->and($anime->seasonYear)->toBe(2026)
        ->and($anime->startDate)->toBe('2026-10-02')
        // extraLarge, AniList's largest; its URL path says "large".
        ->and($anime->coverUrl)->toBe('https://s4.anilist.co/file/anilistcdn/media/anime/cover/large/bx195516-MJpUZlOberqH.jpg')
        ->and($anime->bannerUrl)->toBeNull()
        ->and($anime->siteUrl)->toBe('https://anilist.co/anime/195516')
        ->and($anime->isAdult)->toBeFalse()
        ->and($anime->nextAiring?->episode)->toBe(1)
        ->and($anime->nextAiring?->airsAt->getTimestamp())->toBe(1790949600)
        ->and($anime->nextAiring?->airsAt->utcOffset())->toBe(0)
        ->and($anime->nextAiring?->isEstimate)->toBeFalse()
        ->and($anime->raw)->toBe($media);
});

test('an end date with every part null is stored as null', function () {
    $anime = (new AniListMediaParser)->parse(anilistMedia('season_fall_2026_page1', 195539));

    expect($anime->raw['endDate'])->toBe(['year' => null, 'month' => null, 'day' => null])
        ->and($anime->endDate)->toBeNull()
        ->and($anime->startDate)->toBe('2026-10-20')
        ->and($anime->synonyms)->toBe(['サイバーパンク: エッジランナーズ II', 'Cyberpunk: Edgerunners II']);
});

test('a start date with a year and month but no day is stored as null, the parts kept in raw', function () {
    $anime = (new AniListMediaParser)->parse(anilistMedia('season_fuzzy_dates', 178031));

    expect($anime->startDate)->toBeNull()
        ->and($anime->raw['startDate'])->toBe(['year' => 2027, 'month' => 10, 'day' => null])
        ->and($anime->season)->toBe('FALL')
        ->and($anime->seasonYear)->toBe(2027);
});

test('an entry with no English title keeps its other titles and synonyms', function () {
    $anime = (new AniListMediaParser)->parse(anilistMedia('season_fall_2026_page1', 160275));

    expect($anime->titleEnglish)->toBeNull()
        ->and($anime->titleRomaji)->toBe('Made in Abyss: Mezameru Shinpi')
        ->and($anime->synonyms)->toBe(['Made In Abyss 3', 'Made in Abyss: Awakening Mystery'])
        ->and($anime->format)->toBe('MOVIE')
        ->and($anime->episodesTotal)->toBe(1);
});

test('a finished entry has a null nextAiringEpisode, and full start and end dates', function () {
    $anime = (new AniListMediaParser)->parse(anilistMedia('season_summer_2026_finished', 178789));

    expect($anime->nextAiring)->toBeNull()
        ->and($anime->raw['nextAiringEpisode'])->toBeNull()
        ->and($anime->status)->toBe('FINISHED')
        ->and($anime->episodesTotal)->toBe(14)
        ->and($anime->durationMinutes)->toBe(24)
        ->and($anime->startDate)->toBe('2026-07-04')
        ->and($anime->endDate)->toBe('2026-09-27')
        ->and($anime->bannerUrl)->toBe('https://s4.anilist.co/file/anilistcdn/media/anime/banner/178789-9nHWmoRLlcLu.jpg');
});

test('every entry in every fixture parses', function (string $fixture) {
    $parser = new AniListMediaParser;

    foreach (anilistFixture($fixture)['data']['Page']['media'] as $media) {
        $anime = $parser->parse($media);

        expect($anime->externalId)->toBe((string) $media['id'])
            ->and($anime->titleRomaji)->not->toBeNull()
            ->and($anime->coverUrl)->toStartWith('https://s4.anilist.co/');
    }
})->with(['season_fall_2026_page1', 'season_summer_2026_finished', 'season_fuzzy_dates']);

test('an invalid calendar date is null rather than rolled over', function () {
    $media = anilistMedia('season_fall_2026_page1', 195516);
    $media['startDate'] = ['year' => 2026, 'month' => 2, 'day' => 30];

    expect((new AniListMediaParser)->parse($media)->startDate)->toBeNull();
});
