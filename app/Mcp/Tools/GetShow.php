<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Data\ShowData;
use App\Models\Show;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_show')]
#[Description(<<<'TEXT'
    One Torii show (SubsPlease side) by show id: everything list_shows returns, plus ruleError, premieredAt, releasesTotal, its newest releases (at most 50, newest first; each with episode, version, batch range, publishedAt, dispatchStatus — null: never sent, "sent": queued in qBittorrent, "exists": qBittorrent already had it, "error": sending failed, see dispatchError — and downloadedAt (when qBittorrent finished it)), its anime link (anime summary, linkSource auto or manual, confidence) and pending anime link suggestions with their match scores. Read-only.
    TEXT)]
#[IsReadOnly]
final class GetShow extends ToriiTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'show_id' => $schema->integer()->description('Torii show id.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate(['show_id' => ['required', 'integer']]);
        $show = Show::find((int) $request->get('show_id'));

        return $show === null
            ? Response::error("No show with id {$request->get('show_id')}.")
            : $this->json(ShowData::detail($show));
    }
}
