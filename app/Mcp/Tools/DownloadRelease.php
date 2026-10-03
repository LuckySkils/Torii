<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\DownloadRelease as DownloadReleaseAction;
use App\Models\Release;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('download_release')]
#[Description(<<<'TEXT'
    CHANGES STATE: starts a download. Sends one release (one episode, or a batch) to qBittorrent, exactly like the download button on a release in Torii's web UI, whether or not its show is tracked. Release ids come from get_show. A release already sent or already present in qBittorrent is not sent again. Only call it when the user has asked to download this release. Returns the release, queued (false when it had already been sent) and its dispatchStatus at the time of the call; sending happens in a background job, so a newly queued release shows its previous status until get_show is called again.
    TEXT)]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld]
final class DownloadRelease extends WriteTool
{
    public function __construct(private readonly DownloadReleaseAction $download) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'release_id' => $schema->integer()->description('Torii release id (from get_show).')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate(['release_id' => ['required', 'integer']]);
        $release = Release::with('show:id,name')->find((int) $request->get('release_id'));

        if ($release === null) {
            return $this->failed($request, "No release with id {$request->get('release_id')}.");
        }

        $queued = ($this->download)($release);

        return $this->done($request, [
            'release' => [
                'id' => $release->id,
                'title' => $release->title,
                'episode' => $release->episode,
                'isBatch' => $release->is_batch,
                'show' => ['id' => $release->show->id, 'name' => $release->show->name],
            ],
            'queued' => $queued,
            'dispatchStatus' => $release->fresh()?->dispatch_status?->value,
        ]);
    }
}
