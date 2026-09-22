<?php

namespace App\Providers;

use App\Contracts\DownloadDriver;
use App\Contracts\Notifier;
use App\Services\Notifications\NtfyNotifier;
use App\Services\Notifications\NullNotifier;
use App\Services\QBittorrent\RulesDriver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DownloadDriver::class, function () {
            return match (config('subtracker.qbittorrent.mode')) {
                'rules' => $this->app->make(RulesDriver::class),
                default => throw new \RuntimeException(
                    'Unsupported QBIT_MODE ['.config('subtracker.qbittorrent.mode').'].',
                ),
            };
        });

        $this->app->bind(Notifier::class, function () {
            return config('subtracker.notifications.enabled')
                ? $this->app->make(NtfyNotifier::class)
                : $this->app->make(NullNotifier::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
