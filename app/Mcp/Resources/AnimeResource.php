<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Mcp\Data\AnimeData;
use App\Mcp\Redactor;
use App\Models\Anime;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

#[Name('anime')]
#[Description('One anime from Torii\'s catalog by Torii anime id: the same payload as the get_anime tool.')]
#[MimeType('application/json')]
final class AnimeResource extends Resource implements HasUriTemplate
{
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('torii://anime/{id}');
    }

    public function handle(Request $request): Response
    {
        $anime = ctype_digit((string) $request->get('id')) ? Anime::find((int) $request->get('id')) : null;

        return $anime === null
            ? Response::error("No anime with id {$request->get('id')}.")
            : Response::json(Redactor::clean(AnimeData::detail($anime)));
    }
}
