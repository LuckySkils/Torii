<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Data\AnimeData;
use App\Models\Anime;
use App\Models\Show;
use App\Models\ShowAnimeRejection;
use App\Models\ShowAnimeSuggestion;
use App\Services\Metadata\Matching\ScoredCandidate;
use App\Services\Metadata\Matching\ShowAnimeLinker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('suggest_link')]
#[Description(<<<'TEXT'
    Which anime a Torii show could be linked to, according to Torii's title matcher, for explaining an unlinked or doubtful show. This tool never changes anything: it does not link, suggest or reject. Linking is done by the user in Torii's web UI, and no tool can do it. Returns: the current link (if any); outcome — what automatic matching would decide now: "link" (a single clear match), "ambiguous" (two candidates within 10 points), "held" (a clear match blocked because the anime has fewer episodes than SubsPlease has released, or because the user rejected a pair for this show), "weak" (best score 70-89) or "none"; candidates scoring 70 or more with score (0-100) and rule (exact, subtitle_prefix, season_marker, similarity); the stored pending suggestions with their reason; and the anime the user has rejected for this show.
    TEXT)]
#[IsReadOnly]
final class SuggestLink extends ToriiTool
{
    public function __construct(private readonly ShowAnimeLinker $linker) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'show_id' => $schema->integer()->description('Torii show id.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate(['show_id' => ['required', 'integer']]);
        $show = Show::with('animeLink')->find((int) $request->get('show_id'));

        if ($show === null) {
            return Response::error("No show with id {$request->get('show_id')}.");
        }

        $decision = $this->linker->decisionFor($show);
        $suggestions = ShowAnimeSuggestion::where('show_id', $show->id)->orderByDesc('score')->get();
        $rejections = ShowAnimeRejection::where('show_id', $show->id)->orderBy('rejected_at')->get();

        $animeIds = array_unique([
            ...array_map(fn (ScoredCandidate $c) => $c->candidate->animeId, $decision->candidates),
            ...$suggestions->pluck('anime_id'),
            ...$rejections->pluck('anime_id'),
            ...array_filter([$show->animeLink?->anime_id]),
        ]);
        $anime = Anime::query()->whereKey($animeIds)->select(AnimeData::SUMMARY_COLUMNS)->with(AnimeData::summaryRelations())->get()->keyBy('id');
        $summary = fn (int $id): ?array => $anime->has($id) ? AnimeData::summary($anime[$id]) : null;

        return $this->json([
            'show' => ['id' => $show->id, 'name' => $show->name, 'isTracked' => $show->is_tracked],
            'currentLink' => $show->animeLink === null ? null : [
                'anime' => $summary($show->animeLink->anime_id),
                'linkSource' => $show->animeLink->source->value,
                'confidence' => $show->animeLink->confidence,
            ],
            'outcome' => $decision->outcome->value,
            'candidates' => array_map(fn (ScoredCandidate $c) => [
                'anime' => $summary($c->candidate->animeId),
                'score' => $c->score,
                'rule' => $c->rule->value,
            ], $decision->candidates),
            'pendingSuggestions' => $suggestions->map(fn (ShowAnimeSuggestion $s) => [
                'anime' => $summary($s->anime_id),
                'score' => $s->score,
                'rule' => $s->rule->value,
                'reason' => $s->reason->value,
            ])->values()->all(),
            'rejected' => $rejections->map(fn (ShowAnimeRejection $r) => [
                'anime' => $summary($r->anime_id),
                'rejectedAt' => $r->rejected_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }
}
