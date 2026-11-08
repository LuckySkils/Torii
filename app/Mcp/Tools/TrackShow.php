<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\ShowNotTrackable;
use App\Actions\TrackShow as TrackShowAction;
use App\Models\Show;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('track_show')]
#[Description(<<<'TEXT'
    CHANGES STATE: starts tracking a Torii show, exactly like the Track button in Torii's web UI, and starts downloads immediately. Torii queues every already-released episode it hasn't sent to qBittorrent yet (where SubsPlease has a batch, the batch replaces the episodes it covers) and, unless a batch covers everything released, creates a qBittorrent RSS rule so new episodes download automatically. Calling it on a show that is already tracked runs the same step again, which queues anything not yet sent. A show with no SubsPlease release (only releases imported by hand from other groups; list_shows/get_show say trackable: false) can't be tracked, since the rule follows SubsPlease's feed: the call is refused with the reason and nothing changes. Only call it when the user has asked to track or download this show. Returns: wasTracked, trackingMode (rule: new episodes download automatically; batch: downloaded as a batch, no rule), ruleCreated, ruleState (pending until the background job has synced the rule with qBittorrent), releasesQueued (how many releases were handed to the background job that sends them to qBittorrent) and their episodes.
    TEXT)]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsOpenWorld]
final class TrackShow extends WriteTool
{
    public function __construct(private readonly TrackShowAction $trackShow) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'show_id' => $schema->integer()->description('Torii show id (from list_shows, tracked_summary, or showId in anime results).')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate(['show_id' => ['required', 'integer']]);
        $show = Show::find((int) $request->get('show_id'));

        if ($show === null) {
            return $this->failed($request, "No show with id {$request->get('show_id')}.");
        }

        $wasTracked = $show->is_tracked;

        try {
            $plan = ($this->trackShow)($show);
        } catch (ShowNotTrackable $e) {
            return $this->failed($request, "Can't track \"{$show->name}\". {$e->getMessage()}");
        }

        $show->refresh();

        return $this->done($request, [
            'show' => ['id' => $show->id, 'name' => $show->name],
            'wasTracked' => $wasTracked,
            'isTracked' => $show->is_tracked,
            'trackingMode' => $plan->trackingMode->value,
            'ruleCreated' => $plan->createRule,
            'ruleState' => $show->rule_state->value,
            'releasesQueued' => count($plan->releaseIds),
            'queuedEpisodes' => $show->releases()->whereKey($plan->releaseIds)->orderBy('published_at')->get(['id', 'episode', 'is_batch', 'batch_from', 'batch_to'])
                ->map(fn ($release) => $release->is_batch ? "batch {$release->batch_from}-{$release->batch_to}" : $release->episode)
                ->all(),
        ]);
    }
}
