<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\QueueMissingReleases;
use App\Models\Release;
use App\Models\Show;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('queue_missing')]
#[Description(<<<'TEXT'
    CHANGES STATE: starts downloads. Queues every release of a Torii show that hasn't been sent to qBittorrent yet (the newest version of each episode, plus batches), exactly like "Queue missing" in Torii's web UI. Works whether or not the show is tracked, and does not change tracking or the RSS rule. Releases already sent, or already present in qBittorrent, are skipped. Only call it when the user has asked to download this show's missing episodes. Returns releasesQueued (0 when nothing was missing) and their episodes; they are handed to the background job that sends them to qBittorrent.
    TEXT)]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsOpenWorld]
final class QueueMissing extends WriteTool
{
    public function __construct(private readonly QueueMissingReleases $queueMissing) {}

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

        if ($show === null) {
            return $this->failed($request, "No show with id {$request->get('show_id')}.");
        }

        $releaseIds = ($this->queueMissing)($show);

        return $this->done($request, [
            'show' => ['id' => $show->id, 'name' => $show->name, 'isTracked' => $show->is_tracked],
            'releasesQueued' => count($releaseIds),
            'queuedEpisodes' => Release::query()->whereKey($releaseIds)->orderBy('published_at')->get(['id', 'episode', 'is_batch', 'batch_from', 'batch_to'])
                ->map(fn (Release $release) => $release->is_batch ? "batch {$release->batch_from}-{$release->batch_to}" : $release->episode)
                ->all(),
        ]);
    }
}
