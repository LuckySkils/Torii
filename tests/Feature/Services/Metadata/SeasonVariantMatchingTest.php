<?php

declare(strict_types=1);

use App\Jobs\FetchAnimeCover;
use App\Jobs\MatchShowsToAnime;
use App\Models\ShowAnimeLink;
use App\Services\Metadata\Matching\AnimeMatcher;
use App\Services\Metadata\Matching\ShowAnimeLinker;
use App\Services\Metadata\Matching\TitleNormalizer;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([FetchAnimeCover::class]);
});

/**
 * Real AniList titles (romaji, english, native, synonyms) of entries on the dev
 * database, keyed by the SubsPlease show that should link to each.
 *
 * @return array<string, array<string, mixed>>
 */
function realEntries(): array
{
    return [
        'Mairimashita! Iruma-kun S4' => ['title_romaji' => 'Mairimashita! Iruma-kun 4', 'title_english' => 'Welcome to Demon School! Iruma-kun Season 4', 'title_native' => '魔入りました！入間くん 第4シリーズ', 'synonyms' => ['อิรุมะคุง ผจญในแดนปีศาจ ซีซั่น 4']],
        'Link Click S3' => ['title_romaji' => 'Shiguang Dailiren III', 'title_english' => 'Link Click Season 3', 'title_native' => '时光代理人 第三季', 'synonyms' => []],
        'Link Click S2' => ['title_romaji' => 'Shiguang Dailiren II', 'title_english' => 'Link Click Season 2', 'title_native' => '时光代理人 第二季', 'synonyms' => ['LINK CLICK II', 'ข้ามเวลาพิชิตภารกิจ ภาค 2']],
        'Link Click' => ['title_romaji' => 'Shiguang Dailiren', 'title_english' => 'Link Click', 'title_native' => '时光代理人', 'synonyms' => ['Time Agent', 'CLICK', 'ข้ามเวลาพิชิตภารกิจ']],
        // Shows that were already auto-linked before the fix.
        'Azur Lane - Bisoku Zenshin! S2' => ['title_romaji' => 'Azur Lane: Bisoku Zenshin! Ni!!', 'title_english' => 'Anime AzurLane: Slow Ahead! Season 2', 'title_native' => 'アズールレーン びそくぜんしんっ！にっ！！', 'synonyms' => ['AzurLane: Slow Ahead! Season 2', 'Azur Lane: Bisoku Zenshin! 2nd Season']],
        'Grand Blue S3' => ['title_romaji' => 'Grand Blue Season 3', 'title_english' => 'Grand Blue Dreaming Season 3', 'title_native' => 'ぐらんぶる Season 3', 'synonyms' => ['Grand Blue ก๊วนป่วนชวนบุ๋งบุ๋ง ซีซัน 3']],
        'Kokoore' => ['title_romaji' => 'Koko wa Ore ni Makasete Saki ni Ike to Ittekara 10-nen ga Tattara Densetsu ni Natteita.', 'title_english' => 'I Became a Legend After My 10 Year-Long Last Stand', 'title_native' => 'ここは俺に任せて先に行けと言ってから１０年がたったら伝説になっていた。', 'synonyms' => ['KokoOre', 'ここ俺']],
        'Nige Jouzu no Wakagimi S2' => ['title_romaji' => 'Nige Jouzu no Wakagimi 2nd Season', 'title_english' => 'The Elusive Samurai Season 2', 'title_native' => '逃げ上手の若君 第二期', 'synonyms' => ['Nigewaka 2', 'Tuan Muda yang Jago Kabur Musim 2', '擅长逃跑的殿下S2']],
        'Otome Kaijuu Carameliser' => ['title_romaji' => 'Otome Kaijuu Caraméliser', 'title_english' => 'KAIJU GIRL CARAMELISE', 'title_native' => '乙女怪獣キャラメリゼ', 'synonyms' => ['สาวน้อยไคจู คาราเมไลซ์']],
        'Hell Mode S2' => ['title_romaji' => 'Hell Mode: Yarikomi-zuki no Gamer wa Haisettei no Isekai de Musou Suru 2nd Season', 'title_english' => 'HELL MODE: The Hardcore Gamer Dominates in Another World with Garbage Balancing Season 2', 'title_native' => 'ヘルモード ～やり込み好きのゲーマーは廃設定の異世界で無双する～ 2nd Season', 'synonyms' => []],
        'Honzuki no Gekokujou S4' => ['title_romaji' => 'Honzuki no Gekokujou: Ryoushu no Youjo', 'title_english' => 'Ascendance of a Bookworm: Adopted Daughter of an Archduke', 'title_native' => '本好きの下剋上 領主の養女', 'synonyms' => ['Ascendance of a Bookworm Season 4', '本好きの下剋上 司書になるためには手段を選んでいられません 第4期', 'Honzuki no Gekokujou: Shisho ni Naru Tame ni wa Shudan wo Erandeiraremasen 4th Season']],
        'Mushoku Tensei S3' => ['title_romaji' => 'Mushoku Tensei III: Isekai Ittara Honki Dasu', 'title_english' => 'Mushoku Tensei: Jobless Reincarnation Season 3', 'title_native' => '無職転生Ⅲ ～異世界行ったら本気だす～', 'synonyms' => ['無職転生 ～異世界行ったら本気だす～ 第3期', 'Mushoku Tensei: Isekai Ittara Honki Dasu 3rd Season']],
        'Tenkosaki' => ['title_romaji' => 'Tenkousaki no Seiso Karen na Bishoujo ga, Mukashi Danshi to Omotte Issho ni Asonda Osananajimi datta Ken', 'title_english' => 'Oh Boy, Was I Wrong About Her', 'title_native' => '転校先の清楚可憐な美少女が、昔男子と思って一緒に遊んだ幼馴染だった件', 'synonyms' => ['Tenkosaki: The Neat and Pretty Girl at My New School Is a Childhood Friend of Mine Who I Thought Was a Boy', 'Tenbin']],
    ];
}

