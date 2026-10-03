<?php

namespace App\Providers;

use App\Contracts\DownloadDriver;
use App\Contracts\MetadataProvider;
use App\Contracts\Notifier;
use App\Services\Metadata\AniList\AniListProvider;
use App\Services\Metadata\MetadataProviders;
use App\Services\Notifications\NtfyNotifier;
use App\Services\Notifications\NullNotifier;
use App\Services\QBittorrent\RulesDriver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        $this->app->singleton(MetadataProviders::class, fn () => new MetadataProviders([
            $this->app->make(AniListProvider::class),
        ]));

        $this->app->bind(MetadataProvider::class, fn () => $this->app->make(MetadataProviders::class)
            ->get((string) config('subtracker.metadata.provider')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // MCP endpoint (§15): enough for an agent, not enough for a loop to hammer the database.
        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute((int) config('subtracker.mcp.rate_limit_per_minute'))
            ->by($request->ip()));
    }
}
