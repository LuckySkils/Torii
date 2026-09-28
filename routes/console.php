<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('feed:poll')->everyMinute()->withoutOverlapping();
Schedule::command('qbit:reconcile')->hourly();
Schedule::command('qbit:check-completed')->everyMinute()->withoutOverlapping();

// Anime metadata (§9.4). No webhooks exist in this space, so polling it is. The
// weekly job covers the previous, current and next season plus every linked anime.
Schedule::command('anime:sync-season --weekly')->weeklyOn(1, '04:00');
Schedule::command('anime:sync-airings')->dailyAt('04:30');
