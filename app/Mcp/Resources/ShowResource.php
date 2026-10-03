<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Mcp\Data\ShowData;
use App\Mcp\Redactor;
use App\Models\Show;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

#[Name('show')]
#[Description('One Torii show (SubsPlease side) by show id: the same payload as the get_show tool.')]
#[MimeType('application/json')]
final class ShowResource extends Resource implements HasUriTemplate
{
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('torii://show/{id}');
    }

    public function handle(Request $request): Response
    {
        $show = ctype_digit((string) $request->get('id')) ? Show::find((int) $request->get('id')) : null;

        return $show === null
            ? Response::error("No show with id {$request->get('id')}.")
            : Response::json(Redactor::clean(ShowData::detail($show)));
    }
}
