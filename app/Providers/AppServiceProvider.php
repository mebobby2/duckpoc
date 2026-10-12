<?php

namespace App\Providers;

use App\Services\DuckLake\DuckLakeConnectionFactory;
use App\Services\Insights\DuckDB\FarmPlan;
use App\Services\Mongo\MongoConnectionFactory;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
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

        // Same reasoning for the Mongo baseline: the driver does server
        // discovery and opens a pool on first use, and charging every
        // measurement for that would flatter the engines it is compared
        // against.
        $this->app->singleton(MongoConnectionFactory::class);

        $this->app->singleton(FarmPlan::class, fn () => FarmPlan::forFarm(
            (int) (config('insights.mcp_farm') ?? throw new RuntimeException('Set INSIGHTS_MCP_FARM to the farm this server answers for.')),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
