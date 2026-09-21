<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\DownloadDriver;
use Illuminate\Console\Command;

class QbitReconcile extends Command
{
    protected $signature = 'qbit:reconcile';

    protected $description = 'Reconcile qBittorrent RSS rules with the app\'s tracked shows';

    public function handle(DownloadDriver $driver): int
    {
        $report = $driver->reconcile();

        $this->info('Created: '.count($report->created));
        foreach ($report->created as $ruleName) {
            $this->line("  + {$ruleName}");
        }

        $this->info('Updated: '.count($report->updated));
        foreach ($report->updated as $ruleName) {
            $this->line("  ~ {$ruleName}");
        }

        $this->info('Disabled: '.count($report->disabled));
        foreach ($report->disabled as $ruleName) {
            $this->line("  - {$ruleName}");
        }

        $this->info('Orphaned (left alone): '.count($report->orphaned));
        foreach ($report->orphaned as $ruleName) {
            $this->line("  ? {$ruleName}");
        }

        return self::SUCCESS;
    }
}
