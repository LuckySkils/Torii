<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Feed\PublishedAtFixer;
use Illuminate\Console\Command;

class ReleasesFixPublished extends Command
{
    protected $signature = 'releases:fix-published';

    protected $description = 'Recompute published_at for every release from its raw feed pubDate and FEED_PUBDATE_OFFSET_MINUTES (safe to re-run)';

    public function handle(PublishedAtFixer $fixer): int
    {
        $stats = $fixer->run();

        $this->info(sprintf(
            'Checked %d releases: %d corrected, %d raw values reconstructed, %d kept literal by the safety net; premiere date changed for %d shows.',
            $stats['rows'],
            $stats['changed'],
            $stats['reconstructed'],
            $stats['keptLiteral'],
            $stats['showsRecomputed'],
        ));

        return self::SUCCESS;
    }
}
