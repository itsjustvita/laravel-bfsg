<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use ItsJustVita\LaravelBfsg\Analyzers\BaseAnalyzer;
use ItsJustVita\LaravelBfsg\Bfsg as BfsgRegistry;
use ItsJustVita\LaravelBfsg\Facades\Bfsg;
use ItsJustVita\LaravelBfsg\Http\InProcessFetcher;
use ItsJustVita\LaravelBfsg\Middleware\CheckAccessibility;
use ItsJustVita\LaravelBfsg\Models\BfsgReport;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private const BROKEN = '<html><body><img src="test.jpg"></body></html>';

    private const ACCESSIBLE = '<!DOCTYPE html><html lang="en"><head><title>Accessible Test Page</title></head><body>'
        .'<a href="#main" class="skip-link">Skip to main content</a>'
        .'<header><h1>Page Title</h1></header>'
        .'<nav><a href="/about">About us</a></nav>'
        .'<main id="main">'
        .'<p>Some accessible content.</p>'
        .'<img src="photo.jpg" alt="A descriptive alt text">'
        .'</main>'
        .'<footer><p>Footer content</p></footer>'
        .'</body></html>';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bfsg.middleware.enabled', true);
        config()->set('bfsg.middleware.log_violations', true);
        config()->set('bfsg.middleware.ignored_paths', []);
        config()->set('logging.channels.bfsg-test', ['driver' => 'monolog', 'handler' => TestHandler::class]);
        config()->set('bfsg.middleware.log_channel', 'bfsg-test');
        config()->set('app.debug', false);
    }

    /** Like the HTTP kernel: handle(), then terminate() with the returned response. */
    private function through(Request $request, SymfonyResponse $response): SymfonyResponse
    {
        $middleware = new CheckAccessibility;
        $result = $middleware->handle($request, fn () => $response);
        (new CheckAccessibility)->terminate($request, $result);

        return $result;
    }

    private function html(string $body = self::BROKEN, int $status = 200): Response
    {
        return new Response($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function logs(): TestHandler
    {
        return Log::channel('bfsg-test')->getLogger()->getHandlers()[0];
    }

    /** @return array<string, array{0: \Closure(): array{0: Request, 1: SymfonyResponse}}> */
    public static function skipped(): array
    {
        $get = fn (string $uri = '/page') => Request::create($uri, 'GET');

        return [
            'POST request' => [fn () => [Request::create('/page', 'POST'), new Response(self::BROKEN, 200, ['Content-Type' => 'text/html'])]],
            'JSON response' => [fn () => [$get(), new JsonResponse(['ok' => true])]],
            'text/plain response' => [fn () => [$get(), new Response('x', 200, ['Content-Type' => 'text/plain'])]],
            'redirect' => [fn () => [$get(), new RedirectResponse('/login')]],
            '404 page' => [fn () => [$get(), new Response(self::BROKEN, 404, ['Content-Type' => 'text/html'])]],
            'streamed response' => [fn () => [$get(), new StreamedResponse(fn () => print (self::BROKEN), 200, ['Content-Type' => 'text/html'])]],
            'binary file' => [fn () => [$get(), new BinaryFileResponse(__FILE__, 200, ['Content-Type' => 'text/html'])]],
            'plain Symfony response' => [fn () => [$get(), new SymfonyResponse(self::BROKEN, 200, ['Content-Type' => 'text/html'])]],
            'empty body' => [fn () => [$get(), new Response('  ', 200, ['Content-Type' => 'text/html'])]],
            'XMLHttpRequest' => [fn () => [tap($get(), fn ($r) => $r->headers->set('X-Requested-With', 'XMLHttpRequest')), new Response(self::BROKEN, 200, ['Content-Type' => 'text/html'])]],
            'Livewire update' => [fn () => [tap($get(), fn ($r) => $r->headers->set('X-Livewire', 'true')), new Response(self::BROKEN, 200, ['Content-Type' => 'text/html'])]],
            'Inertia visit' => [fn () => [tap($get(), fn ($r) => $r->headers->set('X-Inertia', 'true')), new Response(self::BROKEN, 200, ['Content-Type' => 'text/html'])]],
            'in-process check by bfsg:check' => [fn () => [tap($get(), fn ($r) => $r->attributes->set(InProcessFetcher::SKIP_ATTRIBUTE, true)), new Response(self::BROKEN, 200, ['Content-Type' => 'text/html'])]],
            'ignored path' => [fn () => [$get('/admin/dashboard'), new Response(self::BROKEN, 200, ['Content-Type' => 'text/html'])]],
        ];
    }

    #[DataProvider('skipped')]
    public function test_skips(\Closure $case): void
    {
        config()->set('app.debug', true);
        config()->set('bfsg.middleware.ignored_paths', ['admin/*']);
        config()->set('bfsg.reporting.save_to_database', true);
        [$request, $response] = $case();

        $result = $this->through($request, $response);

        $this->assertSame($response, $result);
        $this->assertFalse($result->headers->has('X-BFSG-Violations'));
        $this->assertSame([], $this->logs()->getRecords());
        $this->assertSame(0, BfsgReport::query()->count());
    }

    public function test_disabled_or_missing_config_skips(): void
    {
        config()->set('app.debug', true);
        config()->set('bfsg.middleware.enabled', false);
        $this->assertFalse($this->through(Request::create('/page'), $this->html())->headers->has('X-BFSG-Violations'));

        config()->set('bfsg.middleware', ['ignored_paths' => []]);
        $this->assertFalse($this->through(Request::create('/page'), $this->html())->headers->has('X-BFSG-Violations'));
    }

    public function test_analysis_runs_after_the_response_when_not_debugging(): void
    {
        $handled = (new CheckAccessibility)->handle($request = Request::create('/page'), fn () => $this->html());

        $this->assertFalse($handled->headers->has('X-BFSG-Violations'));
        $this->assertSame([], $this->logs()->getRecords(), 'nothing analyzed in handle()');
        $this->assertTrue($request->attributes->get(CheckAccessibility::PENDING));

        (new CheckAccessibility)->terminate($request, $handled);

        $this->assertTrue($this->logs()->hasWarningThatContains('BFSG: '));
        $this->assertNull($request->attributes->get(CheckAccessibility::PENDING));
    }

    public function test_debug_mode_sets_the_header_and_analyzes_once(): void
    {
        config()->set('app.debug', true);
        $counter = new class extends BaseAnalyzer
        {
            public int $runs = 0;

            protected string $key = 'counter';

            protected function inspect(): void
            {
                $this->runs++;
            }
        };
        app(BfsgRegistry::class)->register('counter', $counter);

        $response = $this->through(Request::create('/page'), $this->html());

        $this->assertGreaterThan(0, (int) $response->headers->get('X-BFSG-Violations'));
        $this->assertSame(1, $counter->runs, 'terminate() reuses the result of handle()');
        $this->assertCount(1, $this->logs()->getRecords());
    }

    public function test_accessible_pages_get_no_header_and_no_log_line(): void
    {
        config()->set('app.debug', true);

        $response = $this->through(Request::create('/page'), $this->html(self::ACCESSIBLE));

        $this->assertFalse($response->headers->has('X-BFSG-Violations'));
        $this->assertSame([], $this->logs()->getRecords());
    }

    public function test_the_log_line_carries_counts_only_on_the_configured_channel(): void
    {
        $this->through(Request::create('/page?x=1'), $this->html());

        $records = $this->logs()->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('WARNING', $records[0]->level->getName());
        $this->assertMatchesRegularExpression('#^BFSG: \d+ violations on http://localhost/page\?x=1$#', $records[0]->message);
        $this->assertSame(['errors', 'warnings', 'notices'], array_keys($records[0]->context));
        $this->assertGreaterThan(0, $records[0]->context['errors']);
    }

    public function test_notice_only_pages_are_logged_at_info_level(): void
    {
        $notice = str_replace('</main>', '<a href="https://partner.example/" target="_blank" rel="noopener">Partner site of our company</a></main>', self::ACCESSIBLE);

        $this->through(Request::create('/page'), $this->html($notice));

        $this->assertTrue($this->logs()->hasInfoThatContains('BFSG: 1 violations on'));
    }

    public function test_logging_can_be_switched_off(): void
    {
        config()->set('bfsg.middleware.log_violations', false);

        $this->through(Request::create('/page'), $this->html());

        $this->assertSame([], $this->logs()->getRecords());
    }

    public function test_every_analyzed_page_is_stored_when_enabled_including_clean_ones(): void
    {
        config()->set('bfsg.reporting.save_to_database', true);

        $this->through(Request::create('/broken'), $this->html());
        $this->through(Request::create('/clean'), $this->html(self::ACCESSIBLE));

        $this->assertSame(['http://localhost/broken', 'http://localhost/clean'], BfsgReport::query()->orderBy('id')->pluck('url')->all());
        $this->assertSame(['middleware', 'middleware'], BfsgReport::query()->orderBy('id')->get()->map(fn ($r) => $r->metadata['source'])->all());
        $this->assertSame(0, BfsgReport::query()->where('url', 'http://localhost/clean')->sole()->total_violations);
    }

    public function test_failures_are_logged_and_never_break_the_response(): void
    {
        config()->set('bfsg.reporting.save_to_database', true);
        config()->set('bfsg.middleware.log_violations', false);
        Schema::drop('bfsg_violations');
        Log::shouldReceive('error')->once()->withArgs(fn ($message) => str_starts_with($message, 'BFSG: accessibility analysis failed for http://localhost/page'));

        $response = $this->html();
        $this->assertSame($response, $this->through(Request::create('/page'), $response));
    }

    public function test_a_failed_debug_analysis_is_not_run_again_in_terminate(): void
    {
        config()->set('app.debug', true);
        Bfsg::shouldReceive('analyze')->once()->andThrow(new RuntimeException('analyzer exploded'));
        Log::shouldReceive('error')->once()->withArgs(fn ($message) => str_contains($message, 'BFSG'));

        $response = $this->html();
        $this->assertSame($response, $this->through(Request::create('/page'), $response));
    }

    public function test_an_exploding_analyzer_never_breaks_the_response(): void
    {
        config()->set('app.debug', true);
        Bfsg::shouldReceive('analyze')->andThrow(new RuntimeException('analyzer exploded'));
        Log::shouldReceive('error')->twice()->withArgs(fn ($message) => str_contains($message, 'BFSG'));

        $response = $this->html();
        $request = Request::create('/page');

        $this->assertSame($response, (new CheckAccessibility)->handle($request, fn () => $response));
        $this->assertFalse($response->headers->has('X-BFSG-Violations'));

        config()->set('app.debug', false);
        $request = Request::create('/page');
        (new CheckAccessibility)->terminate($request, (new CheckAccessibility)->handle($request, fn () => $response));
    }
}
