<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ShowAnimeLink;
use App\Services\Metadata\AiringWindow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `anime` prop on a show (§9.7): the linked anime, or null when unlinked.
 * List endpoints get the small summary; the show page gets the full object.
 * Expects `anime.image` (id/anime_id/sha256/width/height only) and, for full, `anime.nextAiring` loaded.
 *
 * @mixin ShowAnimeLink
 */
final class LinkedAnimeResource extends JsonResource
{
    public function __construct(ShowAnimeLink $link, private readonly bool $full = false)
    {
        parent::__construct($link);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function summaryFor(?ShowAnimeLink $link): ?array
    {
        return $link?->anime === null ? null : (new self($link))->resolve();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function fullFor(?ShowAnimeLink $link): ?array
    {
        return $link?->anime === null ? null : (new self($link, full: true))->resolve();
    }

    public function toArray(Request $request): array
    {
        $anime = $this->anime;

        $summary = [
            'id' => $anime->id,
            'titleRomaji' => $anime->title_romaji,
            'titleEnglish' => $anime->title_english,
            'coverUrl' => $anime->image?->url(),
            'coverWidth' => $anime->image?->width,
            'coverHeight' => $anime->image?->height,
            'episodesAired' => $anime->episodes_aired,
            'linkSource' => $this->source->value,
        ];

        if (! $this->full) {
            return $summary;
        }

        return [
            ...$summary,
            'genres' => $anime->genres ?? [],
            'episodesTotal' => $anime->episodes_total,
            'status' => $anime->status,
            'format' => $anime->format,
            'durationMinutes' => $anime->duration_minutes,
            'description' => $anime->description,
            'season' => $anime->season,
            'seasonYear' => $anime->season_year,
            'nextAiringAt' => $anime->nextAiring?->airs_at->toIso8601String(),
            'nextEpisode' => $anime->nextAiring?->episode,
            'siteUrl' => $anime->site_url,
            'airingWindow' => app(AiringWindow::class)->for($anime->id, now()),
            'confidence' => $this->confidence,
        ];
    }
}
