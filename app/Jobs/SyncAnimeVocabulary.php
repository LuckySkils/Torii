<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\MetadataProvider;
use App\Services\Metadata\AnimeTaxonomyWriter;
use App\Services\Metadata\MetadataProviderException;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Loads the provider's full genre and tag lists into `genres`/`tags` (one
 * request), so suggest_anime recognises a tag no catalog anime has yet ("time
 * travel") instead of falling through to a shorter one ("travel"). Queued by the
 * weekly sync and once by the `anime.vocabulary` bootstrap task.
 */
final class SyncAnimeVocabulary implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Below the database queue's 90s retry_after. */
    public int $timeout = 80;

    public int $maxExceptions = 3;

    /** Rate-limit releases don't count against tries; real failures do (maxExceptions). */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function handle(MetadataProvider $provider, AnimeTaxonomyWriter $writer): void
    {
        try {
            $vocabulary = $provider->vocabulary();
        } catch (MetadataProviderException $e) {
            if (! $e->rateLimited) {
                throw $e;
            }

            logger()->warning('Anime vocabulary sync rate limited; released', ['retry_after' => $e->retryAfter]);
            $this->release($e->retryAfter ?? 60);

            return;
        }

        $writer->addVocabulary($vocabulary);

        logger()->info('Anime vocabulary synced', ['genres' => count($vocabulary->genres), 'tags' => count($vocabulary->tags)]);
    }
}
