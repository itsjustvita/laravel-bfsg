<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit\Mcp;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelBfsg\Analyzers\BaseAnalyzer;
use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Http\FetchFailed;
use ItsJustVita\LaravelBfsg\Http\InProcessFetcher;
use ItsJustVita\LaravelBfsg\Http\PrivateNetworkGuard;
use ItsJustVita\LaravelBfsg\Http\UrlFetcher;
use ItsJustVita\LaravelBfsg\Mcp\BfsgMcpServer;
use ItsJustVita\LaravelBfsg\Mcp\Tools\AnalyzeHtml;
use ItsJustVita\LaravelBfsg\Mcp\Tools\AnalyzeUrl;
use ItsJustVita\LaravelBfsg\Mcp\Tools\CheckContrast;
use ItsJustVita\LaravelBfsg\Mcp\Tools\GenerateReport;
use ItsJustVita\LaravelBfsg\Mcp\Tools\GetHistory;
use ItsJustVita\LaravelBfsg\Mcp\Tools\GetReport;
use ItsJustVita\LaravelBfsg\Mcp\Tools\ListAnalyzers;
use ItsJustVita\LaravelBfsg\Models\BfsgReport;
use ItsJustVita\LaravelBfsg\Models\BfsgViolation;
use ItsJustVita\LaravelBfsg\Support\PackageVersion;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use PHPUnit\Framework\Attributes\DataProvider;

class McpToolsTest extends TestCase
{
    use RefreshDatabase;

    private const BROKEN = '<!DOCTYPE html><html><body><img src="hero.jpg"></body></html>';

