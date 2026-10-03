<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use App\Models\Anime;
use Illuminate\Database\Eloquent\Builder;

/**
 * The anime filters shared by /schedule and the MCP tools (search_anime,
 * list_schedule, suggest_anime). Normalized on construction: unknown formats,
 * modes and empty values are dropped rather than matching nothing.
 */
final readonly class AnimeFilters
{
    /**
     * @param  array<int, string>  $formats
     * @param  array<int, string>  $genresInclude
     * @param  array<int, string>  $genresExclude
     */
    public function __construct(
        public ?string $query = null,
        public ?string $season = null,
        public ?int $year = null,
        public array $formats = [],
        public ?string $status = null,
        public array $genresInclude = [],
        public array $genresExclude = [],
        public string $adult = 'hide',
        public string $linked = 'all',
        public string $tracked = 'all',
    ) {}

    /**
     * From loose input (query params or tool arguments): accepts arrays or comma lists.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $list = function (mixed $value): array {
            $values = is_array($value) ? $value : explode(',', (string) $value);

            return array_values(array_unique(array_filter(array_map(fn ($item) => trim((string) $item), $values), fn (string $item) => $item !== '')));
        };
        $text = fn (mixed $value): ?string => ($value = trim((string) $value)) === '' ? null : $value;

        return new self(
            query: $text($input['query'] ?? null),
            season: ($season = $text($input['season'] ?? null)) === null ? null : strtoupper($season),
            year: is_numeric($input['year'] ?? null) ? (int) $input['year'] : null,
            formats: array_values(array_intersect(array_map('strtoupper', $list($input['format'] ?? [])), Anime::FORMATS)),
            status: ($status = $text($input['status'] ?? null)) === null ? null : strtoupper($status),
            genresInclude: $list($input['genres_include'] ?? []),
            genresExclude: $list($input['genres_exclude'] ?? []),
            adult: in_array($input['adult'] ?? null, ['include', 'only'], true) ? $input['adult'] : 'hide',
            linked: in_array($input['linked'] ?? null, ['linked', 'unlinked'], true) ? $input['linked'] : 'all',
            tracked: in_array($input['tracked'] ?? null, ['tracked', 'untracked'], true) ? $input['tracked'] : 'all',
        );
    }

    /**
     * @param  Builder<Anime>  $anime
     */
    public function apply(Builder $anime): void
    {
        $anime
            ->when($this->query !== null, fn (Builder $q) => $q->matchingTitle((string) $this->query))
            ->when($this->season !== null, fn (Builder $q) => $q->where('season', $this->season))
            ->when($this->year !== null, fn (Builder $q) => $q->where('season_year', $this->year))
            ->when($this->formats !== [], fn (Builder $q) => $q->whereIn('format', $this->formats))
            ->when($this->status !== null, fn (Builder $q) => $q->where('status', $this->status))
            ->withAllGenres($this->genresInclude)
            ->withoutGenres($this->genresExclude)
            ->when($this->adult === 'hide', fn (Builder $q) => $q->where('is_adult', false))
            ->when($this->adult === 'only', fn (Builder $q) => $q->where('is_adult', true))
            ->when($this->linked === 'linked', fn (Builder $q) => $q->whereHas('links'))
            ->when($this->linked === 'unlinked', fn (Builder $q) => $q->whereDoesntHave('links'))
            // "Tracked" = linked to a show that is currently tracked.
            ->when($this->tracked === 'tracked', fn (Builder $q) => $q->whereHas('links.show', fn (Builder $show) => $show->where('is_tracked', true)))
            ->when($this->tracked === 'untracked', fn (Builder $q) => $q->whereDoesntHave('links.show', fn (Builder $show) => $show->where('is_tracked', true)));
    }
}
