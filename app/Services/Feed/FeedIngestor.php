<?php

declare(strict_types=1);

namespace App\Services\Feed;

use App\Events\NewReleaseDetected;
use App\Models\Release;
use App\Models\Show;
use Carbon\Carbon;
use Illuminate\Support\Str;
use SimpleXMLElement;

final class FeedIngestor
{
    private const SUBSPLEASE_NAMESPACE = 'https://subsplease.org/rss';

    public function __construct(
        private readonly SubsPleaseTitleParser $parser = new SubsPleaseTitleParser,
    ) {}

    public function ingest(string $xmlBody): IngestResult
    {
        $xml = simplexml_load_string($xmlBody);

        if ($xml === false) {
            return new IngestResult(itemsTotal: 0, itemsNew: 0, showsNew: 0);
        }

        $items = $xml->channel->item ?? [];

        $itemsTotal = 0;
        $itemsNew = 0;
        $showsNew = 0;

        foreach ($items as $item) {
            $itemsTotal++;

            $title = (string) $item->title;
            $category = isset($item->category) ? (string) $item->category : null;
            $parsed = $this->parser->parse($title, $category);

            $showId = null;

            if ($parsed->name !== null) {
                [$show, $wasCreated] = $this->upsertShow($parsed->name, $parsed->episode);
                $showId = $show->id;

                if ($wasCreated) {
                    $showsNew++;
                }
            } else {
                logger()->warning('Unparseable SubsPlease release title', ['title' => $title]);
            }

            $wasNewRelease = $this->upsertRelease($item, $parsed, $showId);

            if ($wasNewRelease) {
                $itemsNew++;
            }
        }

        return new IngestResult(
            itemsTotal: $itemsTotal,
            itemsNew: $itemsNew,
            showsNew: $showsNew,
        );
    }

    /**
     * @return array{0: Show, 1: bool}
     */
    private function upsertShow(string $name, ?string $episode): array
    {
        $show = Show::where('name', $name)->first();

        if ($show === null) {
            $show = Show::create([
                'name' => $name,
                'slug' => Str::slug($name),
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'latest_episode' => $episode,
            ]);

            return [$show, true];
        }

        $show->last_seen_at = now();

        if ($episode !== null) {
            $show->latest_episode = $episode;
        }

        $show->save();

        return [$show, false];
    }

    private function upsertRelease(SimpleXMLElement $item, ParsedItem $parsed, ?int $showId): bool
    {
        $guid = (string) $item->guid;
        $link = (string) $item->link;

        $namespaced = $item->children(self::SUBSPLEASE_NAMESPACE);
        $sizeLabel = isset($namespaced->size) ? (string) $namespaced->size : null;

        $existing = Release::where('guid', $guid)->first();

        $attributes = [
            'show_id' => $showId,
            'title' => (string) $item->title,
            'episode' => $parsed->episode,
            'version' => $parsed->version,
            'is_batch' => $parsed->isBatch,
            'batch_from' => $parsed->batchFrom,
            'batch_to' => $parsed->batchTo,
            'resolution' => $parsed->resolution ?? '',
            'crc' => $parsed->crc,
            'link' => $link,
            'infohash' => $this->extractInfohash($link),
            'size_label' => $sizeLabel,
            'published_at' => Carbon::parse((string) $item->pubDate)->utc(),
        ];

        if ($existing === null) {
            $release = Release::create([...$attributes, 'guid' => $guid, 'first_seen_at' => now()]);

            NewReleaseDetected::dispatch($release);

            return true;
        }

        $existing->fill($attributes);
        $existing->save();

        return false;
    }

    private function extractInfohash(string $link): ?string
    {
        if (preg_match('/xt=urn:btih:([0-9A-Za-z]+)/', $link, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        return null;
    }
}