    /** Fake DNS for the private-network guard (no real lookups in tests); unknown hosts get a public address. */
    private const DNS = [
        'localhost' => ['127.0.0.1'],
        'internal.example' => ['10.1.2.3'],
        'dual.example' => ['93.184.215.15', 'fd12:3456::1'],
        'metadata.example' => ['169.254.169.254'],
        'pinned.example' => ['93.184.215.20', '2606:4700::6810:1'],
        'other.example' => ['93.184.215.21'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (! TestResponse::hasMacro('payload')) {
            // The first content item of a tool response, decoded (content() is protected; macros run bound to the response).
            TestResponse::macro('payload', fn () => json_decode($this->content()[0] ?? 'null', true, flags: JSON_THROW_ON_ERROR));
            TestResponse::macro('text', fn () => (string) ($this->content()[0] ?? ''));
        }

        $this->app->instance(PrivateNetworkGuard::class, new PrivateNetworkGuard(fn (string $host) => self::DNS[$host] ?? ($host === 'nowhere.example' ? [] : ['93.184.215.14'])));
    }

    public function test_tools_have_snake_case_names_and_annotations(): void
    {
        $expected = [
            AnalyzeHtml::class => ['analyze_html', ['readOnlyHint' => true]],
            AnalyzeUrl::class => ['analyze_url', ['openWorldHint' => true]],
            CheckContrast::class => ['check_contrast', ['readOnlyHint' => true]],
            ListAnalyzers::class => ['list_analyzers', ['readOnlyHint' => true]],
            GetHistory::class => ['get_history', ['readOnlyHint' => true]],
            GetReport::class => ['get_report', ['readOnlyHint' => true]],
            GenerateReport::class => ['generate_report', ['openWorldHint' => true]],
        ];

        foreach ($expected as $class => [$name, $annotations]) {
            $tool = new $class;
            $this->assertSame($name, $tool->name(), $class);
            $this->assertSame($annotations, $tool->annotations(), $class);
        }
    }

    public function test_the_server_reports_the_package_version(): void
    {
        $server = new class(new FakeTransporter) extends BfsgMcpServer
        {
            public function version(): string
            {
                $this->boot();

                return $this->version;
            }
        };

        $this->assertSame(PackageVersion::get(), $server->version());
        $this->assertNotSame('2.1.0', $server->version());
    }

    public function test_analyze_html_returns_the_json_report(): void
    {
        $response = BfsgMcpServer::tool(AnalyzeHtml::class, ['html' => self::BROKEN]);

        $response->assertOk();
        $report = $response->payload();
        $this->assertSame(['url', 'package_version', 'locale', 'analyzed_at', 'analyzers', 'summary', 'violations'], array_keys($report));
        $this->assertSame('images.missing_alt', $report['violations']['images'][0]['key']);
        $this->assertLessThan(100, $report['summary']['score']);
    }

    public function test_analyze_html_honours_the_locale_and_keeps_empty_violations_an_object(): void
    {
        $german = BfsgMcpServer::tool(AnalyzeHtml::class, ['html' => self::BROKEN, 'locale' => 'de'])->payload();
        $this->assertSame('de', $german['locale']);
        $this->assertSame('Bild ohne Textalternative (hero.jpg)', $german['violations']['images'][0]['message']);

        $clean = BfsgMcpServer::tool(AnalyzeHtml::class, ['html' => '<p>Just text.</p>']);
        $this->assertStringContainsString('"violations": {}', $clean->text());
    }

    public function test_analyze_html_requires_html(): void
    {
        BfsgMcpServer::tool(AnalyzeHtml::class, [])->assertHasErrors(['The html parameter is required.']);
        BfsgMcpServer::tool(AnalyzeHtml::class, ['html' => ['not', 'a', 'string']])->assertHasErrors();
    }

    public function test_analyze_url_fetches_paths_of_this_app_in_process(): void
    {
        $this->app['router']->get('/mcp-page', fn () => response(self::BROKEN));

        $report = BfsgMcpServer::tool(AnalyzeUrl::class, ['url' => '/mcp-page'])->assertOk()->payload();

        $this->assertSame('http://localhost/mcp-page', $report['url']);
        $this->assertContains('language.missing_lang', array_column($report['violations']['language'], 'key'), 'fetched pages are full documents');
        Http::assertNothingSent();
    }

    public function test_analyze_url_uses_the_mcp_tls_setting_and_allowed_hosts(): void
    {
        $verify = null;
        Http::fake(function (HttpRequest $request, array $options) use (&$verify) {
            $verify = $options['verify'];

            return Http::response(self::BROKEN, 200);
        });
        config()->set('bfsg.mcp.verify_ssl', false);
        config()->set('bfsg.mcp.allowed_hosts', ['docs.example.com']);

        BfsgMcpServer::tool(AnalyzeUrl::class, ['url' => 'https://docs.example.com/'])->assertOk();
        $this->assertFalse($verify);

        BfsgMcpServer::tool(AnalyzeUrl::class, ['url' => 'https://intranet.example.com/'])->assertHasErrors(['not in the list of allowed hosts']);
        BfsgMcpServer::tool(AnalyzeUrl::class, ['url' => '/local'])->assertHasErrors(['not in the list of allowed hosts']);
    }

    public function test_analyze_url_reports_fetch_failures_as_tool_errors(): void
    {
        Http::fake(['https://example.com/api' => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json'])]);

        BfsgMcpServer::tool(AnalyzeUrl::class, ['url' => 'https://example.com/api'])->assertHasErrors(['not an HTML page']);
        BfsgMcpServer::tool(AnalyzeUrl::class, [])->assertHasErrors(['The url parameter is required.']);
    }

    public function test_check_contrast(): void
    {
        $good = BfsgMcpServer::tool(CheckContrast::class, ['foreground' => '#000000', 'background' => '#ffffff'])->assertOk()->payload();
        $this->assertEqualsWithDelta(21.0, $good['ratio'], 0.01);
        $this->assertTrue($good['aaa_normal']);

        $bad = BfsgMcpServer::tool(CheckContrast::class, ['foreground' => 'oklch(87.2% .01 258.338)', 'background' => 'white'])->payload();
        $this->assertFalse($bad['aa_normal']);

        BfsgMcpServer::tool(CheckContrast::class, ['foreground' => 'not-a-color', 'background' => '#fff'])->assertHasErrors(['Could not parse the colours']);
        BfsgMcpServer::tool(CheckContrast::class, ['foreground' => 123, 'background' => '#fff'])->assertHasErrors(['must be colour strings']);
    }

    public function test_list_analyzers_includes_disabled_and_custom_analyzers(): void
    {
        config()->set('bfsg.checks.contrast', false);
        $this->app->forgetInstance(Bfsg::class);
        app(Bfsg::class)->register('marquee', new class extends BaseAnalyzer
        {
            protected string $key = 'marquee';

            protected string $description = 'Moving content';

            protected array $rules = ['2.2.2'];

            protected function inspect(): void {}
        });

        $byName = array_column(BfsgMcpServer::tool(ListAnalyzers::class)->assertOk()->payload(), null, 'name');

        $this->assertSame([...array_keys(Bfsg::ANALYZERS), 'marquee'], array_keys($byName));
        $this->assertFalse($byName['contrast']['enabled']);
        $this->assertTrue($byName['images']['enabled']);
        $this->assertSame(['1.1.1'], $byName['images']['rules']);
        $this->assertSame(['2.2.2'], $byName['marquee']['rules']);
        $this->assertTrue($byName['marquee']['enabled']);
    }

    public function test_get_history_lists_reports_newest_first_with_whole_scores(): void
    {
        BfsgReport::create(['url' => 'https://a.example', 'total_violations' => 5, 'score' => 82.5, 'grade' => 'B'])->forceFill(['created_at' => '2026-01-01 10:00'])->save();
        BfsgReport::create(['url' => 'https://b.example', 'total_violations' => 0, 'score' => 100, 'grade' => 'A+'])->forceFill(['created_at' => '2026-02-01 10:00'])->save();

        $all = BfsgMcpServer::tool(GetHistory::class)->assertOk()->payload()['reports'];
        $this->assertSame(['https://b.example', 'https://a.example'], array_column($all, 'url'));
        $this->assertSame(83, $all[1]['score']);

        $this->assertCount(1, BfsgMcpServer::tool(GetHistory::class, ['url' => 'https://a.example'])->payload()['reports']);
        $this->assertCount(1, BfsgMcpServer::tool(GetHistory::class, ['limit' => 1])->payload()['reports']);
    }

    public function test_get_report_returns_findings_with_key_fingerprint_and_context(): void
    {
        $violation = BfsgViolation::factory()->create();

        $payload = BfsgMcpServer::tool(GetReport::class, ['report_id' => $violation->report_id])->assertOk()->payload();

        $this->assertSame($violation->report_id, $payload['report']['id']);
        $this->assertSame('images.missing_alt', $payload['violations'][0]['key']);
        $this->assertSame($violation->fingerprint, $payload['violations'][0]['fingerprint']);
        $this->assertSame('/html[1]/body[1]/img[1]', $payload['violations'][0]['selector']);

        BfsgMcpServer::tool(GetReport::class, ['report_id' => 999])->assertHasErrors(['Report #999 not found.']);
        BfsgMcpServer::tool(GetReport::class, ['report_id' => 'x'])->assertHasErrors(['positive integer']);
    }

    public function test_generate_report_formats_and_save(): void
    {
        $this->app['router']->get('/mcp-report', fn () => response(self::BROKEN));
        $directory = sys_get_temp_dir().'/bfsg-mcp-'.uniqid();
        config()->set('bfsg.reporting.output_path', $directory);

        $json = BfsgMcpServer::tool(GenerateReport::class, ['url' => '/mcp-report', 'save' => true])->assertOk()->payload();
        $this->assertSame('json', $json['format']);
        $this->assertSame('http://localhost/mcp-report', json_decode($json['report'], true)['url']);
        $this->assertSame('mcp', BfsgReport::query()->findOrFail($json['report_id'])->metadata['source']);

        $markdown = BfsgMcpServer::tool(GenerateReport::class, ['url' => '/mcp-report', 'format' => 'markdown', 'locale' => 'de'])->payload();
        $this->assertStringStartsWith('# Barrierefreiheitsbericht', $markdown['report']);
        $this->assertArrayNotHasKey('report_id', $markdown);

        $pdf = BfsgMcpServer::tool(GenerateReport::class, ['url' => '/mcp-report', 'format' => 'pdf'])->payload();

        try {
            $this->assertStringStartsWith('%PDF', (string) file_get_contents($pdf['path']));
        } finally {
            array_map('unlink', glob($directory.'/*'));
            rmdir($directory);
        }

        BfsgMcpServer::tool(GenerateReport::class, ['url' => '/mcp-report', 'format' => 'xml'])->assertHasErrors(['Invalid format [xml]']);
    }

    /** @return array<string, array{0: string}> */
    public static function refusedWithoutAllowList(): array
    {
        return [
            'IPv4 loopback' => ['http://127.0.0.1/'],
            'IPv4 loopback, other address' => ['http://127.8.9.10:8080/'],
            '10/8' => ['http://10.0.0.5/'],
            '172.16/12' => ['http://172.31.255.1/'],
            '192.168/16' => ['http://192.168.1.1/'],
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'link-local' => ['http://169.254.1.1/'],
            'unspecified IPv4' => ['http://0.0.0.0/'],
            'IPv6 loopback' => ['http://[::1]/'],
            'IPv6 unspecified' => ['http://[::]/'],
            'unique local' => ['http://[fd00::1]/'],
            'IPv6 link-local' => ['http://[fe80::1]/'],
            'IPv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/'],
            'name resolving to 10/8' => ['https://internal.example/'],
            'name with a private AAAA record' => ['https://dual.example/'],
            'hex loopback' => ['http://0x7f000001/'],
            'dotted hex loopback' => ['http://0x7f.0x0.0x0.0x1/'],
            'octal loopback' => ['http://017700000001/'],
            'decimal loopback' => ['http://2130706433/'],
            'hex metadata address' => ['http://0xa9fea9fe/latest/meta-data/'],
            'short loopback' => ['http://127.1/'],
            'octal dotted loopback' => ['http://0177.0.0.1/'],
            'mixed-radix private' => ['http://0xa.012.0.1/'],
            'CGNAT (Alibaba Cloud metadata)' => ['http://100.100.100.200/latest/meta-data/'],
            'benchmarking 198.18/15' => ['http://198.18.0.1/'],
            'reserved 240/4' => ['http://240.0.0.1/'],
            'IETF protocol assignments 192.0.0/24' => ['http://192.0.0.8/'],
            'documentation 203.0.113/24' => ['http://203.0.113.10/'],
            'NAT64 of the metadata address' => ['http://[64:ff9b::a9fe:a9fe]/'],
            '6to4 of the metadata address' => ['http://[2002:a9fe:a9fe::1]/'],
            'IPv4-compatible loopback' => ['http://[::127.0.0.1]/'],
            'IPv6 site-local' => ['http://[fec0::1]/'],
            'IPv6 documentation' => ['http://[2001:db8::1]/'],
        ];
    }

    #[DataProvider('refusedWithoutAllowList')]
    public function test_without_an_allow_list_private_loopback_link_local_and_unspecified_addresses_are_refused(string $url): void
    {
        Http::fake(fn () => Http::response(self::BROKEN, 200));

        BfsgMcpServer::tool(AnalyzeUrl::class, ['url' => $url])->assertHasErrors(['a non-public address']);
        BfsgMcpServer::tool(GenerateReport::class, ['url' => $url])->assertHasErrors(['a non-public address']);

        config()->set('bfsg.mcp.allowed_hosts', []);
        BfsgMcpServer::tool(AnalyzeUrl::class, ['url' => $url])->assertHasErrors(['a non-public address']);

        Http::assertNothingSent();
    }

    public function test_without_an_allow_list_public_hosts_and_this_application_are_fetched(): void
    {
        Http::fake(fn () => Http::response(self::BROKEN, 200));
        $this->app['router']->get('/mcp-page', fn () => response(self::BROKEN));

        BfsgMcpServer::tool(AnalyzeUrl::class, ['url' => 'http://172.32.0.1/'])->assertOk();
        BfsgMcpServer::tool(AnalyzeUrl::class, ['url' => 'https://docs.example.com/'])->assertOk();
        // app.url is http://localhost (resolving to 127.0.0.1): its own host is exempt, as a path and as a URL
        BfsgMcpServer::tool(AnalyzeUrl::class, ['url' => '/mcp-page'])->assertOk();
        BfsgMcpServer::tool(AnalyzeUrl::class, ['url' => 'http://localhost/mcp-page'])->assertOk();

        Http::assertSentCount(2);
    }

    public function test_only_the_origin_of_app_url_is_exempt_not_other_ports_or_schemes_of_its_host(): void
    {
        Http::fake(fn () => Http::response(self::BROKEN, 200));
        config()->set('app.url', 'http://localhost');

        BfsgMcpServer::tool(AnalyzeUrl::class, ['url' => 'http://localhost:6379/'])->assertHasErrors(['localhost resolves to 127.0.0.1']);
        BfsgMcpServer::tool(AnalyzeUrl::class, ['url' => 'https://localhost:8443/'])->assertHasErrors(['localhost resolves to 127.0.0.1']);
        BfsgMcpServer::tool(AnalyzeUrl::class, ['url' => 'https://localhost/'])->assertHasErrors(['localhost resolves to 127.0.0.1']);

        Http::assertNothingSent();
    }

    public function test_a_redirect_to_a_private_address_is_refused_without_an_allow_list(): void
    {
        Http::fake([
            'https://public.example/named' => Http::response('', 302, ['Location' => 'https://metadata.example/']),
            'https://public.example/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            '*' => Http::response(self::BROKEN, 200),
        ]);

        BfsgMcpServer::tool(AnalyzeUrl::class, ['url' => 'https://public.example/away'])->assertHasErrors(['169.254.169.254']);
        BfsgMcpServer::tool(AnalyzeUrl::class, ['url' => 'https://public.example/named'])->assertHasErrors(['metadata.example resolves to 169.254.169.254']);

        Http::assertSentCount(2);
        Http::assertNotSent(fn (HttpRequest $request) => in_array(parse_url($request->url(), PHP_URL_HOST), ['169.254.169.254', 'metadata.example'], true));
    }

    public function test_a_host_that_does_not_resolve_is_refused(): void
    {
        Http::fake(fn () => Http::response(self::BROKEN, 200));

        BfsgMcpServer::tool(AnalyzeUrl::class, ['url' => 'https://nowhere.example/'])->assertHasErrors(['nowhere.example does not resolve']);

        Http::assertNothingSent();
    }

    public function test_numeric_host_forms_are_parsed_like_inet_aton(): void
    {
        $guard = new PrivateNetworkGuard(fn () => $this->fail('numeric hosts must not be resolved'));

        foreach (['0x7f000001' => '127.0.0.1', '0x7f.0x0.0x0.0x1' => '127.0.0.1', '017700000001' => '127.0.0.1', '2130706433' => '127.0.0.1', '0xa9fea9fe' => '169.254.169.254', '127.1' => '127.0.0.1', '10.1' => '10.0.0.1', '0x08080808' => '8.8.8.8', '8.8.2056' => '8.8.8.8', '0x' => '0.0.0.0'] as $host => $ip) {
            $this->assertSame($ip, PrivateNetworkGuard::parseNumericHost($host), $host);
        }

        foreach (['cafe.de', 'example.com', '1.2.3.4.5', '08.1.1.1', '256.1.1.1', '1.2.65536', '4294967296', '0x1ffffffff', 'dead.beef'] as $host) {
            $this->assertNull(PrivateNetworkGuard::parseNumericHost($host), $host);
        }

        $this->assertSame(['8.8.8.8'], $guard->check('http://0x08080808/'));

        // Only digits, hex and dots but not a valid address: refused rather than handed to a resolver
        foreach (['http://08.1.1.1/', 'http://1.2.3.4.5/', 'http://0x1ffffffff/'] as $url) {
            try {
                $guard->check($url);
                $this->fail("{$url} was not refused");
            } catch (FetchFailed $e) {
                $this->assertStringContainsString('not a valid IPv4 address', $e->getMessage());
            }
        }
    }

    public function test_every_remote_hop_and_its_stylesheets_are_pinned_to_the_vetted_addresses(): void
    {
        $sent = [];
        Http::fake(function (HttpRequest $request, array $options) use (&$sent) {
            $sent[] = [$request->url(), $options['curl'][CURLOPT_RESOLVE] ?? null];

            return match ($request->url()) {
                'https://pinned.example/start' => Http::response('', 302, ['Location' => 'http://other.example:8080/page']),
                'http://other.example:8080/page' => Http::response('<html><head><link rel="stylesheet" href="/app.css"></head><body><p>Hi</p></body></html>', 200, ['Content-Type' => 'text/html']),
                'http://other.example:8080/app.css' => Http::response('p { color: #000; }', 200, ['Content-Type' => 'text/css']),
                default => Http::response(self::BROKEN, 200),
            };
        });

        BfsgMcpServer::tool(AnalyzeUrl::class, ['url' => 'https://pinned.example/start'])->assertOk();
        BfsgMcpServer::tool(AnalyzeUrl::class, ['url' => 'http://8.8.8.8/'])->assertOk();

        $this->assertSame([
            ['https://pinned.example/start', ['pinned.example:443:93.184.215.20,[2606:4700::6810:1]', 'pinned.example.:443:93.184.215.20,[2606:4700::6810:1]']],
            ['http://other.example:8080/page', ['other.example:8080:93.184.215.21', 'other.example.:8080:93.184.215.21']],
            ['http://other.example:8080/app.css', ['other.example:8080:93.184.215.21', 'other.example.:8080:93.184.215.21']],
            // An IP literal needs no pin: nothing is resolved
            ['http://8.8.8.8/', null],
        ], $sent);
    }

    public function test_a_guarded_fetch_fails_closed_when_the_address_cannot_be_pinned(): void
    {
        Http::fake(fn () => Http::response(self::BROKEN, 200));
        $this->app->instance(UrlFetcher::class, new class(app(InProcessFetcher::class)) extends UrlFetcher
        {
            protected function canPinAddresses(): bool
            {
                return false;
            }
        });

        BfsgMcpServer::tool(AnalyzeUrl::class, ['url' => 'https://docs.example.com/'])->assertHasErrors(['needs the curl extension']);
        Http::assertNothingSent();

        // An allow-list does not pin, and neither does an IP literal
        BfsgMcpServer::tool(AnalyzeUrl::class, ['url' => 'http://8.8.8.8/'])->assertOk();
        config()->set('bfsg.mcp.allowed_hosts', ['docs.example.com']);
        BfsgMcpServer::tool(AnalyzeUrl::class, ['url' => 'https://docs.example.com/'])->assertOk();
    }

    public function test_an_explicit_allow_list_governs_private_addresses_too(): void
    {
        Http::fake(fn () => Http::response(self::BROKEN, 200));
        config()->set('bfsg.mcp.allowed_hosts', ['127.0.0.1', 'internal.example']);

        BfsgMcpServer::tool(AnalyzeUrl::class, ['url' => 'http://127.0.0.1:8080/'])->assertOk();
        BfsgMcpServer::tool(AnalyzeUrl::class, ['url' => 'https://internal.example/'])->assertOk();
        BfsgMcpServer::tool(AnalyzeUrl::class, ['url' => 'https://docs.example.com/'])->assertHasErrors(['not in the list of allowed hosts']);
    }

    public function test_the_guard_classifies_addresses(): void
    {
        foreach (['127.0.0.1', '10.255.255.255', '172.16.0.0', '192.168.0.1', '169.254.169.254', '0.0.0.0', '::1', '::', 'fc00::', 'fdff:ffff::1', 'fe80::1', 'febf::1', '::ffff:10.0.0.1', '[::1]', '100.64.0.1', '100.100.100.200', '198.18.0.1', '198.19.255.255', '240.0.0.1', '255.255.255.255', '192.0.0.8', '192.0.2.1', '203.0.113.10', 'fec0::1', '2001:db8::1', '64:ff9b::a9fe:a9fe', '64:ff9b::7f00:1', '2002:a9fe:a9fe::1', '2002:7f00:1::', '::127.0.0.1', '::10.0.0.1'] as $blocked) {
            $this->assertTrue(PrivateNetworkGuard::isBlocked($blocked), $blocked);
        }

        foreach (['8.8.8.8', '172.15.255.255', '172.32.0.0', '192.169.0.1', '169.255.0.1', '1.0.0.0', '100.63.255.255', '100.128.0.0', '93.184.215.14', '2606:4700::1111', '2a00:1450:4001::200e', '64:ff9b::808:808', '::ffff:8.8.8.8', 'not-an-ip'] as $public) {
            $this->assertFalse(PrivateNetworkGuard::isBlocked($public), $public);
        }
    }

    public function test_the_locale_parameter_is_validated_before_anything_is_fetched(): void
    {
        Http::fake(fn () => Http::response(self::BROKEN, 200));

        foreach ([AnalyzeHtml::class => ['html' => self::BROKEN], AnalyzeUrl::class => ['url' => 'https://docs.example.com/'], GenerateReport::class => ['url' => 'https://docs.example.com/']] as $tool => $arguments) {
            BfsgMcpServer::tool($tool, [...$arguments, 'locale' => '../../../../tmp/evil'])->assertHasErrors(['Invalid locale [../../../../tmp/evil]']);
            BfsgMcpServer::tool($tool, [...$arguments, 'locale' => 'fr'])->assertHasErrors(['Unsupported locale [fr]']);
            BfsgMcpServer::tool($tool, [...$arguments, 'locale' => ['de']])->assertHasErrors(['must be a string']);
        }

        Http::assertNothingSent();
    }

    public function test_the_reported_locale_is_the_rendered_one_when_the_configured_locale_is_unavailable(): void
    {
        $english = BfsgMcpServer::tool(AnalyzeHtml::class, ['html' => self::BROKEN, 'locale' => 'en'])->payload();

        foreach (['fr', '../x'] as $configured) {
            config()->set('bfsg.locale', $configured);

            $report = BfsgMcpServer::tool(AnalyzeHtml::class, ['html' => self::BROKEN])->assertOk()->payload();

            $this->assertSame('en', $report['locale'], $configured);
            $this->assertSame($english['violations']['images'][0]['message'], $report['violations']['images'][0]['message'], $configured);
        }

        $this->app['router']->get('/mcp-report', fn () => response(self::BROKEN));
        $markdown = BfsgMcpServer::tool(GenerateReport::class, ['url' => '/mcp-report', 'format' => 'markdown'])->assertOk()->payload();
        $this->assertStringStartsWith('# Accessibility Report', $markdown['report']);

        config()->set('bfsg.locale', 'de');
        $this->assertSame('de', BfsgMcpServer::tool(AnalyzeHtml::class, ['html' => self::BROKEN])->payload()['locale']);
    }
}
