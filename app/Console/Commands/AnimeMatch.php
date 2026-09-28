<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\MatchShowsToAnime;
use App\Models\Show;
use App\Services\Metadata\Matching\MatchDecision;
use App\Services\Metadata\Matching\MatchOutcome;
use App\Services\Metadata\Matching\ShowAnimeLinker;
use Illuminate\Console\Command;

class AnimeMatch extends Command
{
    protected $signature = 'anime:match
        {--dry-run : Print the proposed links and scores without writing anything}
        {--show= : Only this show (id or exact name)}';

    protected $description = 'Automatically link SubsPlease shows to anime metadata (local, no API calls)';

    public function handle(ShowAnimeLinker $linker): int
    {
        $showId = null;

        if ($this->option('show') !== null) {
            $option = (string) $this->option('show');
            $showId = Show::query()
                ->when(ctype_digit($option), fn ($query) => $query->whereKey((int) $option), fn ($query) => $query->where('name', $option))
                ->value('id');

            if ($showId === null) {
                $this->error("No show [{$option}].");

                return self::FAILURE;
            }
        }

        if (! $this->option('dry-run')) {
            MatchShowsToAnime::dispatch($showId);
            $this->info('Queued matching'.($showId !== null ? " for show #{$showId}." : ' for all shows.'));

            return self::SUCCESS;
        }

        $decisions = $linker->plan($showId);

        $this->table(
            ['Show', 'Outcome', 'Best match', 'Score', 'Rule', 'Runner-up', 'Score'],
            array_map(fn (MatchDecision $decision) => [
                $decision->show->name,
                $decision->outcome->value.($decision->outcome === MatchOutcome::Held ? ': '.$decision->reason?->value : ''),
                $decision->best()?->candidate->label ?? '',
                $decision->best()?->score ?? '',
                $decision->best()?->rule->value ?? '',
                $decision->runnerUp()?->candidate->label ?? '',
                $decision->runnerUp()?->score ?? '',
            ], $decisions),
        );

        $counts = [];

        foreach ($decisions as $decision) {
            $counts[$decision->outcome->value] = ($counts[$decision->outcome->value] ?? 0) + 1;
        }

        $this->line(sprintf(
            '%d shows without a manual link: %d would link, %d ambiguous and %d held (would become suggestions), %d weak, %d no candidate. Nothing written (dry run).',
            count($decisions),
            $counts[MatchOutcome::Link->value] ?? 0,
            $counts[MatchOutcome::Ambiguous->value] ?? 0,
            $counts[MatchOutcome::Held->value] ?? 0,
            $counts[MatchOutcome::Weak->value] ?? 0,
            $counts[MatchOutcome::None->value] ?? 0,
        ));

        return self::SUCCESS;
    }
}
