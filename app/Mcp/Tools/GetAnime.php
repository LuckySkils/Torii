<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Data\AnimeData;
use App\Models\Anime;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_anime')]
#[Description(<<<'TEXT'
    Full detail for one anime by Torii anime id (the id other tools return, not an AniList id): all titles and synonyms, dates, episode counts (total and aired), duration, genres, AniList tags with their relevance rank (0-100, spoiler tags omitted), studios, the description (HTML, truncated to about 600 characters; descriptionTruncated says whether it was cut), the next airing and estimated airing window, external ids (e.g. anilist) and the linked Torii show(s). Use it on a shortlist from search_anime or suggest_anime. Read-only.
    TEXT)]
#[IsReadOnly]
final class GetAnime extends ToriiTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'anime_id' => $schema->integer()->description('Torii anime id.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate(['anime_id' => ['required', 'integer']]);
        $anime = Anime::find((int) $request->get('anime_id'));

        return $anime === null
            ? Response::error("No anime with id {$request->get('anime_id')}.")
            : $this->json(AnimeData::detail($anime));
    }
}
