<?php

declare(strict_types=1);

namespace App\Services\Feed;

use App\Enums\PremiereSource;
use App\Models\Release;
use App\Models\Show;
use App\Services\Premiere\PremiereCalculator;
use Illuminate\Support\Collection;

/**
 * Recomputes published_at for every release from its raw feed string, so the
 * result depends only on (raw, first_seen_at, offset) and re-running is a no-op.
 */
final class PublishedAtFixer
{
    public function __construct(
        private readonly PubDateCorrector $corrector,
        private readonly PremiereCalculator $premiereCalculator,
    ) {}

    /**
     * @return array{rows: int, reconstructed: int, changed: int, keptLiteral: int, showsRecomputed: int}
     */
    public function run(): array
    {
        $stats = ['rows' => 0, 'reconstructed' => 0, 'changed' => 0, 'keptLiteral' => 0, 'showsRecomputed' => 0];

        Release::query()->chunkById(500, function (Collection $releases) use (&$stats) {
            foreach ($releases as $release) {
                /** @var Release $release */
                $stats['rows']++;

                $raw = $release->published_at_raw;
                $updates = [];

                if ($raw === null) {
                    // Before published_at_raw existed, published_at held the feed's
                    // wall clock verbatim, so the raw string is recoverable from it.
                    $raw = $this->corrector->rawFromLiteral($release->published_at);
                    $updates['published_at_raw'] = $raw;
                    $stats['reconstructed']++;
                }

                $publishedAt = $this->corrector->correct($raw, $release->first_seen_at, $release->title);

                if ($this->corrector->offsetMinutes() !== 0 && $publishedAt->equalTo($this->corrector->literal($raw))) {
                    $stats['keptLiteral']++;
                }

                if (! $publishedAt->equalTo($release->published_at)) {
                    $updates['published_at'] = $publishedAt;
                    $stats['changed']++;
                }

                if ($updates !== []) {
                    $release->update($updates);
                }
            }
        });

        Show::query()
            ->whereIn('premiere_source', [PremiereSource::Episode1, PremiereSource::EarliestSeen])
            ->each(function (Show $show) use (&$stats) {
                $before = $show->premiered_at;

                $this->premiereCalculator->apply($show, $this->premiereCalculator->fromEpisode1OrEarliest($show));

                if ($before === null || ! $before->equalTo($show->premiered_at)) {
                    $stats['showsRecomputed']++;
                }
            });

        return $stats;
    }
}
