<?php

namespace App\Providers;

use App\Services\DuckLake\DuckLakeConnectionFactory;
use Illuminate\Support\ServiceProvider;
use Saturio\DuckDB\DuckDB;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(DuckLakeConnectionFactory::class, fn () => new DuckLakeConnectionFactory(
            config('duckdb'),
        ));

        // Lazy singleton — connect()/ATTACH only runs on first resolution,
        // not at container boot.
        $this->app->singleton(DuckDB::class, fn ($app) => $app->make(DuckLakeConnectionFactory::class)->connect());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
