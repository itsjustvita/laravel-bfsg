<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit\Mcp;

use Closure;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelBfsg\AnalysisResult;
use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Mcp\BfsgMcpServer;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use Laravel\Mcp\Server\Registrar;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use ReflectionProperty;
use RuntimeException;

class McpServerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->requireMcp();
    }

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

    public function test_server_survives_a_tool_exception(): void
    {
        // RT1: with app.debug on, laravel/mcp re-throws what a tool throws, which ended the stdio loop
        config()->set('app.debug', true);
        Exceptions::fake();
        $this->app->instance(Bfsg::class, new class extends Bfsg
        {
            public function analyze(string $html, array $options = []): AnalysisResult
            {
                throw new RuntimeException('kaputt');
            }
        });

        $lines = $this->serve([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'analyze_html', 'arguments' => ['html' => '<p>x</p>']]],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
        ]);

        $this->assertCount(2, $lines);
        $call = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        $list = json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $call['id']);
        $this->assertTrue($call['result']['isError']);
        $this->assertSame('Analysis failed: kaputt', $call['result']['content'][0]['text']);
        $this->assertSame(2, $list['id']);
        $this->assertContains('analyze_html', array_column($list['result']['tools'], 'name'));
    }

    public function test_error_with_invalid_utf8_is_scrubbed(): void
    {
        // RT5: an error text with invalid UTF-8 made the JSON-RPC encoder fail, and the server wrote an empty line
        config()->set('bfsg.mcp.allowed_hosts', ['example.com']);
        Http::fake(fn () => Http::response('{}', 200, ['Content-Type' => "application/x-caf\xe9"]));

        $lines = $this->serve([
            ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'analyze_url', 'arguments' => ['url' => 'https://example.com/api']]],
        ]);

        $this->assertCount(1, $lines);
        $this->assertNotSame('', $lines[0]);
        $response = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        $text = $response['result']['content'][0]['text'];

        $this->assertSame(7, $response['id']);
        $this->assertTrue($response['result']['isError']);
        $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
        $this->assertStringStartsWith('https://example.com/api is not an HTML page (Content-Type: application/x-caf', $text);
    }

    /**
     * Feeds JSON-RPC lines to a started server the way the stdio transport does and returns the lines it wrote.
     *
     * @param  list<array<string, mixed>>  $messages
     * @return list<string>
     */
    private function serve(array $messages): array
    {
        $transport = new class extends FakeTransporter
        {
            /** @var list<string> */
            public array $sent = [];

            public ?Closure $handler = null;

            public function onReceive(Closure $handler): void
            {
                $this->handler = $handler;
            }

            // laravel/mcp 0.6 passes a session id, 1.x does not
            public function send(string $message, ?string $sessionId = null): void
            {
                $this->sent[] = $message;
            }

            public function stream(Closure $stream): void
            {
                $stream();
            }
        };

        (new BfsgMcpServer($transport))->start();

        foreach ($messages as $message) {
            ($transport->handler)(json_encode($message, JSON_THROW_ON_ERROR)."\n");
        }

        return $transport->sent;
    }
}
