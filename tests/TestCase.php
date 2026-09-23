<?php

namespace ItsJustVita\LaravelBfsg\Tests;

use Barryvdh\DomPDF\ServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use ItsJustVita\LaravelBfsg\BfsgServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // No test may reach the network or spawn a real process (node/Playwright); tests fake what they need.
        Http::preventStrayRequests();
        Process::fake();
        $this->app->setLocale('en');
    }

    protected function getPackageProviders($app)
    {
        return [
            BfsgServiceProvider::class,
            ServiceProvider::class,
            // laravel/mcp is optional (suggest); its provider resolves the tool Request for the MCP testing API
            ...(class_exists(McpServiceProvider::class) ? [McpServiceProvider::class] : []),
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing.foreign_key_constraints', true);
    }
}
