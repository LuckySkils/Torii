<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\RuleState;
use App\Mcp\Data\ShowData;
use App\Models\Show;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_shows')]
#[Description(<<<'TEXT'
    Torii's shows: the SubsPlease side, one per show name SubsPlease has released, which is what Torii tracks and downloads. Not the AniList catalog (use search_anime for that). Each row: id, name, isTracked, ruleState (state of its qBittorrent RSS download rule: none, pending, synced, disabled, error), trackingMode (rule: new episodes download automatically; batch: a finished show downloaded as a batch; null when untracked), latestEpisode, queuedCount (releases sent to qBittorrent), downloadedCount (releases qBittorrent finished), animeId (the linked anime, or null) and hasSuggestions (unreviewed anime link suggestions exist). Ordered by name. Use get_show for one show's releases. Read-only.
    TEXT)]
#[IsReadOnly]
final class ListShows extends ToriiTool
{
    private const DEFAULT_LIMIT = 20;

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Substring of the SubsPlease show name, case-insensitive.'),
            'tracked' => $schema->string()->enum(['all', 'tracked', 'untracked'])->description('Default all.'),
            'rule_state' => $schema->string()->enum(array_column(RuleState::cases(), 'value'))->description('Only shows whose download rule is in this state.'),
            'has_suggestions' => $schema->boolean()->description('true: only shows with anime link suggestions awaiting review; false: only shows without.'),
            ...$this->pagingSchema($schema, self::DEFAULT_LIMIT),
        ];
    }

    public function handle(Request $request): Response
    {
        $search = trim((string) $request->get('query'));
        $tracked = $request->get('tracked');
        $ruleState = RuleState::tryFrom((string) $request->get('rule_state'));
        $hasSuggestions = $request->get('has_suggestions');

        $query = Show::query()
            ->when($search !== '', fn ($q) => $q->whereLike('name', '%'.addcslashes($search, '\\%_').'%'))
            ->when($tracked === 'tracked', fn ($q) => $q->where('is_tracked', true))
            ->when($tracked === 'untracked', fn ($q) => $q->where('is_tracked', false))
            ->when($ruleState !== null, fn ($q) => $q->where('rule_state', $ruleState))
            ->when($hasSuggestions === true, fn ($q) => $q->whereHas('animeSuggestions'))
            ->when($hasSuggestions === false, fn ($q) => $q->whereDoesntHave('animeSuggestions'));

        $total = (clone $query)->count();
        $offset = $this->offset($request);

        $shows = ShowData::withRowData($query)
            ->orderBy('name')
            ->orderBy('id')
            ->offset($offset)
            ->limit($this->limit($request, self::DEFAULT_LIMIT))
            ->get();

        return $this->json([
            'total' => $total,
            'offset' => $offset,
            'results' => $shows->map(fn (Show $show) => ShowData::row($show))->all(),
        ]);
    }
}
