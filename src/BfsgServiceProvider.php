<?php

namespace ItsJustVita\LaravelBfsg;

use Illuminate\Support\ServiceProvider;
use ItsJustVita\LaravelBfsg\Commands\AnalyzeUrlCommand;
use ItsJustVita\LaravelBfsg\Commands\BfsgCheckCommand;
use ItsJustVita\LaravelBfsg\Commands\BfsgHistoryCommand;
use ItsJustVita\LaravelBfsg\Commands\McpServerCommand;
use ItsJustVita\LaravelBfsg\Components\AccessibleImage;
use ItsJustVita\LaravelBfsg\Mcp\Tools\AnalyzeHtml;
use ItsJustVita\LaravelBfsg\Mcp\Tools\AnalyzeUrl;
use ItsJustVita\LaravelBfsg\Mcp\Tools\CheckContrast;
use ItsJustVita\LaravelBfsg\Mcp\Tools\GenerateReport;
use ItsJustVita\LaravelBfsg\Mcp\Tools\GetHistory;
use ItsJustVita\LaravelBfsg\Mcp\Tools\GetReport;
use ItsJustVita\LaravelBfsg\Mcp\Tools\ListAnalyzers;
use Laravel\Boost\BoostServiceProvider;

class BfsgServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge config file
        $this->mergeConfigFrom(
            __DIR__.'/../config/bfsg.php', 'bfsg'
        );

        // Register main class as singleton
        $this->app->singleton('bfsg', function ($app) {
            return new Bfsg;
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Only when the package runs in an app (not during tests)
        if ($this->app->runningInConsole()) {
            // Publish config
            $this->publishes([
                __DIR__.'/../config/bfsg.php' => config_path('bfsg.php'),
            ], 'bfsg-config');

            // Publish views
            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/bfsg'),
            ], 'bfsg-views');

            // Publish migrations
            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'bfsg-migrations');

            // Register commands
            $this->commands([
                BfsgCheckCommand::class,
                AnalyzeUrlCommand::class,
                BfsgHistoryCommand::class,
                McpServerCommand::class,
            ]);
        }

        // Load migrations
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Load views
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'bfsg');

        // Register Blade components
        $this->loadViewComponentsAs('bfsg', [
            AccessibleImage::class,
        ]);

        // Auto-register MCP tools with Laravel Boost if available
        if (class_exists(BoostServiceProvider::class)) {
            $this->app->booted(function () {
                $tools = config('boost.mcp.tools.include', []);
                $bfsgTools = [
                    AnalyzeHtml::class,
                    AnalyzeUrl::class,
                    CheckContrast::class,
                    ListAnalyzers::class,
                    GetHistory::class,
                    GetReport::class,
                    GenerateReport::class,
                ];
                config(['boost.mcp.tools.include' => array_merge($tools, $bfsgTools)]);
            });
        }
    }
}
