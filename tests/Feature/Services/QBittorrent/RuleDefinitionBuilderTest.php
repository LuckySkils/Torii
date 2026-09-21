<?php

declare(strict_types=1);

use App\Services\Feed\SubsPleaseTitleParser;
use App\Services\QBittorrent\RuleDefinitionBuilder;

test('build returns the full rule definition shape', function () {
    config(['subtracker.feed.url' => 'https://subsplease.org/rss/?r=1080']);
    config(['subtracker.qbittorrent.category' => 'Anime']);

    $rule = (new RuleDefinitionBuilder)->build('Grand Blue S3');

    expect($rule)->toBe([
        'enabled' => true,
        'mustContain' => '^\[SubsPlease\] Grand Blue S3 - \d',
        'mustNotContain' => '\[Batch\]',
        'useRegex' => true,
        'episodeFilter' => '',
        'smartFilter' => true,
        'affectedFeeds' => ['https://subsplease.org/rss/?r=1080'],
        'ignoreDays' => 0,
        'assignedCategory' => 'Anime',
    ]);
});

test('the regex does not match a sequel season or a subtitled release of a differently-named show', function () {
    $pattern = (new RuleDefinitionBuilder)->buildMustContain('Show');

    expect(preg_match('#'.$pattern.'#', '[SubsPlease] Show Season 2 - 01 (1080p) [ABCD1234].mkv'))->toBe(0)
        ->and(preg_match('#'.$pattern.'#', '[SubsPlease] Show - Subtitle - 01 (1080p) [ABCD1234].mkv'))->toBe(0)
        ->and(preg_match('#'.$pattern.'#', '[SubsPlease] Show - 01 (1080p) [ABCD1234].mkv'))->toBe(1);
});

test('mustNotContain excludes every batch title, including synthetic ones with an unparseable range', function () {
    $builder = new RuleDefinitionBuilder;
    $mustNotContainPattern = '#'.$builder->buildMustNotContain().'#';

    $batchTitles = [
        '[SubsPlease] Mujikaku Seijo wa Kyou mo Muishiki ni Chikara wo Tare Nagasu (01-12) (1080p) [Batch]',
        '[SubsPlease] Neko to Ryuu (01-12) (1080p) [Batch]',
        '[SubsPlease] Some Show (Season 2) (1080p) [Batch]',
    ];

    foreach ($batchTitles as $title) {
        expect(preg_match($mustNotContainPattern, $title))->toBe(1, "mustNotContain failed to match batch title: {$title}");
    }

    // And the must-contain side never matches a batch title in the first place, so both conditions agree.
    foreach ($batchTitles as $title) {
        $pattern = '#'.$builder->buildMustContain('anything').'#';
        expect(preg_match($pattern, $title))->toBe(0);
    }
});

test('every show rule regex matches exactly that show\'s non-batch titles in the real fixture and nothing else', function () {
    $xml = simplexml_load_file(dirname(__DIR__, 3).'/Fixtures/subsplease_1080.xml');
    $parser = new SubsPleaseTitleParser;
    $builder = new RuleDefinitionBuilder;

    $itemsByShow = [];
    foreach ($xml->channel->item as $item) {
        $title = (string) $item->title;
        $category = isset($item->category) ? (string) $item->category : null;
        $parsed = $parser->parse($title, $category);

        expect($parsed->name)->not->toBeNull();

        $itemsByShow[$parsed->name][] = ['title' => $title, 'isBatch' => $parsed->isBatch];
    }

    expect(count($itemsByShow))->toBeGreaterThan(1);

    $mustNotContainPattern = '#'.$builder->buildMustNotContain().'#';

    foreach ($itemsByShow as $showName => $items) {
        $pattern = '#'.$builder->buildMustContain($showName).'#';

        foreach ($items as $item) {
            $matches = preg_match($pattern, $item['title']) === 1;

            if ($item['isBatch']) {
                expect($matches)->toBeFalse("Batch title unexpectedly matched [{$showName}]'s rule: {$item['title']}")
                    ->and(preg_match($mustNotContainPattern, $item['title']))
                    ->toBe(1, "mustNotContain failed to exclude batch title: {$item['title']}");
            } else {
                expect($matches)->toBeTrue("Title failed to match its own show [{$showName}]'s rule: {$item['title']}");
            }
        }

        foreach ($itemsByShow as $otherShowName => $otherItems) {
            if ($otherShowName === $showName) {
                continue;
            }

            foreach ($otherItems as $item) {
                expect(preg_match($pattern, $item['title']))
                    ->toBe(0, "[{$showName}]'s rule incorrectly matched another show's title: {$item['title']}");
            }
        }
    }
});
