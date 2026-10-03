<?php

declare(strict_types=1);

namespace App\Services\Metadata\AniList;

use App\Contracts\MetadataProvider;
use App\Services\Metadata\ProviderAiring;
use App\Services\Metadata\ProviderAnime;
use App\Services\Metadata\ProviderTagDefinition;
use App\Services\Metadata\ProviderVocabulary;

final class AniListProvider implements MetadataProvider
{
    /** AniList's `perPage` maximum for a top-level Page. */
    public const PER_PAGE = 50;

    /**
     * A nested `airingSchedule` connection silently caps perPage at 25, whatever
     * is asked for (verified live), so page arithmetic uses 25 explicitly.
     */
    public const SCHEDULE_PER_PAGE = 25;

    /**
     * pageInfo.total/lastPage are fake caps (5000/1000), so paging follows only
     * hasNextPage; this is just a runaway guard (2,500 entries per season).
     */
    private const MAX_PAGES = 50;

    /**
     * The Media fields every entry query asks for, verified against the live API
     * (including at perPage 50). Tags, studios and external links aren't read yet;
     * they're fetched so the stored payload already has them when something does.
     */
    private const MEDIA_FIELDS = <<<'GRAPHQL'
        id idMal
        title { romaji english native }
        synonyms description genres format status episodes duration season seasonYear
        startDate { year month day }
        endDate { year month day }
        coverImage { extraLarge large medium color }
        bannerImage siteUrl isAdult
        nextAiringEpisode { airingAt timeUntilAiring episode }
        tags { name rank isMediaSpoiler }
        studios(isMain: true) { nodes { id name } }
        externalLinks { site url type language }
        GRAPHQL;

    private const SCHEDULE_FIELDS = 'pageInfo { hasNextPage } nodes { episode airingAt }';

    public function __construct(
        private readonly AniListClient $client,
        private readonly AniListMediaParser $parser,
    ) {}

    public function key(): string
    {
        return AniListMediaParser::PROVIDER;
    }

    public function capabilities(): array
    {
        return ['seasons', 'schedule', 'images', 'descriptions'];
    }

    /**
     * @return iterable<int, ProviderAnime>
     */
    public function season(string $season, int $year): iterable
    {
        // Sorted by id, not popularity, so entries can't shift between pages mid-sync.
        $query = 'query ($season: MediaSeason, $seasonYear: Int, $page: Int, $perPage: Int) {
            Page(page: $page, perPage: $perPage) {
                pageInfo { hasNextPage currentPage }
                media(season: $season, seasonYear: $seasonYear, type: ANIME, sort: [ID]) { '.self::MEDIA_FIELDS.' }
            }
        }';

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $data = $this->client->query($query, [
                'season' => strtoupper($season),
                'seasonYear' => $year,
                'page' => $page,
                'perPage' => self::PER_PAGE,
            ]);

            foreach ($data['Page']['media'] ?? [] as $media) {
                if (is_array($media) && isset($media['id'])) {
                    yield $this->parser->parse($media);
                }
            }

