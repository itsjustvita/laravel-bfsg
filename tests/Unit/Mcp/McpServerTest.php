<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit\Mcp;

use ItsJustVita\LaravelBfsg\Mcp\BfsgMcpServer;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use Laravel\Mcp\Server\Registrar;
use Laravel\Mcp\Server\Tool;
use ReflectionProperty;

class McpServerTest extends TestCase
{
    public function test_tools_use_the_documented_snake_case_names(): void
    {
        $tools = (new ReflectionProperty(BfsgMcpServer::class, 'tools'))->getDefaultValue();

        $names = array_map(fn (string $class): string => (new $class)->name(), $tools);

        $this->assertSame([
            'analyze_html',
            'analyze_url',
            'check_contrast',
            'list_analyzers',
            'get_history',
            'get_report',
            'generate_report',
        ], $names);

        foreach ($tools as $class) {
            $this->assertInstanceOf(Tool::class, new $class);
        }
    }

    public function test_command_starts_the_server_through_the_laravel_mcp_registrar(): void
    {
        $started = [];

        $registrar = new class($started) extends Registrar
        {
            /** @param array<int, string> $started */
            public function __construct(private array &$started) {}

            public function local(string $handle, string $serverClass): void
            {
                $this->started[] = "registered:{$handle}:{$serverClass}";

                $this->localServers[$handle] = function () use ($handle): void {
                    $this->started[] = "started:{$handle}";
                };
            }
        };

        $this->app->instance(Registrar::class, $registrar);

        $this->artisan('bfsg:mcp-server')->assertExitCode(0);

        $this->assertSame([
            'registered:bfsg:'.BfsgMcpServer::class,
            'started:bfsg',
        ], $started);
    }

    public function test_command_reuses_a_server_the_app_registered_under_the_bfsg_handle(): void
    {
        $started = [];

        $registrar = new class($started) extends Registrar
        {
            /** @param array<int, string> $started */
            public function __construct(private array &$started) {}

            public function local(string $handle, string $serverClass): void
            {
                $this->started[] = "registered:{$handle}";
            }

            public function getLocalServer(string $handle): ?callable
            {
                return function () use ($handle): void {
                    $this->started[] = "app-server:{$handle}";
                };
            }
        };

        $this->app->instance(Registrar::class, $registrar);

        $this->artisan('bfsg:mcp-server')->assertExitCode(0);

        $this->assertSame(['app-server:bfsg'], $started);
    }
}
