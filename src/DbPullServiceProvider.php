<?php

namespace DrJermy\DbPull;

use DrJermy\DbPull\Commands\PullDatabase;
use Illuminate\Support\ServiceProvider;

class DbPullServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/db-pull.php', 'db-pull');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PullDatabase::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/db-pull.php' => config_path('db-pull.php'),
            ], 'db-pull-config');
        }
    }
}