            if (($data['Page']['pageInfo']['hasNextPage'] ?? false) !== true) {
                return;
            }
        }

        logger()->warning('AniList season paging hit the page guard', ['season' => $season, 'year' => $year]);
    }

    public function byExternalId(string $id): ?ProviderAnime
    {
        try {
            $data = $this->client->query(
                'query ($id: Int) { Media(id: $id, type: ANIME) { '.self::MEDIA_FIELDS.' } }',
                ['id' => (int) $id],
            );
        } catch (AniListException $e) {
            if ($e->status === 404) {
                return null;
            }

            throw $e;
        }

        return is_array($data['Media'] ?? null) ? $this->parser->parse($data['Media']) : null;
    }

    public function byExternalIds(array $ids): array
    {
        $query = 'query ($ids: [Int], $page: Int, $perPage: Int) {
            Page(page: $page, perPage: $perPage) {
                pageInfo { hasNextPage }
                media(id_in: $ids, type: ANIME) { '.self::MEDIA_FIELDS.' }
            }
        }';

        $results = [];

        foreach (array_chunk($this->intIds($ids), self::PER_PAGE) as $chunk) {
            $data = $this->client->query($query, ['ids' => $chunk, 'page' => 1, 'perPage' => self::PER_PAGE]);

            foreach ($data['Page']['media'] ?? [] as $media) {
                if (is_array($media) && isset($media['id'])) {
                    $results[] = $this->parser->parse($media);
                }
            }
        }

        return $results;
    }

    public function search(string $query, int $limit = 10): array
    {
        // Called from a web request: fail fast rather than wait on the throttle.
        $data = $this->client->interactive()->query(
            'query ($search: String, $perPage: Int) {
                Page(page: 1, perPage: $perPage) {
                    media(search: $search, type: ANIME, sort: SEARCH_MATCH) { '.self::MEDIA_FIELDS.' }
                }
            }',
            ['search' => $query, 'perPage' => max(1, min($limit, self::PER_PAGE))],
        );

        $results = [];

        foreach ($data['Page']['media'] ?? [] as $media) {
            if (is_array($media) && isset($media['id'])) {
                $results[] = $this->parser->parse($media);
            }
        }

        return $results;
    }

    public function schedule(string $externalId): array
    {
        return $this->schedules([$externalId])[$externalId] ?? [];
    }

    /**
     * One request: AniList's genre list and its full tag collection (every tag,
     * adult ones included, whether or not any anime here has it), with each tag's
     * category and description.
     */
    public function vocabulary(): ProviderVocabulary
    {
        $data = $this->client->query('query { GenreCollection MediaTagCollection { name description category isAdult } }');

        $text = fn ($value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;

        $genres = array_values(array_unique(array_filter(
            array_map($text, is_array($data['GenreCollection'] ?? null) ? $data['GenreCollection'] : []),
        )));

        $tags = [];

        foreach (is_array($data['MediaTagCollection'] ?? null) ? $data['MediaTagCollection'] : [] as $tag) {
            $name = is_array($tag) ? $text($tag['name'] ?? null) : null;

            if ($name !== null && ! isset($tags[$name])) {
                $tags[$name] = new ProviderTagDefinition(
                    name: $name,
                    category: $text($tag['category'] ?? null),
                    description: $text($tag['description'] ?? null),
                    isAdult: ($tag['isAdult'] ?? false) === true,
                );
            }
        }

        return new ProviderVocabulary($genres, array_values($tags));
    }

    /**
     * One request per 50 anime (each with its first 25 airings), plus one follow-up
     * per extra 25 airings for an anime that has more (a 26-episode cour, One Piece).
     */
    public function schedules(array $externalIds): array
    {
        $query = 'query ($ids: [Int], $page: Int, $perPage: Int) {
            Page(page: $page, perPage: $perPage) {
                pageInfo { hasNextPage }
                media(id_in: $ids, type: ANIME) {
                    id
                    nextAiringEpisode { airingAt episode }
                    airingSchedule(page: 1, perPage: '.self::SCHEDULE_PER_PAGE.') { '.self::SCHEDULE_FIELDS.' }
                }
            }
        }';

        $schedules = [];

        foreach (array_chunk($this->intIds($externalIds), self::PER_PAGE) as $chunk) {
            $data = $this->client->query($query, ['ids' => $chunk, 'page' => 1, 'perPage' => self::PER_PAGE]);

            foreach ($data['Page']['media'] ?? [] as $media) {
                if (! is_array($media) || ! isset($media['id'])) {
                    continue;
                }

                $id = (string) $media['id'];
                $connection = is_array($media['airingSchedule'] ?? null) ? $media['airingSchedule'] : [];
                $airings = $this->scheduleNodes($connection);

                if (($connection['pageInfo']['hasNextPage'] ?? false) === true) {
                    $airings = [...$airings, ...$this->remainingSchedulePages($id)];
                }

                $next = $this->parser->airing($media['nextAiringEpisode'] ?? null);

                if ($next !== null) {
                    $airings[] = $next;
                }

                $schedules[$id] = $this->uniqueByEpisode($airings);
            }
        }

        return $schedules;
    }

    /**
     * @return array<int, ProviderAiring>
     */
    private function remainingSchedulePages(string $id): array
    {
        $query = 'query ($id: Int, $page: Int, $perPage: Int) {
            Media(id: $id, type: ANIME) { airingSchedule(page: $page, perPage: $perPage) { '.self::SCHEDULE_FIELDS.' } }
        }';

        $airings = [];

        for ($page = 2; $page <= self::MAX_PAGES; $page++) {
            $data = $this->client->query($query, ['id' => (int) $id, 'page' => $page, 'perPage' => self::SCHEDULE_PER_PAGE]);
            $connection = is_array($data['Media']['airingSchedule'] ?? null) ? $data['Media']['airingSchedule'] : [];
            $airings = [...$airings, ...$this->scheduleNodes($connection)];

            if (($connection['pageInfo']['hasNextPage'] ?? false) !== true) {
                break;
            }
        }

        return $airings;
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return array<int, ProviderAiring>
     */
    private function scheduleNodes(array $connection): array
    {
        $airings = [];

        foreach ($connection['nodes'] ?? [] as $node) {
            $airing = $this->parser->airing($node);

            if ($airing !== null) {
                $airings[] = $airing;
            }
        }

        return $airings;
    }

    /**
     * nextAiringEpisode usually repeats a schedule node; the later-listed one wins.
     *
     * @param  array<int, ProviderAiring>  $airings
     * @return array<int, ProviderAiring>
     */
    private function uniqueByEpisode(array $airings): array
    {
        $byEpisode = [];

        foreach ($airings as $airing) {
            $byEpisode[$airing->episode] = $airing;
        }

        ksort($byEpisode);

        return array_values($byEpisode);
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<int, int>
     */
    private function intIds(array $ids): array
    {
        return array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));
    }
}
