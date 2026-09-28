<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Metadata\Matching\TitleNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Normalized anime metadata from an external provider. A separate, linkable
 * layer: never the source of truth for what Torii can download.
 */
class Anime extends Model
{
    protected $table = 'anime';

    protected $fillable = [
        'title_romaji',
        'title_english',
        'title_native',
        'synonyms',
        'description',
        'genres',
        'format',
        'status',
        'episodes_total',
        'episodes_aired',
        'season',
        'season_year',
        'start_date',
        'end_date',
        'duration_minutes',
        'cover_url',
        'banner_url',
        'site_url',
        'is_adult',
        'primary_provider',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'synonyms' => 'array',
            'genres' => 'array',
            'episodes_total' => 'integer',
            'episodes_aired' => 'integer',
            'season_year' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'duration_minutes' => 'integer',
            'is_adult' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function externalIds(): HasMany
    {
        return $this->hasMany(AnimeExternalId::class);
    }

    public function payloads(): HasMany
    {
        return $this->hasMany(AnimePayload::class);
    }

    public function airings(): HasMany
    {
        return $this->hasMany(AnimeAiring::class);
    }

    /** The earliest airing still in the future, eager-loadable (one-of-many). */
    public function nextAiring(): HasOne
    {
        return $this->hasOne(AnimeAiring::class)->ofMany(
            ['airs_at' => 'min', 'id' => 'min'],
            fn ($query) => $query->where('airs_at', '>', now()),
        );
    }

    public function image(): HasOne
    {
        return $this->hasOne(AnimeImage::class);
    }

    /** AniList's anime formats, in the order filters list them. */
    public const FORMATS = ['TV', 'TV_SHORT', 'MOVIE', 'SPECIAL', 'OVA', 'ONA', 'MUSIC'];

    /**
     * Every word of $text (normalized, season marker dropped) appears in some
     * title or synonym.
     *
     * @param  Builder<Anime>  $query
     */
    public function scopeMatchingTitle(Builder $query, string $text): void
    {
        $words = array_filter(explode(' ', app(TitleNormalizer::class)->normalize($text)->base), fn (string $word) => $word !== '');

        foreach ($words as $word) {
            // Words are letters/digits only after normalization, so no LIKE escaping is needed.
            $query->whereRaw(
                "lower(concat_ws(' ', title_romaji, title_english, title_native, synonyms::text)) like ?",
                ['%'.$word.'%'],
            );
        }
    }

    /**
     * Has every one of $genres (JSONB containment: genres @> '[...]').
     *
     * @param  Builder<Anime>  $query
     * @param  array<int, string>  $genres
     */
    public function scopeWithAllGenres(Builder $query, array $genres): void
    {
        if ($genres !== []) {
            $query->whereJsonContains('genres', array_values($genres));
        }
    }

    /**
     * Has none of $genres.
     *
     * @param  Builder<Anime>  $query
     * @param  array<int, string>  $genres
     */
    public function scopeWithoutGenres(Builder $query, array $genres): void
    {
        foreach ($genres as $genre) {
            $query->whereJsonDoesntContain('genres', $genre);
        }
    }

    /**
     * Has any of $genres (for "the linked anime has an excluded genre").
     *
     * @param  Builder<Anime>  $query
     * @param  array<int, string>  $genres
     */
    public function scopeWithAnyGenre(Builder $query, array $genres): void
    {
        $query->where(function (Builder $any) use ($genres) {
            foreach ($genres as $genre) {
                $any->orWhereJsonContains('genres', $genre);
            }
        });
    }

    /** Several SubsPlease shows may point at the same anime (show_id is unique, anime_id isn't). */
    public function links(): HasMany
    {
        return $this->hasMany(ShowAnimeLink::class);
    }

    public function externalId(string $provider): ?string
    {
        return $this->externalIds->firstWhere('provider', $provider)?->external_id;
    }

    /**
     * Every title form, for matching and search.
     *
     * @return array<int, string>
     */
    public function titles(): array
    {
        return array_values(array_filter(
            [$this->title_romaji, $this->title_english, $this->title_native, ...($this->synonyms ?? [])],
            fn (?string $title) => $title !== null && trim($title) !== '',
        ));
    }
}
