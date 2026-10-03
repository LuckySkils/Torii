<?php

declare(strict_types=1);

namespace App\Mcp\Data;

use App\Models\Anime;
use App\Models\ShowAnimeLink;
use App\Models\Tag;
use App\Services\Metadata\AiringWindow;
use App\Support\HtmlExcerpt;

/**
 * The anime shapes MCP tools return. Compact: no description in list rows; only
 * the detail carries one, cut the way /anime/{id}/card cuts it.
 */
final class AnimeData
{
    /** Columns a summary needs. */
    public const SUMMARY_COLUMNS = ['id', 'title_romaji', 'title_english', 'format', 'status', 'season', 'season_year', 'episodes_total', 'genres', 'is_adult'];

    /** Eager loads a summary needs. */
    public static function summaryRelations(): array
    {
        return ['links.show' => fn ($q) => $q->select(['id', 'name', 'is_tracked'])];
    }

    /**
     * One list row. Expects `links.show` loaded.
     *
     * @return array<string, mixed>
     */
    public static function summary(Anime $anime): array
    {
        $show = $anime->primaryLinkedShow();

        return [
            'id' => $anime->id,
            'titleRomaji' => $anime->title_romaji,
            'titleEnglish' => $anime->title_english,
            'format' => $anime->format,
            'status' => $anime->status,
            'season' => $anime->season,
            'seasonYear' => $anime->season_year,
            'episodesTotal' => $anime->episodes_total,
            'genres' => $anime->genres ?? [],
            'isAdult' => $anime->is_adult,
            'isLinked' => $show !== null,
            'isTracked' => $show?->is_tracked ?? false,
            'showId' => $show?->id,
        ];
    }

    /**
     * Everything get_anime returns, loaded here.
     *
     * @return array<string, mixed>
     */
    public static function detail(Anime $anime): array
    {
        $anime->loadMissing([...self::summaryRelations(), 'externalIds', 'nextAiring']);
        [$description, $truncated] = HtmlExcerpt::cut($anime->description);

        return [
            ...self::summary($anime),
            'titleNative' => $anime->title_native,
            'synonyms' => $anime->synonyms ?? [],
            'startDate' => $anime->start_date?->toDateString(),
            'endDate' => $anime->end_date?->toDateString(),
            'episodesAired' => $anime->episodes_aired,
            'durationMinutes' => $anime->duration_minutes,
            // Non-spoiler AniList tags with their relevance rank (0–100), most relevant first.
            'tags' => $anime->tags()
                ->wherePivot('is_spoiler', false)
                ->orderByPivot('rank', 'desc')
                ->orderBy('name')
                ->get(['tags.name'])
                ->map(fn (Tag $tag) => ['name' => $tag->name, 'rank' => (int) $tag->pivot->rank])
                ->all(),
            'studios' => $anime->studios()->orderBy('name')->pluck('name')->all(),
            'description' => $description,
            'descriptionTruncated' => $truncated,
            'siteUrl' => $anime->site_url,
            'externalIds' => $anime->externalIds->pluck('external_id', 'provider')->all(),
            'airing' => [
                'next' => $anime->nextAiring === null ? null : ['episode' => $anime->nextAiring->episode, 'airsAt' => $anime->nextAiring->airs_at->toIso8601String()],
                'window' => app(AiringWindow::class)->for($anime->id, now()),
            ],
            'linkedShows' => $anime->links->map(fn (ShowAnimeLink $link) => [
                'id' => $link->show->id,
                'name' => $link->show->name,
                'isTracked' => $link->show->is_tracked,
                'linkSource' => $link->source->value,
            ])->values()->all(),
        ];
    }
}
