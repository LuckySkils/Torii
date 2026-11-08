<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

/*
 * Shared by the Nyaa import tests (§16). Not a test file: required by them.
 */

const NYAA_LINK = 'https://nyaa.si/?page=rss&q=Hanaori-san+wa+Tensei+shitemo+Kenka+ga+Shitai+%5Bsubsplease%5D+1080&c=0_0&f=0';
const HANAORI = 'Hanaori-san wa Tensei shitemo Kenka ga Shitai';

/**
 * A Nyaa RSS page in the real feed's format (tests/Fixtures/nyaa/search_rss.xml),
 * for the cases that feed doesn't contain: v2s, remakes, batches, other groups.
 * The .torrent URL in <link>, the view page in <guid>, and the nyaa: fields.
 *
 * @param  array<int, array<string, mixed>>  $items  title, id, hash, and optionally size, seeders, trusted, remake, pubDate
 */
function nyaaRss(array $items, string $title = 'Nyaa - "Hanaori-san [subsplease] 1080" - Torrent File RSS'): string
{
    $xml = '<?xml version="1.0" encoding="utf-8"?>'."\n"
        .'<rss xmlns:atom="http://www.w3.org/2005/Atom" xmlns:nyaa="https://nyaa.si/xmlns/nyaa" version="2.0"><channel>'
        .'<title>'.htmlspecialchars($title, ENT_XML1).'</title><description>RSS Feed</description><link>https://nyaa.si/</link>';

    foreach ($items as $item) {
        $xml .= '<item>'
            .'<title>'.htmlspecialchars($item['title'], ENT_XML1).'</title>'
            .'<link>https://nyaa.si/download/'.$item['id'].'.torrent</link>'
            .'<guid isPermaLink="true">https://nyaa.si/view/'.$item['id'].'</guid>'
            .'<pubDate>'.($item['pubDate'] ?? 'Sun, 20 Sep 2026 15:31:09 -0000').'</pubDate>'
            .'<nyaa:seeders>'.($item['seeders'] ?? 120).'</nyaa:seeders><nyaa:leechers>3</nyaa:leechers><nyaa:downloads>900</nyaa:downloads>'
            .'<nyaa:infoHash>'.$item['hash'].'</nyaa:infoHash>'
            .'<nyaa:categoryId>1_2</nyaa:categoryId><nyaa:category>Anime - English-translated</nyaa:category>'
            .'<nyaa:size>'.($item['size'] ?? '1.4 GiB').'</nyaa:size><nyaa:comments>0</nyaa:comments>'
            .'<nyaa:trusted>'.(($item['trusted'] ?? true) ? 'Yes' : 'No').'</nyaa:trusted>'
            .'<nyaa:remake>'.(($item['remake'] ?? false) ? 'Yes' : 'No').'</nyaa:remake>'
            .'<description><![CDATA[<a href="https://nyaa.si/view/'.$item['id'].'">#'.$item['id'].'</a>]]></description>'
            .'</item>';
    }

    return $xml.'</channel></rss>';
}

function hanaori(string $episode, string $crc = '0E3DE4FA'): string
{
    return '[SubsPlease] '.HANAORI." - {$episode} (1080p) [{$crc}].mkv";
}

function hashFor(string $seed): string
{
    return sha1($seed);
}

/**
 * @param  array<int, array<string, mixed>>  $items
 * @param  array<int, string>  $inQbit  infohashes qBittorrent reports
 */
function fakeNyaa(array $items, array $inQbit = []): void
{
    Http::fake([
        'nyaa.si/*' => Http::response(nyaaRss($items), 200, ['Content-Type' => 'application/xml']),
        'http://qbit.test:8080/api/v2/torrents/info*' => Http::response(array_map(fn (string $hash) => ['hash' => $hash], $inQbit)),
    ]);
}

/**
 * @return array<string, mixed>
 */
function nyaaPreview(): array
{
    return test()->postJson('/import/nyaa/preview', ['url' => NYAA_LINK])->assertOk()->json();
}
