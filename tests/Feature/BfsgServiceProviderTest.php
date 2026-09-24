<?php

namespace ItsJustVita\LaravelBfsg\Tests\Feature;

use ItsJustVita\LaravelBfsg\AnalysisResult;
use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\BfsgServiceProvider;
use ItsJustVita\LaravelBfsg\Commands\BfsgCheckCommand;
use ItsJustVita\LaravelBfsg\Commands\BfsgHistoryCommand;
use ItsJustVita\LaravelBfsg\Commands\McpServerCommand;
use ItsJustVita\LaravelBfsg\Mcp\Tools\AnalyzeHtml;
use ItsJustVita\LaravelBfsg\Mcp\Tools\AnalyzeUrl;
use ItsJustVita\LaravelBfsg\Mcp\Tools\CheckContrast;
use ItsJustVita\LaravelBfsg\Mcp\Tools\GenerateReport;
use ItsJustVita\LaravelBfsg\Mcp\Tools\GetHistory;
use ItsJustVita\LaravelBfsg\Mcp\Tools\GetReport;
use ItsJustVita\LaravelBfsg\Mcp\Tools\ListAnalyzers;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

class BfsgServiceProviderTest extends TestCase
{
    public function test_bfsg_is_registered_as_singleton(): void
    {
        $instance1 = app('bfsg');
        $instance2 = app('bfsg');

        $this->assertInstanceOf(Bfsg::class, $instance1);
        $this->assertSame($instance1, $instance2);
    }

    public function test_config_is_merged(): void
    {
        $this->assertNotNull(config('bfsg'));
        $this->assertEquals('AA', config('bfsg.compliance_level'));
    }

    public function test_checks_config_has_all_analyzers(): void
    {
        $checks = config('bfsg.checks');

        $expectedKeys = [
            'images',
            'forms',
            'headings',
            'contrast',
            'aria',
            'links',
            'keyboard',
            'language',
            'tables',
            'media',
            'semantic',
            'page_title',
            'input_purpose',
            'focus',
            'error_handling',
            'status_messages',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $checks, "Missing check key: {$key}");
        }
    }

    public function test_commands_are_registered(): void
    {
        $registeredCommands = array_keys(\Artisan::all());

        $this->assertContains('bfsg:check', $registeredCommands);
        $this->assertContains('bfsg:history', $registeredCommands);
        $this->assertNotContains('bfsg:analyze', $registeredCommands, 'replaced by bfsg:check --browser');
    }

    public function test_facade_resolves(): void
    {
        $html = '<html lang="en"><body><h1>Test</h1></body></html>';

        $result = \ItsJustVita\LaravelBfsg\Facades\Bfsg::analyze($html);

        $this->assertInstanceOf(AnalysisResult::class, $result);
    }

    public function test_the_mcp_server_command_needs_laravel_mcp(): void
    {
        $withoutMcp = new class($this->app) extends BfsgServiceProvider
        {
            protected function mcpAvailable(): bool
            {
                return false;
            }
        };

        $this->assertSame([BfsgCheckCommand::class, BfsgHistoryCommand::class], $withoutMcp->commandClasses());
        $this->assertSame([BfsgCheckCommand::class, BfsgHistoryCommand::class, McpServerCommand::class], (new BfsgServiceProvider($this->app))->commandClasses());
        $this->assertContains('bfsg:mcp-server', array_keys(\Artisan::all()));
    }

    /** The Boost stub class would otherwise stay defined for every later test of the process. */
    #[RunInSeparateProcess]
    public function test_boost_receives_the_mcp_tools_when_installed(): void
    {
        require_once __DIR__.'/../Support/Stubs/BoostServiceProvider.php';
        config()->set('boost.mcp.tools.include', ['App\\Mcp\\ExistingTool']);

        (new BfsgServiceProvider($this->app))->boot();

        $this->assertSame(['App\\Mcp\\ExistingTool', AnalyzeHtml::class, AnalyzeUrl::class, CheckContrast::class, ListAnalyzers::class, GetHistory::class, GetReport::class, GenerateReport::class], config('boost.mcp.tools.include'));
    }
}
