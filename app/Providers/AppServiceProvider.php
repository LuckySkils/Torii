<?php

namespace App\Providers;

use App\Contracts\DownloadDriver;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
