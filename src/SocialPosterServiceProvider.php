<?php

namespace BeePost\SocialPoster;

use Illuminate\Support\ServiceProvider;

class SocialPosterServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Merge package configuration
        $this->mergeConfigFrom(
            __DIR__.'/../config/social-poster.php', 'social-poster'
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Load database migrations
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Publish configuration file
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/social-poster.php' => config_path('social-poster.php'),
            ], 'social-poster-config');
        }
    }
}
