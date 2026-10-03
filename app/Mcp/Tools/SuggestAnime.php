<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Data\AnimeSuggestions;
use App\Services\Metadata\AnimeFilters;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('suggest_anime')]
#[Description(<<<'TEXT'
    Recommendation candidates from Torii's catalog, for "what should I watch", "something like X" or "what am I missing". The result is a set of candidates with evidence, not a ranking: use each candidate's `why` together with your own knowledge of the titles to choose and explain, and call get_anime for detail on a shortlist. Candidates are compared with anchors: the anime in like_anime_ids, or, when none are given, the anime linked to currently tracked shows; plus the genres and tags requested in `tags` and `query`, together as one more anchor. For precise results, call list_tags, pick exact names (preferring ones with usedInCatalog > 0) and pass them in `tags`. `query` is a convenience: free text whose genre and tag names are matched as whole phrases, longest first, but a word directly after an unrecognised word is not applied ("time travel" applies nothing rather than Travel; AniList's name is Time Manipulation), so parts of it may come back unrecognised. Both only add: to leave a genre out use genres_exclude. score adds up, over every anchor: per shared genre, its rarity in Torii's catalog (1 for a genre only two anime have, falling towards 0 for one nearly every anime has; common genres like Comedy or Fantasy count about 0.2); per shared tag, 1.5 × the lower of the two AniList relevance ranks / 100 × the tag's rarity in Torii's catalog (requested tags count as rank 100; distinctive tags count far more than generic ones like "Male Protagonist"); 2 per shared studio, 0.5 for the same format, minus 1 when episode counts differ more than fourfold. Only anime in Torii's local catalog (recent and current seasons) can appear. Tracked and untracked anime are both returned and flagged (isTracked, isLinked, showId); set include_tracked false to leave out what is already tracked. why: sharedGenres [{name, catalogShare, weight}], sharedTags [{name, rank, catalogShare, weight}] (rank = this candidate's AniList relevance, 0-100; catalogShare = fraction of Torii's catalog with this tag; weight = what that genre or tag added to score; heaviest first, at most 8 tags), sharedStudios, anchors (ids of the anime anchors it overlaps most, strongest first; the requested anchor is not listed). The response also lists the anchors used, requestedTags for `tags` (genres and tags applied, notInCatalog: known names no anime here has, so they match nothing; unknown: names that are not in the vocabulary at all) and queryTerms for `query` (the same, with unrecognised: phrases that name no known genre or tag, so that part of the request was not applied, e.g. moods like "cozy" or titles, which belong in like_anime_ids). Read-only.
    TEXT)]
#[IsReadOnly]
final class SuggestAnime extends ToriiTool
{
    private const DEFAULT_LIMIT = 30;

    public function __construct(private readonly AnimeSuggestions $suggestions) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->animeFilterSchema($schema, 'Free text describing what the user wants (convenience; for precision use tags). Genre and tag names in it act as an extra anchor; everything else is reported back as unrecognised. Not a title search, and not for exclusions.'),
            'tags' => $schema->array()->items($schema->string())->description('Exact genre or tag names from list_tags (case and punctuation ignored) to find anime that have them; they act as an extra anchor. Unknown names are reported back in requestedTags.unknown, not guessed.'),
            'like_anime_ids' => $schema->array()->items($schema->integer())->description('Torii anime ids to find similar anime to. These anime themselves are not returned. Default: the anime linked to tracked shows.'),
            'include_tracked' => $schema->boolean()->description('Include anime whose show is already tracked. Default true.'),
            'limit' => $schema->integer()->min(1)->description('Candidates to return. Default '.self::DEFAULT_LIMIT."; values above the server's MAX_RESULTS cap are reduced to it."),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate([
            'like_anime_ids' => ['nullable', 'array', 'max:20'],
            'like_anime_ids.*' => ['integer'],
            'tags' => ['nullable', 'array', 'max:30'],
            'tags.*' => ['string', 'max:100'],
            'include_tracked' => ['nullable', 'boolean'],
        ]);

        // query describes taste here, not a title: keep it out of the title filter.
        $filters = AnimeFilters::fromArray([...$request->all(), 'query' => null]);

        try {
            return $this->json($this->suggestions->suggest(
                query: is_string($request->get('query')) ? $request->get('query') : null,
                likeAnimeIds: array_map('intval', (array) ($request->get('like_anime_ids') ?? [])),
                filters: $filters,
                includeTracked: (bool) ($request->get('include_tracked') ?? true),
                limit: $this->limit($request, self::DEFAULT_LIMIT),
                tagNames: array_values(array_filter(array_map('trim', (array) ($request->get('tags') ?? [])), fn (string $name) => $name !== '')),
            ));
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }
    }
}
