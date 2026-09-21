<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\DownloadDriver;
use App\Enums\RuleState;
use App\Models\Show;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class SyncShowRule implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 60, 120];

    public function __construct(
        public readonly Show $show,
        public readonly bool $track,
    ) {}

    public function handle(DownloadDriver $driver): void
    {
        if ($this->track) {
            $driver->track($this->show);
        } else {
            $driver->untrack($this->show);
        }

        $this->show->update([
            'rule_state' => $this->track ? RuleState::Synced : RuleState::Disabled,
            'rule_synced_at' => now(),
            'rule_error' => null,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $this->show->update([
            'rule_state' => RuleState::Error,
            'rule_error' => $exception->getMessage(),
        ]);
    }
}