/**
 * @param  array<string, mixed>  $entry
 * @return array<int, string>
 */
function entryTitles(array $entry): array
{
    return array_values(array_filter([$entry['title_romaji'], $entry['title_english'], $entry['title_native'], ...$entry['synonyms']]));
}

test('the season comes from whichever variant marks it: Iruma-kun S4 matches the romaji "Iruma-kun 4"', function () {
    $matcher = new AnimeMatcher(new TitleNormalizer);
    $titles = entryTitles(realEntries()['Mairimashita! Iruma-kun S4']);

    expect($matcher->score('Mairimashita! Iruma-kun S4', $titles))->toBe(90)
        ->and($matcher->seasonOf($titles))->toBe(4)
        // Other seasons of the same show still don't match.
        ->and($matcher->score('Mairimashita! Iruma-kun', $titles))->toBe(0)
        ->and($matcher->score('Mairimashita! Iruma-kun S3', $titles))->toBe(0);
});

test('Link Click S3 matches season 3, and only season 3', function () {
    $matcher = new AnimeMatcher(new TitleNormalizer);
    $entries = realEntries();

    expect($matcher->score('Link Click S3', entryTitles($entries['Link Click S3'])))->toBe(90)
        ->and($matcher->score('Link Click S3', entryTitles($entries['Link Click S2'])))->toBe(0)
        ->and($matcher->score('Link Click S3', entryTitles($entries['Link Click'])))->toBe(0)
        ->and($matcher->seasonOf(entryTitles($entries['Link Click S3'])))->toBe(3)
        ->and($matcher->seasonOf(entryTitles($entries['Link Click'])))->toBe(1);
});

test('variants that disagree on the season give the anime no season to hand out', function () {
    $matcher = new AnimeMatcher(new TitleNormalizer);
    $titles = ['Foo 2nd Season', 'Foo Season 3', 'Foo 4'];

    expect($matcher->seasonOf($titles))->toBe(1)
        // "Foo 4" keeps its number as part of the name; no variant says season 4.
        ->and($matcher->score('Foo S4', $titles))->toBe(0)
        ->and($matcher->score('Foo S3', $titles))->toBe(90);
});

test('a trailing number no variant confirms as a season stays part of the name', function () {
    $matcher = new AnimeMatcher(new TitleNormalizer);

    expect($matcher->score('Cyberpunk: Edgerunners S2', ['Cyberpunk: Edgerunners 2']))->toBe(0)
        ->and($matcher->score('Mob Psycho 100', ['Mob Psycho 100']))->toBe(100)
        ->and($matcher->score('Mob Psycho 100 S3', ['Mob Psycho 100 III']))->toBe(90);
});

test('every show links to its own entry with all of them in one index, previously linked ones included', function () {
    foreach (realEntries() as $showName => $entry) {
        metadataShow($showName);
        metadataAnime($entry);
    }

    (new MatchShowsToAnime)->handle(app(ShowAnimeLinker::class));

    $linked = ShowAnimeLink::with(['show', 'anime'])->get()
        ->mapWithKeys(fn (ShowAnimeLink $link) => [$link->show->name => $link->anime->title_romaji])
        ->sortKeys()
        ->all();

    $expected = collect(realEntries())->map(fn (array $entry) => $entry['title_romaji'])->sortKeys()->all();

    expect($linked)->toBe($expected);
});
