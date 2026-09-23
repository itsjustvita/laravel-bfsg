<?php

namespace ItsJustVita\LaravelBfsg;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
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
use ItsJustVita\LaravelBfsg\Middleware\CheckAccessibility;
use Laravel\Boost\BoostServiceProvider;
use Laravel\Mcp\Server;

class BfsgServiceProvider extends ServiceProvider
{
    /** @return list<class-string> bfsg:check, bfsg:history, and bfsg:mcp-server when laravel/mcp is installed */
    public function commandClasses(): array
    {
        return $this->mcpAvailable()
            ? [BfsgCheckCommand::class, BfsgHistoryCommand::class, McpServerCommand::class]
            : [BfsgCheckCommand::class, BfsgHistoryCommand::class];
    }

    /** laravel/mcp is an optional dependency (composer suggest). */
    protected function mcpAvailable(): bool
    {
        return class_exists(Server::class);
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge config file
        $this->mergeConfigFrom(
            __DIR__.'/../config/bfsg.php', 'bfsg'
        );

        // One registry per application; settings other than bfsg.checks are read live (see Bfsg::__construct)
        $this->app->singleton(Bfsg::class, fn ($app) => new Bfsg($app));
        $this->app->alias(Bfsg::class, 'bfsg');
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

            // Publish translations
            $this->publishes([
                __DIR__.'/../lang' => $this->app->langPath('vendor/bfsg'),
            ], 'bfsg-lang');

            $this->commands($this->commandClasses());
        }

        // Load migrations
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Load views
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'bfsg');

        // Load translations
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'bfsg');

        // Register the middleware alias
        $this->app['router']->aliasMiddleware('bfsg', CheckAccessibility::class);

        // <x-bfsg-accessible-image>
        Blade::component('bfsg-accessible-image', AccessibleImage::class);

        // Auto-register MCP tools with Laravel Boost if available
        if (class_exists(BoostServiceProvider::class) && $this->mcpAvailable()) {
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
