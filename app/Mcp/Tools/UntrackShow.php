<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\UntrackShow as UntrackShowAction;
use App\Models\Show;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('untrack_show')]
#[Description(<<<'TEXT'
    CHANGES STATE: stops tracking a Torii show, exactly like the Untrack button in Torii's web UI. Its qBittorrent RSS rule is disabled (not deleted), so new episodes stop downloading automatically. Nothing already queued or downloaded is removed or cancelled. Only call it when the user has asked to stop tracking this show. Returns: wasTracked, isTracked and ruleState (pending until the background job has disabled the rule in qBittorrent, then disabled).
    TEXT)]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld]
final class UntrackShow extends WriteTool
{
    public function __construct(private readonly UntrackShowAction $untrackShow) {}

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

        $wasTracked = $show->is_tracked;
        ($this->untrackShow)($show);
        $show->refresh();

        return $this->done($request, [
            'show' => ['id' => $show->id, 'name' => $show->name],
            'wasTracked' => $wasTracked,
            'isTracked' => $show->is_tracked,
            'ruleState' => $show->rule_state->value,
        ]);
    }
}
