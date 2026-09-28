<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Anime;
use App\Models\ShowAnimeLink;
use App\Services\Metadata\AiringWindow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One anime on the browse page (Anime/Index), or with detail() on Anime/Show.
 * Expects `image` (id/anime_id/sha256/width/height only), `nextAiring` and `links.show` loaded.
 *
 * @mixin Anime
 */
final class AnimeResource extends JsonResource
{
    private bool $detail = false;

    public function detail(): self
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $item = [
            'id' => $this->id,
            'titleRomaji' => $this->title_romaji,
            'titleEnglish' => $this->title_english,
            'titleNative' => $this->title_native,
            'coverUrl' => $this->image?->url(),
            'coverWidth' => $this->image?->width,
            'coverHeight' => $this->image?->height,
            'format' => $this->format,
            'status' => $this->status,
            'season' => $this->season,
            'seasonYear' => $this->season_year,
            'episodesTotal' => $this->episodes_total,
            'episodesAired' => $this->episodes_aired,
            'genres' => $this->genres ?? [],
            'startDate' => $this->start_date?->toDateString(),
            'nextAiringAt' => $this->nextAiring?->airs_at->toIso8601String(),
            'nextEpisode' => $this->nextAiring?->episode,
            'isAdult' => $this->is_adult,
            'linkedShows' => $this->links->map(fn (ShowAnimeLink $link) => [
                'id' => $link->show->id,
                'name' => $link->show->name,
                'linkSource' => $link->source->value,
                'confidence' => $link->confidence,
            ])->values()->all(),
        ];

        if (! $this->detail) {
            return $item;
        }

        return [
            ...$item,
            'synonyms' => $this->synonyms ?? [],
            'description' => $this->description,
            'endDate' => $this->end_date?->toDateString(),
            'durationMinutes' => $this->duration_minutes,
            'siteUrl' => $this->site_url,
            'externalIds' => $this->externalIds->pluck('external_id', 'provider')->all(),
            'primaryProvider' => $this->primary_provider,
            'syncedAt' => $this->synced_at->toIso8601String(),
            'airingWindow' => app(AiringWindow::class)->for($this->id, now()),
        ];
    }
}
