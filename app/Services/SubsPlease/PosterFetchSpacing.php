<?php

declare(strict_types=1);

namespace App\Services\SubsPlease;

use App\Support\DispatchSpacing;

/**
 * Spaces poster fetches that arrive one at a time (ShowDiscovered fires once per
 * new show) the way images:fetch spaces its bulk dispatches, so a first poll
 * discovering 50+ shows doesn't hit SubsPlease's API in a burst: the first fetch
 * goes out immediately, each one after it within the same burst SECONDS later.
 */
final class PosterFetchSpacing
{
    /** Gap between poster fetches; images:fetch and "refresh missing" use it too. */
    public const SECONDS = 3;

    public function __construct(private readonly DispatchSpacing $spacing) {}

    /** Seconds this fetch should wait: 0 when nothing is scheduled ahead of it. */
    public function nextDelay(): int
    {
        return $this->spacing->nextDelay('posters', self::SECONDS);
    }
}
