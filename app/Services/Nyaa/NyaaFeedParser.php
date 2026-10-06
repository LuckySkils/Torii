<?php

declare(strict_types=1);

namespace App\Services\Nyaa;

use App\Services\Feed\Infohash;
use Carbon\CarbonImmutable;
use SimpleXMLElement;
use Throwable;

/**
 * Parses a Nyaa RSS page: `<title>`, `<link>` (the .torrent URL), `<guid>` (the
 * view page), `<pubDate>` (RFC 822, true UTC) and the `nyaa:` fields (seeders,
 * leechers, downloads, infoHash, category, size, trusted, remake). The `nyaa:`
 * children are read by prefix, so the namespace URI doesn't matter.
 */
final class NyaaFeedParser
{
    /**
     * @return array{title: string, items: array<int, NyaaItem>}
     *
     * @throws NyaaException when the body isn't an RSS feed
     */
    public function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $feed = simplexml_load_string($xml, options: LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($feed === false || ! isset($feed->channel)) {
            throw new NyaaException('Nyaa did not return an RSS feed.');
        }

        $items = [];

        foreach ($feed->channel->item ?? [] as $item) {
            $items[] = $this->item($item);
        }

        return ['title' => trim((string) $feed->channel->title), 'items' => $items];
    }

    private function item(SimpleXMLElement $item): NyaaItem
    {
        $nyaa = $item->children('nyaa', true);
        $viewUrl = trim((string) $item->guid);
        $rawDate = trim((string) $item->pubDate);

        try {
            // RFC 822 without the weekday: a mismatched "Sat," would otherwise move the date to that day.
            $publishedAt = $rawDate === '' ? null : CarbonImmutable::parse(preg_replace('/^[A-Za-z]{3},\s*/', '', $rawDate))->utc();
        } catch (Throwable) {
            $publishedAt = null;
        }

        return new NyaaItem(
            title: trim((string) $item->title),
            torrentUrl: trim((string) $item->link),
            viewUrl: $viewUrl,
            nyaaId: preg_match('~/view/(\d+)~', $viewUrl, $match) === 1 ? $match[1] : null,
            publishedAt: $publishedAt,
            publishedAtRaw: $rawDate,
            infohash: Infohash::normalize(isset($nyaa->infoHash) ? (string) $nyaa->infoHash : null),
            size: isset($nyaa->size) ? trim((string) $nyaa->size) : null,
            seeders: (int) ($nyaa->seeders ?? 0),
            leechers: (int) ($nyaa->leechers ?? 0),
            downloads: (int) ($nyaa->downloads ?? 0),
            trusted: strcasecmp(trim((string) ($nyaa->trusted ?? '')), 'Yes') === 0,
            remake: strcasecmp(trim((string) ($nyaa->remake ?? '')), 'Yes') === 0,
            category: isset($nyaa->category) ? trim((string) $nyaa->category) : null,
        );
    }
}
