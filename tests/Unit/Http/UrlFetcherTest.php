<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit\Http;

use GuzzleHttp\Handler\ProxyEnvironment;
use GuzzleHttp\ProxyOptions;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Context\Repository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Dom\HtmlDocument;
use ItsJustVita\LaravelBfsg\Http\AuthenticatedHttpClient;
use ItsJustVita\LaravelBfsg\Http\FetchedPage;
use ItsJustVita\LaravelBfsg\Http\FetchFailed;
use ItsJustVita\LaravelBfsg\Http\FetchOptions;
use ItsJustVita\LaravelBfsg\Http\InProcessFetcher;
use ItsJustVita\LaravelBfsg\Http\UrlFetcher;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\Support\PerformanceBudget;
use ItsJustVita\LaravelBfsg\Tests\Support\ProxyVariables;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use ItsJustVita\LaravelBfsg\Violation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\VarDumper\VarDumper;

class UrlFetcherTest extends TestCase
{
    use ProxyVariables;

    private const PAGE = '<!DOCTYPE html><html lang="en"><head><title>Page</title></head><body><main><h1>Hi</h1></main></body></html>';

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearProxyEnvironment();
    }

    protected function tearDown(): void
    {
        $this->restoreProxyEnvironment();

        parent::tearDown();
    }

    private function fetcher(): UrlFetcher
    {
        return app(UrlFetcher::class);
    }

    private function failure(string $url, ?FetchOptions $options = null): FetchFailed
    {
        try {
            $this->fetcher()->fetch($url, $options);
        } catch (FetchFailed $e) {
            return $e;
        }

        $this->fail("fetching $url did not fail");
    }

    public function test_remote_pages_are_fetched_through_the_client_and_redirects_followed(): void
    {
        Http::fake([
            'https://example.com/old' => Http::response('', 301, ['Location' => '/new', 'Set-Cookie' => 'seen=1; Path=/']),
            'https://example.com/new' => Http::response(self::PAGE, 200, ['Content-Type' => 'text/html; charset=UTF-8']),
        ]);

        $page = $this->fetcher()->fetch('https://example.com/old');

        $this->assertInstanceOf(FetchedPage::class, $page);
        $this->assertSame('https://example.com/old', $page->url);
        $this->assertSame('https://example.com/new', $page->finalUrl);
        $this->assertSame(self::PAGE, $page->html);
        $this->assertSame(200, $page->status);
        $this->assertTrue($page->redirected);
        $this->assertFalse($page->landedOnLogin);
        $this->assertFalse($page->inProcess);
        $this->assertSame('text/html; charset=UTF-8', $page->contentType);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://example.com/new' && ($request->header('Cookie')[0] ?? '') === 'seen=1');
    }

    public function test_the_given_client_carries_authentication(): void
    {
        Http::fake(['*' => Http::response(self::PAGE, 200)]);

        $this->fetcher()->fetch('https://example.com/', new FetchOptions(client: (new AuthenticatedHttpClient)->withBearer('secret')));

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer secret'));
    }

    public function test_failures_are_fetch_failed_with_status(): void
    {
        Http::fake(function (Request $request) {
            return match (parse_url($request->url(), PHP_URL_PATH)) {
                '/missing' => Http::response('Not found', 404),
                '/json' => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
                '/file' => Http::response('%PDF', 200, ['Content-Type' => 'application/pdf']),
                '/loop' => Http::response('', 302, ['Location' => '/loop']),
                '/down' => throw new ConnectionException('Connection refused'),
                default => Http::response(self::PAGE, 200),
            };
        });

        $this->assertSame(404, $this->failure('https://example.com/missing')->status);
        $this->assertStringContainsString('application/json', $this->failure('https://example.com/json')->getMessage());
        $this->assertStringContainsString('not an HTML page', $this->failure('https://example.com/file')->getMessage());
        $this->assertStringContainsString('after 5 redirects', $this->failure('https://example.com/loop')->getMessage());
        $this->assertStringContainsString('Connection refused', $this->failure('https://example.com/down')->getMessage());
        $this->assertStringContainsString('Invalid URL', $this->failure('ftp://example.com/')->getMessage());
        $this->assertStringContainsString('Invalid URL', $this->failure('not-a-url')->getMessage());
    }

    public function test_allowed_hosts_are_checked_for_the_url_and_every_redirect(): void
    {
        Http::fake([
            'https://docs.example.com/away' => Http::response('', 302, ['Location' => 'https://evil.example.net/']),
            '*' => Http::response(self::PAGE, 200),
        ]);
        $options = new FetchOptions(allowedHosts: ['docs.example.com']);

        $this->assertSame(self::PAGE, $this->fetcher()->fetch('https://DOCS.example.com/', $options)->html);
        $this->assertStringContainsString('evil.example.net', $this->failure('https://docs.example.com/away', $options)->getMessage());
        $this->assertStringContainsString('not in the list of allowed hosts', $this->failure('https://other.example.com/', $options)->getMessage());
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'evil.example.net') || str_contains($request->url(), 'other.example.com'));
    }

    public function test_paths_and_app_url_pages_are_fetched_in_process_without_http(): void
    {
        config()->set('app.url', 'https://shop.test');
        $seen = [];
        $this->app['router']->get('/bfsg-page', function (\Illuminate\Http\Request $request) use (&$seen) {
            $seen[] = [$request->getHost(), $request->secure(), $request->attributes->get(InProcessFetcher::SKIP_ATTRIBUTE)];

            return response(self::PAGE);
        });

        $page = $this->fetcher()->fetch('/bfsg-page');
        $this->fetcher()->fetch('https://shop.test/bfsg-page');

        $this->assertTrue($page->inProcess);
        $this->assertSame('https://shop.test/bfsg-page', $page->finalUrl);
        $this->assertSame(self::PAGE, $page->html);
        $this->assertSame([['shop.test', true, true], ['shop.test', true, true]], $seen);
        Http::assertNothingSent();
    }

    public function test_in_process_requests_can_act_as_a_user_and_detect_the_login_redirect(): void
    {
        $this->app['router']->get('/account', fn () => auth()->check() ? response('<html><body>'.auth()->id().'</body></html>') : redirect('/login'));
        $this->app['router']->get('/login', fn () => response('<html><body><form></form></body></html>'));

        $guest = $this->fetcher()->fetch('/account');
        $this->assertTrue($guest->landedOnLogin);
        $this->assertTrue($guest->redirected);
        $this->assertSame('http://localhost/login', $guest->finalUrl);

        $member = $this->fetcher()->fetch('/account', new FetchOptions(actingAs: new GenericUser(['id' => 42])));
        $this->assertFalse($member->landedOnLogin);
        $this->assertStringContainsString('<body>42</body>', $member->html);

        $this->assertFalse($this->fetcher()->fetch('/login')->landedOnLogin, 'checking the login page itself is not a redirect to it');
    }

    public function test_in_process_errors_streams_and_non_html(): void
    {
        $this->app['router']->get('/boom', fn () => throw new \RuntimeException('boom'));
        $this->app['router']->get('/api', fn () => response()->json(['ok' => true]));
        $this->app['router']->get('/stream', fn () => new StreamedResponse(function () {
            echo self::PAGE;
        }, 200, ['Content-Type' => 'text/html']));

        $this->assertSame(500, $this->failure('/boom')->status);
        $this->assertSame(404, $this->failure('/nope')->status);
        $this->assertStringContainsString('application/json', $this->failure('/api')->getMessage());
        $this->assertSame(self::PAGE, $this->fetcher()->fetch('/stream')->html);
    }

    public function test_same_origin_stylesheets_are_inlined_in_place(): void
    {
        $html = '<html><head><link rel="stylesheet" href="/css/app.css"><style>p{}</style>'
            .'<link rel="stylesheet" href="https://cdn.example.net/lib.css"><link rel="stylesheet" media="print" href="/print.css">'
            .'<link rel="alternate stylesheet" href="/alt.css"><link rel="icon" href="/favicon.ico"><link rel=stylesheet href=missing.css></head><body></body></html>';

        Http::fake([
            'https://example.com/page' => Http::response($html, 200),
            'https://example.com/css/app.css' => Http::response('body { color: #333 } </style><script>', 200),
            'https://example.com/missing.css' => Http::response('', 404),
        ]);

        $page = $this->fetcher()->fetch('https://example.com/page');

        $this->assertStringContainsString('<head><style data-bfsg-inlined="/css/app.css">body { color: #333 } <\/style><script></style><style>p{}</style>', $page->html);
        $this->assertStringContainsString('<link rel="stylesheet" href="https://cdn.example.net/lib.css">', $page->html);
        $this->assertStringContainsString('<link rel="stylesheet" media="print" href="/print.css">', $page->html);
        $this->assertStringContainsString('<link rel=stylesheet href=missing.css>', $page->html);
        // F3 (3.1.1): the cross-origin sheet is no longer skipped silently.
        $this->assertSame(['Stylesheet missing.css could not be loaded.', '1 stylesheet on another origin was not inlined; contrast results are approximate.'], $page->warnings);
        Http::assertNotSent(fn (Request $request) => in_array($request->url(), ['https://cdn.example.net/lib.css', 'https://example.com/print.css', 'https://example.com/alt.css'], true));
    }

    public function test_inlined_stylesheets_keep_their_media(): void
    {
        $html = '<html><head><link rel="stylesheet" media="(prefers-color-scheme: dark)" href="/dark.css"><link rel="stylesheet" media="screen and (min-width: 1px)" href="/wide.css">'
            .'<link rel="stylesheet" media="" href="/all.css"></head><body><p>x</p></body></html>';

        Http::fake([
            'https://example.com/page' => Http::response($html, 200),
            'https://example.com/dark.css' => Http::response('p { color: #eee }', 200),
            'https://example.com/wide.css' => Http::response('p { font-size: 20px }', 200),
            'https://example.com/all.css' => Http::response('p { font-weight: 700 }', 200),
        ]);

        $page = $this->fetcher()->fetch('https://example.com/page');

        $this->assertStringContainsString('<style data-bfsg-inlined="/dark.css" media="(prefers-color-scheme: dark)">p { color: #eee }</style>', $page->html);
        $this->assertStringContainsString('<style data-bfsg-inlined="/wide.css" media="screen and (min-width: 1px)">p { font-size: 20px }</style>', $page->html);
        $this->assertStringContainsString('<style data-bfsg-inlined="/all.css">p { font-weight: 700 }</style>', $page->html);
        $this->assertSame(['preference', 'viewport', null], array_column(HtmlDocument::fromHtml($page->html)->cssParser()->rules(), 'condition'));
    }

    public function test_invalid_utf8_in_an_inlined_media_attribute_is_substituted(): void
    {
        Http::fake([
            'https://example.com/page' => Http::response("<html><head><link rel=\"stylesheet\" media=\"(prefers-color-scheme: dark\xFF)\" href=\"/dark.css\"></head><body></body></html>", 200),
            'https://example.com/dark.css' => Http::response('p { color: #eee }', 200),
        ]);

        $page = $this->fetcher()->fetch('https://example.com/page');

        $this->assertStringContainsString("<style data-bfsg-inlined=\"/dark.css\" media=\"(prefers-color-scheme: dark\u{FFFD})\">p { color: #eee }</style>", $page->html);
    }

    public function test_stylesheet_limits_and_the_switch(): void
    {
        config()->set('bfsg.fetch.max_stylesheets', 2);
        config()->set('bfsg.fetch.max_stylesheet_bytes', 10);
        $html = '<html><head><link rel="stylesheet" href="/a.css"><link rel="stylesheet" href="/big.css"><link rel="stylesheet" href="/c.css"></head></html>';

        Http::fake([
            'https://example.com/' => Http::response($html, 200),
            'https://example.com/a.css' => Http::response('p{}', 200),
            'https://example.com/big.css' => Http::response(str_repeat('x', 11), 200),
        ]);

        $page = $this->fetcher()->fetch('https://example.com/');
        $this->assertStringContainsString('<style data-bfsg-inlined="/a.css">p{}</style>', $page->html);
        $this->assertSame(['Stylesheet /big.css was not inlined: larger than 10 bytes.', 'Stylesheet /c.css was not inlined: more than 2 stylesheets.'], $page->warnings);

        $this->assertSame($html, $this->fetcher()->fetch('https://example.com/', new FetchOptions(inlineStylesheets: false))->html);
        config()->set('bfsg.fetch.inline_stylesheets', false);
        $this->assertSame($html, $this->fetcher()->fetch('https://example.com/')->html);
    }

    public function test_in_process_stylesheets_are_read_from_the_public_directory_only(): void
    {
        $public = sys_get_temp_dir().'/bfsg-public-'.uniqid();
        mkdir($public.'/css', 0777, true);
        file_put_contents($public.'/css/app.css', '.muted { color: #999 }');
        file_put_contents(dirname($public).'/bfsg-secret.css', 'secret');
        $this->app->usePublicPath($public);
        $this->app['router']->get('/styled', fn () => response('<html><head><link rel="stylesheet" href="/css/app.css"><link rel="stylesheet" href="/../bfsg-secret.css"></head><body></body></html>'));

        try {
            $page = $this->fetcher()->fetch('/styled');
            $this->assertNull(app(InProcessFetcher::class)->publicFile('/../bfsg-secret.css'), 'no path outside public/');
            $this->assertSame('.muted { color: #999 }', app(InProcessFetcher::class)->publicFile('/css/app.css'));
        } finally {
            unlink($public.'/css/app.css');
            rmdir($public.'/css');
            rmdir($public);
            unlink(dirname($public).'/bfsg-secret.css');
        }

        $this->assertStringContainsString('<style data-bfsg-inlined="/css/app.css">.muted { color: #999 }</style>', $page->html);
        $this->assertStringNotContainsString('secret</style>', $page->html);
        $this->assertCount(1, $page->warnings);
    }

    public function test_basic_authorization_decodes_the_credentials_of_a_url(): void
    {
        $this->assertSame('Basic '.base64_encode('deploy:s3cr@t'), UrlFetcher::basicAuthorization('https://deploy:s3cr%40t@staging.example.com/page'));
        $this->assertSame('Basic '.base64_encode('token:'), UrlFetcher::basicAuthorization('https://token@staging.example.com/'));
        $this->assertNull(UrlFetcher::basicAuthorization('https://staging.example.com/page?user=a@b'));
        $this->assertNull(UrlFetcher::basicAuthorization('http://'));
    }

    public function test_credentials_are_not_sent_to_another_origin_on_redirects(): void
    {
        Http::fake([
            'https://example.com/away' => Http::response('', 302, ['Location' => 'http://evil.example.net/steal']),
            'https://example.com/down' => Http::response('', 302, ['Location' => 'http://example.com/plain']),
            'https://example.com/port' => Http::response('', 302, ['Location' => 'https://example.com:8443/other']),
            'https://example.com/same' => Http::response('', 302, ['Location' => '/landing']),
            '*' => Http::response(self::PAGE, 200),
        ]);
        $client = fn () => (new AuthenticatedHttpClient)
            ->withBearer('TOPSECRET', 'https://example.com')
            ->withApiKey('K', 'X-API-Key', 'https://example.com')
            ->withHeaders(['X-Tenant' => 'acme'], 'https://example.com')
            ->withSessionCookie('laravel_session', 'sess', 'https://example.com/');
        $leaks = fn (Request $request) => $request->hasHeader('Authorization') || $request->hasHeader('X-API-Key')
            || $request->hasHeader('X-Tenant') || $request->hasHeader('Cookie');

        foreach (['away' => 'http://evil.example.net/steal', 'down' => 'http://example.com/plain', 'port' => 'https://example.com:8443/other'] as $path => $target) {
            $this->fetcher()->fetch("https://example.com/$path", new FetchOptions(client: $client()));
            Http::assertSent(fn (Request $request) => $request->url() === $target);
            Http::assertNotSent(fn (Request $request) => $request->url() === $target && $leaks($request));
        }

        $this->fetcher()->fetch('https://example.com/same', new FetchOptions(client: $client()));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://example.com/landing'
            && $request->hasHeader('Authorization', 'Bearer TOPSECRET') && $request->hasHeader('X-API-Key', 'K')
            && $request->hasHeader('X-Tenant', 'acme') && ($request->header('Cookie')[0] ?? '') === 'laravel_session=sess');
    }

    public function test_in_process_fetches_do_not_leak_auth_session_or_request_state(): void
    {
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        config()->set('auth.guards.other', ['driver' => 'session', 'provider' => 'users']);
        $this->app['router']->middleware('web')->group(function ($router) {
            $router->get('/who', fn () => response('<html><body>'.(auth()->check() ? 'IN:'.auth()->id() : 'GUEST').'</body></html>'));
            $router->get('/put', function () {
                session()->put('secret', 'S1');
                session()->flash('status', 'flashed');

                return response('<html><body>put</body></html>');
            });
            $router->get('/read', fn () => response('<html><body>'.e(json_encode([session('secret'), session('status')])).'</body></html>'));
        });
        $request = app('request');
        $defaultGuard = $this->app['auth']->getDefaultDriver();

        $this->assertStringContainsString('IN:42', $this->fetcher()->fetch('/who', new FetchOptions(actingAs: new GenericUser(['id' => 42]), guard: 'other'))->html);
        $this->assertStringContainsString('GUEST', $this->fetcher()->fetch('/who')->html, 'a later fetch without actingAs is a guest');
        $this->assertFalse(auth()->check(), 'the calling process is not logged in');
        $this->assertSame($defaultGuard, $this->app['auth']->getDefaultDriver(), 'the default guard is restored');

        $this->fetcher()->fetch('/put');
        $this->assertStringContainsString('[null,null]', $this->fetcher()->fetch('/read')->html, 'session data does not carry over');
        $this->assertNull(session('secret'));

        $this->assertSame($request, app('request'), 'the current request is restored');
        $this->assertSame($request, \Illuminate\Support\Facades\Request::getFacadeRoot());
        $this->assertSame('http://localhost/x', url('/x'));
    }

    public function test_same_app_detection_compares_scheme_host_and_port(): void
    {
        Http::fake(['*' => Http::response(self::PAGE, 200)]);
        $this->app['router']->get('/', fn () => response('<html><body>kernel</body></html>'));

        $this->assertTrue($this->fetcher()->isSameApp('http://LOCALHOST:80/x'));
        $this->assertFalse($this->fetcher()->isSameApp('http://localhost:5173/'));
        $this->assertFalse($this->fetcher()->isSameApp('https://localhost/'));
        $this->assertFalse($this->fetcher()->isSameApp('ftp://localhost/'));

        $vite = $this->fetcher()->fetch('http://localhost:5173/');
        $this->assertFalse($vite->inProcess);
        $this->assertSame(self::PAGE, $vite->html);
        Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost:5173/');
    }

    public function test_a_redirect_from_a_remote_page_into_this_application_is_not_fetched_in_process(): void
    {
        $this->app['router']->get('/admin-secret', fn () => response('<html><body>ADMIN '.auth()->id().'</body></html>'));
        Http::fake([
            'https://attacker.example/x' => Http::response('', 302, ['Location' => 'http://localhost/admin-secret']),
            'http://localhost/*' => Http::response('<html><body>remote copy</body></html>', 200),
        ]);

        $page = $this->fetcher()->fetch('https://attacker.example/x', new FetchOptions(actingAs: new GenericUser(['id' => 1])));

        $this->assertFalse($page->inProcess);
        $this->assertStringNotContainsString('ADMIN', $page->html);
        Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost/admin-secret');
    }

    public function test_every_redirect_hop_must_be_an_http_url(): void
    {
        Http::fake([
            'https://example.com/file' => Http::response('', 302, ['Location' => 'file:///etc/passwd']),
            'https://example.com/js' => Http::response('', 302, ['Location' => 'javascript:alert(1)']),
            '*' => Http::response(self::PAGE, 200),
        ]);
        $this->app['router']->get('/to-file', fn () => redirect()->away('file:///etc/passwd'));

        foreach (['https://example.com/file', 'https://example.com/js', '/to-file'] as $url) {
            $this->assertStringContainsString('Invalid URL', $this->failure($url)->getMessage(), $url);
        }

        Http::assertNotSent(fn (Request $request) => ! str_starts_with($request->url(), 'https://example.com/'));
    }

    public function test_malformed_urls_and_in_process_errors_are_fetch_failed(): void
    {
        $this->app['router']->get('/stream-boom', fn () => new StreamedResponse(function () {
            throw new \RuntimeException('stream exploded');
        }, 200, ['Content-Type' => 'text/html']));
        Http::fake(['https://example.com/bad' => Http::response('', 302, ['Location' => 'http://exa mple.com/'])]);

        $this->assertStringContainsString('Invalid URL', $this->failure('http://exa mple.com/')->getMessage());
        $this->assertStringContainsString('Invalid URL', $this->failure('https://example.com/bad')->getMessage());
        $this->assertStringContainsString('stream exploded', $this->failure('/stream-boom')->getMessage());
    }

    public function test_allowed_hosts_accept_ipv6_brackets_and_trailing_dots(): void
    {
        Http::fake(['*' => Http::response(self::PAGE, 200)]);

        $this->assertSame(self::PAGE, $this->fetcher()->fetch('http://[::1]:8080/', new FetchOptions(allowedHosts: ['::1']))->html);
        $this->assertSame(self::PAGE, $this->fetcher()->fetch('http://[::1]/', new FetchOptions(allowedHosts: ['[::1]']))->html);
        $this->assertSame(self::PAGE, $this->fetcher()->fetch('https://example.com./', new FetchOptions(allowedHosts: ['example.com']))->html);
        $this->assertSame(self::PAGE, $this->fetcher()->fetch('https://example.com/', new FetchOptions(allowedHosts: ['Example.COM.']))->html);
        $this->assertStringContainsString('not in the list of allowed hosts', $this->failure('https://example.com.evil.net/', new FetchOptions(allowedHosts: ['example.com']))->getMessage());
    }

    public function test_pages_larger_than_the_page_limit_are_fetch_failed(): void
    {
        Http::fake(['*' => Http::response('<html>'.str_repeat('x', UrlFetcher::MAX_PAGE_BYTES), 200)]);
        $this->app['router']->get('/huge', fn () => response('<html>'.str_repeat('x', UrlFetcher::MAX_PAGE_BYTES)));

        $this->assertStringContainsString('larger than '.UrlFetcher::MAX_PAGE_BYTES.' bytes', $this->failure('https://example.com/huge')->getMessage());
        $this->assertStringContainsString('larger than '.UrlFetcher::MAX_PAGE_BYTES.' bytes', $this->failure('/huge')->getMessage());
    }

    public function test_links_inside_comments_templates_noscript_and_scripts_are_not_inlined(): void
    {
        $html = '<html><head><!-- <link rel="stylesheet" href="/c.css"> --><template><link rel="stylesheet" href="/t.css"></template>'
            .'<noscript><link rel="stylesheet" href="/n.css"></noscript><script>var s = \'<link rel="stylesheet" href="/s.css">\';</script>'
            .'<link rel="stylesheet" href="/real.css"></head><body></body></html>';
        Http::fake(['https://example.com/page' => Http::response($html, 200), '*' => Http::response('p{}', 200)]);

        $page = $this->fetcher()->fetch('https://example.com/page');

        $this->assertStringContainsString('<style data-bfsg-inlined="/real.css">p{}</style>', $page->html);
        foreach (['c', 't', 'n', 's'] as $name) {
            $this->assertStringContainsString('<link rel="stylesheet" href="/'.$name.'.css">', $page->html);
        }
        Http::assertNotSent(fn (Request $request) => preg_match('~/[ctns]\.css$~', $request->url()) === 1);
    }

    public function test_public_files_are_css_only_and_size_checked_before_reading(): void
    {
        config()->set('bfsg.fetch.max_stylesheet_bytes', 10);
        $public = sys_get_temp_dir().'/bfsg-public-'.uniqid();
        mkdir($public, 0777, true);
        file_put_contents($public.'/index.php', '<?php echo "source";');
        file_put_contents($public.'/big.css', str_repeat('x', 11));
        $this->app->usePublicPath($public);
        $this->app['router']->get('/styled', fn () => response('<html><head><link rel="stylesheet" href="/index.php"><link rel="stylesheet" href="/big.css"></head><body></body></html>'));

        try {
            $this->assertNull(app(InProcessFetcher::class)->publicFile('/index.php'));
            $page = $this->fetcher()->fetch('/styled');
        } finally {
            unlink($public.'/index.php');
            unlink($public.'/big.css');
            rmdir($public);
        }

        $this->assertStringNotContainsString('source', $page->html);
        $this->assertContains('Stylesheet /big.css was not inlined: larger than 10 bytes.', $page->warnings);
    }

    public function test_unbound_credentials_are_bound_to_the_requested_origin_before_any_redirect(): void
    {
        $this->app['router']->get('/go', fn () => redirect()->away('https://evil.example.net/'));
        Http::fake(['*' => Http::response(self::PAGE, 200)]);
        $client = (new AuthenticatedHttpClient)->withBearer('UNBOUND')->withApiKey('K')->withHeaders(['X-Tenant' => 'acme']);

        $this->fetcher()->fetch('/go', new FetchOptions(client: $client));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://evil.example.net/');
        Http::assertNotSent(fn (Request $request) => $request->hasHeader('Authorization') || $request->hasHeader('X-API-Key') || $request->hasHeader('X-Tenant'));

        $client->get('http://localhost/api');
        Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost/api' && $request->hasHeader('Authorization', 'Bearer UNBOUND'));
    }

    public function test_large_raw_text_elements_do_not_stop_inlining(): void
    {
        $script = '<script>'.str_repeat('if (a < b) { x = "<i>"; } ', 45000).'</script>';
        $textarea = '<textarea>'.str_repeat("line <b>\n", 240000).'</textarea>';
        $html = '<html><head><link rel="stylesheet" href="/a.css">'.$script.'</head><body>'.$textarea
            .'<link rel="stylesheet" href="/b.css"><SCRIPT type="module">const s = \'<link rel="stylesheet" href="/s.css">\'</SCRIPT ></body></html>';
        $this->assertGreaterThan(1000000, strlen($script));
        $this->assertGreaterThan(2000000, strlen($textarea));
        Http::fake(['https://example.com/page' => Http::response($html, 200), '*' => Http::response('p{}', 200)]);

        $page = $this->fetcher()->fetch('https://example.com/page');

        $this->assertTrue(str_contains($page->html, '<style data-bfsg-inlined="/a.css">p{}</style>'), '/a.css before the script is inlined');
        $this->assertTrue(str_contains($page->html, '<style data-bfsg-inlined="/b.css">p{}</style>'), '/b.css after the textarea is inlined');
        $this->assertTrue(str_contains($page->html, '\'<link rel="stylesheet" href="/s.css">\''), 'the link inside the module script stays');
        $this->assertTrue(str_contains($page->html, $script) && str_contains($page->html, $textarea), 'raw text is unchanged');
        $this->assertSame([], $page->warnings);
    }

    public function test_an_unclosed_raw_text_element_ends_the_scan(): void
    {
        Http::fake([
            'https://example.com/page' => Http::response('<html><head><link rel="stylesheet" href="/a.css"><script>var x = 1; <link rel="stylesheet" href="/b.css">', 200),
            '*' => Http::response('p{}', 200),
        ]);

        $page = $this->fetcher()->fetch('https://example.com/page');

        $this->assertStringContainsString('<style data-bfsg-inlined="/a.css">p{}</style><script>var x = 1; <link rel="stylesheet" href="/b.css">', $page->html);
    }

    public function test_the_callers_session_store_and_logged_in_user_survive_an_in_process_fetch(): void
    {
        $this->app['router']->get('/who', fn () => response('<html><body>'.(auth()->check() ? 'IN:'.auth()->id() : 'GUEST').' '.e((string) session('outer')).'</body></html>'));
        auth()->setUser(new GenericUser(['id' => 7]));
        $store = app('session.store');
        $store->put('outer', 'kept');

        $html = $this->fetcher()->fetch('/who')->html;

        $this->assertStringContainsString('<body>GUEST </body>', $html, 'the fetch sees neither the caller\'s user nor its session');
        $this->assertSame(7, auth()->id(), 'the caller is still logged in');
        $this->assertSame($store, app('session.store'));
        $this->assertSame($store, app('session')->driver());
        $this->assertSame('kept', session('outer'));
    }

    public function test_repeated_in_process_fetches_do_not_grow_the_request_rebinding_callbacks(): void
    {
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $this->app['router']->middleware('web')->get('/who', fn () => response('<html><body>'.(auth()->check() ? 'IN' : 'GUEST').'</body></html>'));
        $count = fn () => (fn () => count($this->reboundCallbacks['request'] ?? []))->call($this->app);
        auth()->guard(); // the caller's guard (and its callback) exists before the fetches
        // A service first resolved by a fetch keeps its callback, once (the URL generator's; it must follow the request)
        $this->fetcher()->fetch('/who');
        $before = $count();

        for ($i = 0; $i < 20; $i++) {
            $this->fetcher()->fetch('/who', new FetchOptions(actingAs: $i % 2 === 0 ? new GenericUser(['id' => $i + 1]) : null));
        }

        $this->assertSame($before, $count(), 'a long-running MCP server must not collect one callback per fetch');
        $this->assertStringContainsString('GUEST', $this->fetcher()->fetch('/who')->html);
        $this->assertSame('http://localhost/x', url('/x'));
    }

    public function test_a_locale_set_by_the_fetched_page_does_not_leak_into_the_caller(): void
    {
        $this->app['router']->get('/german', function () {
            app()->setLocale('de');

            return response('<html><body>'.app()->getLocale().'</body></html>');
        });
        $this->app['router']->get('/german-broken', function () {
            app()->setLocale('de');

            throw new \RuntimeException('boom');
        });
        app()->setLocale('en');

        $this->assertStringContainsString('<body>de</body>', $this->fetcher()->fetch('/german')->html);
        $this->assertSame('en', app()->getLocale());
        $this->assertSame('en', app('translator')->getLocale());

        $this->failure('/german-broken');
        $this->assertSame('en', app()->getLocale());
        $this->assertSame('en', app('translator')->getLocale());
    }

    /** Runs $test with a VarDumper handler that records what reaches it (the process's own dumper writes to php://stdout). */
    private function recordingDumps(\Closure $test): array
    {
        $dumped = [];
        $previous = VarDumper::setHandler(function (mixed $value) use (&$dumped) {
            $dumped[] = $value;
        });

        try {
            $test();
        } finally {
            VarDumper::setHandler($previous);
        }

        return $dumped;
    }

    public function test_in_process_output_is_captured_and_reported_as_a_warning(): void
    {
        $this->app['router']->get('/echo', function () {
            echo 'STRAY-ECHO';

            return response(self::PAGE);
        });
        $this->app['router']->get('/dump', function () {
            dump('STRAY-DUMP');

            return response(self::PAGE);
        });
        $level = ob_get_level();

        $dumped = $this->recordingDumps(function () use (&$echo, &$dump, &$german, &$leak) {
            ob_start();

            try {
                $echo = $this->fetcher()->fetch('/echo');
                $dump = $this->fetcher()->fetch('/dump');
                app()->setLocale('de');
                $german = $this->fetcher()->fetch('/echo');
            } finally {
                $leak = ob_get_clean();
                app()->setLocale('en');
            }

            dump('after the fetches');
        });

        $this->assertSame('', $leak, 'nothing reaches the output of the calling process');
        $this->assertSame(['after the fetches'], $dumped, 'dump() inside the page is captured, and the caller\'s dumper is restored');
        $this->assertSame($level, ob_get_level());
        $this->assertSame(self::PAGE, $echo->html);
        $this->assertSame(['The application wrote 10 bytes outside the response; they were discarded.'], $echo->warnings);
        $this->assertSame(self::PAGE, $dump->html);
        $this->assertCount(1, $dump->warnings);
        $this->assertStringContainsString('outside the response', $dump->warnings[0]);
        $this->assertSame(['Die Anwendung hat 10 Bytes außerhalb der Antwort ausgegeben; sie wurden verworfen.'], $german->warnings);
    }

    public function test_dump_is_captured_even_with_a_var_dumper_format(): void
    {
        $this->app['router']->get('/dump', function () {
            dump('STRAY-DUMP');

            return response(self::PAGE);
        });
        $server = $_SERVER['VAR_DUMPER_FORMAT'] ?? null;
        $env = $_ENV['VAR_DUMPER_FORMAT'] ?? null;

        // VarDumper::setHandler() ignores every call while VAR_DUMPER_FORMAT is set; the recorder stands for the
        // process's own dumper, which writes to php://stdout.
        $dumped = $this->recordingDumps(function () use (&$page) {
            $_SERVER['VAR_DUMPER_FORMAT'] = $_ENV['VAR_DUMPER_FORMAT'] = 'cli';

            try {
                $page = $this->fetcher()->fetch('/dump');
                $this->assertSame('cli', $_SERVER['VAR_DUMPER_FORMAT']);
                $this->assertSame('cli', $_ENV['VAR_DUMPER_FORMAT']);
                dump('after the fetch');
            } finally {
                unset($_SERVER['VAR_DUMPER_FORMAT'], $_ENV['VAR_DUMPER_FORMAT']);
            }
        });

        if ($server !== null) {
            $_SERVER['VAR_DUMPER_FORMAT'] = $server;
        }

        if ($env !== null) {
            $_ENV['VAR_DUMPER_FORMAT'] = $env;
        }

        $this->assertSame(['after the fetch'], $dumped, 'the page\'s dump() is captured, the caller\'s dumper is restored');
        $this->assertSame(self::PAGE, $page->html);
        $this->assertCount(1, $page->warnings);
        $this->assertStringContainsString('outside the response', $page->warnings[0]);
    }

    public function test_streamed_bodies_are_captured_even_when_flushed_and_output_buffers_are_left_as_found(): void
    {
        $head = '<!DOCTYPE html><html lang="en"><head><title>Page</title></head><body>';
        $this->app['router']->get('/flushed', fn () => response()->stream(function () use ($head) {
            echo $head;
            ob_flush();
            flush();
            echo '<main><h1>Hi</h1></main></body></html>';
        }, 200, ['Content-Type' => 'text/html']));
        $this->app['router']->get('/noisy-stream', function () {
            echo 'X';

            return response()->stream(fn () => print (self::PAGE), 200, ['Content-Type' => 'text/html']);
        });
        $this->app['router']->get('/unclosed', function () {
            ob_start();
            echo '<p>partial';

            throw new \RuntimeException('boom');
        });
        $this->app['router']->get('/stream-boom', fn () => response()->stream(function () {
            ob_start();
            echo 'half';

            throw new \RuntimeException('stream boom');
        }, 200, ['Content-Type' => 'text/html']));
        $level = ob_get_level();

        $dumped = $this->recordingDumps(function () use (&$flushed, &$noisy, &$unclosed, &$broken, &$leak) {
            ob_start();

            try {
                $flushed = $this->fetcher()->fetch('/flushed');
                $noisy = $this->fetcher()->fetch('/noisy-stream');
                $unclosed = $this->failure('/unclosed');
                $broken = $this->failure('/stream-boom');
            } finally {
                $leak = ob_get_clean();
            }

            dump('restored after an exception');
        });

        $this->assertSame('', $leak);
        $this->assertSame($level, ob_get_level(), 'the output buffer stack is as it was, also after exceptions');
        $this->assertSame(['restored after an exception'], $dumped);
        $this->assertSame($head.'<main><h1>Hi</h1></main></body></html>', $flushed->html);
        $this->assertSame([], $flushed->warnings);
        $this->assertSame(self::PAGE, $noisy->html, 'output before the stream is not part of the body');
        $this->assertSame(['The application wrote 1 bytes outside the response; they were discarded.'], $noisy->warnings);
        $this->assertSame(500, $unclosed->status);
        $this->assertStringContainsString('stream boom', $broken->getMessage());
    }

    public function test_scoped_instances_do_not_leak_between_fetches(): void
    {
        $this->app->scoped('tenant', fn () => request()->path());
        $this->app['router']->get('/alpha', fn () => response('<html><body>tenant='.app('tenant').'</body></html>'));
        $this->app['router']->get('/beta', fn () => response('<html><body>tenant='.app('tenant').'</body></html>'));
        $caller = app('tenant');

        $this->assertStringContainsString('tenant=alpha', $this->fetcher()->fetch('/alpha')->html);
        $this->assertStringContainsString('tenant=beta', $this->fetcher()->fetch('/beta')->html);
        $this->assertSame($caller, app('tenant'), 'the caller keeps its own scoped instance');
    }

    public function test_context_is_isolated_and_restored(): void
    {
        Context::add('seen', 'caller');
        Context::addHidden('secret', 'caller-secret');
        $this->app['router']->get('/context', function () {
            $before = Context::get('seen', 'none').'/'.Context::getHidden('secret', 'none');
            Context::add('seen', 'route');
            Context::addHidden('secret', 'route-secret');

            return response('<html><body>before='.$before.'</body></html>');
        });
        $this->app['router']->get('/context-boom', function () {
            Context::add('seen', 'boom');

            throw new \RuntimeException('boom');
        });

        $this->assertStringContainsString('before=none/none', $this->fetcher()->fetch('/context')->html);
        $this->assertStringContainsString('before=none/none', $this->fetcher()->fetch('/context')->html, 'nor from the previous page');
        $this->failure('/context-boom');

        $this->assertSame('caller', Context::get('seen'));
        $this->assertSame('caller-secret', Context::getHidden('secret'));
        $this->assertSame(['seen' => 'caller'], app(Repository::class)->all());
    }

    public function test_an_app_url_with_a_path_is_the_base_path_of_in_process_requests(): void
    {
        config()->set('app.url', 'http://localhost/app');
        $public = sys_get_temp_dir().'/bfsg-public-'.uniqid();
        mkdir($public.'/css', 0777, true);
        file_put_contents($public.'/css/app.css', '.muted { color: #999 }');
        $this->app->usePublicPath($public);
        $seen = [];
        $this->app['router']->get('/contact', function (\Illuminate\Http\Request $request) use (&$seen) {
            $seen[] = [$request->path(), url('/x')];

            return response('<html><head><link rel="stylesheet" href="/app/css/app.css"></head><body></body></html>');
        });
        $this->app['router']->get('/', function (\Illuminate\Http\Request $request) use (&$seen) {
            $seen[] = [$request->path(), url('/x')];

            return response(self::PAGE);
        });

        try {
            $contact = $this->fetcher()->fetch('/contact');
            $this->fetcher()->fetch('http://localhost/app');
            $this->fetcher()->fetch('/');
        } finally {
            unlink($public.'/css/app.css');
            rmdir($public.'/css');
            rmdir($public);
        }

        $this->assertSame('http://localhost/app/contact', $contact->finalUrl);
        $this->assertSame([['contact', 'http://localhost/app/x'], ['/', 'http://localhost/app/x'], ['/', 'http://localhost/app/x']], $seen);
        $this->assertStringContainsString('<style data-bfsg-inlined="/app/css/app.css">.muted { color: #999 }</style>', $contact->html);
        $this->assertSame([], $contact->warnings);
    }

    /**
     * What Guzzle and libcurl do (checked against real curl requests with Guzzle 7.8, 7.11, 7.16 and 8.3): the request's
     * `proxy` option, else Guzzle's default from HTTPS_PROXY, HTTP_PROXY (CLI only) and NO_PROXY; when that decides
     * nothing, the variables libcurl reads (lowercase first, never uppercase HTTP_PROXY) with no_proxy/NO_PROXY.
     *
     * @return array<string, array{0: array<string, string>, 1: array<string, mixed>, 2: string, 3: bool}> environment, request options, URL, proxied
     */
    public static function proxyDecisions(): array
    {
        $proxy = 'http://proxy.local:3128';
        // Guzzle 8 and 7.12+ resolve proxies themselves; earlier Guzzle 7 leaves no_proxy to libcurl, which applies it to every proxy
        $guzzleResolves = class_exists(ProxyOptions::class) || class_exists(ProxyEnvironment::class);

        return [
            'nothing configured' => [[], [], 'https://example.com/', false],
            'HTTPS_PROXY' => [['HTTPS_PROXY' => $proxy], [], 'https://example.com/', true],
            'https_proxy' => [['https_proxy' => $proxy], [], 'https://example.com/', true],
            'HTTPS_PROXY is not used for http' => [['HTTPS_PROXY' => $proxy], [], 'http://example.com/', false],
            'http_proxy' => [['http_proxy' => $proxy], [], 'http://example.com/', true],
            'HTTP_PROXY in the CLI' => [['HTTP_PROXY' => $proxy], [], 'http://example.com/', true],
            'HTTP_PROXY is not used for https' => [['HTTP_PROXY' => $proxy], [], 'https://example.com/', false],
            'all_proxy' => [['all_proxy' => 'socks5://proxy.local:1080'], [], 'http://example.com/', true],
            'ALL_PROXY' => [['ALL_PROXY' => 'socks5://proxy.local:1080'], [], 'https://example.com/', true],
            'empty variable' => [['HTTPS_PROXY' => ''], [], 'https://example.com/', false],
            'NO_PROXY host' => [['HTTPS_PROXY' => $proxy, 'NO_PROXY' => 'example.com'], [], 'https://example.com/', false],
            'NO_PROXY parent domain' => [['HTTPS_PROXY' => $proxy, 'NO_PROXY' => 'example.com'], [], 'https://www.example.com/', false],
            'NO_PROXY leading dot, subdomain' => [['HTTPS_PROXY' => $proxy, 'NO_PROXY' => '.example.com'], [], 'https://www.example.com/', false],
            'NO_PROXY wildcard' => [['HTTPS_PROXY' => $proxy, 'NO_PROXY' => '*'], [], 'https://example.com/', false],
            'NO_PROXY list' => [['HTTPS_PROXY' => $proxy, 'NO_PROXY' => 'localhost, example.com'], [], 'https://example.com/', false],
            'NO_PROXY other host' => [['HTTPS_PROXY' => $proxy, 'NO_PROXY' => 'other.com'], [], 'https://example.com/', true],
            'NO_PROXY needs a label boundary' => [['HTTPS_PROXY' => $proxy, 'NO_PROXY' => 'example.com'], [], 'https://notexample.com/', true],
            // Guzzle 8 and libcurl ignore the leading dot; the own match of Guzzle 7.12+ does not and proxies the domain itself
            'NO_PROXY leading dot, the domain itself' => [['HTTPS_PROXY' => $proxy, 'NO_PROXY' => '.example.com'], [], 'https://example.com/', class_exists(ProxyEnvironment::class)],
            'no_proxy with https_proxy (libcurl rules)' => [['https_proxy' => $proxy, 'no_proxy' => '.example.com'], [], 'https://example.com/', false],
            // Guzzle builds its default from HTTPS_PROXY and NO_PROXY only; just libcurl (Guzzle 7 before 7.12) adds no_proxy
            'no_proxy and HTTPS_PROXY' => [['HTTPS_PROXY' => $proxy, 'no_proxy' => 'example.com'], [], 'https://example.com/', $guzzleResolves],
            'NO_PROXY and a proxy option' => [['NO_PROXY' => 'example.com'], ['proxy' => $proxy], 'https://example.com/', $guzzleResolves],
            'proxy option' => [[], ['proxy' => $proxy], 'https://example.com/', true],
            'proxy option for the scheme' => [[], ['proxy' => ['https' => $proxy]], 'https://example.com/', true],
            'proxy option for another scheme' => [[], ['proxy' => ['http' => $proxy]], 'https://example.com/', false],
            'proxy option with a no list' => [[], ['proxy' => ['https' => $proxy, 'no' => ['example.com']]], 'https://example.com/', false],
            'an empty proxy option switches the environment off' => [['HTTPS_PROXY' => $proxy], ['proxy' => ''], 'https://example.com/', false],
        ];
    }

    /**
     * @param  array<string, string>  $environment
     * @param  array<string, mixed>  $options
     */
    #[DataProvider('proxyDecisions')]
    public function test_proxy_in_effect_mirrors_guzzle_and_libcurl(array $environment, array $options, string $url, bool $proxied): void
    {
        $this->setProxyEnvironment($environment);

        $this->assertSame($proxied, UrlFetcher::proxyInEffect($url, $options));
    }

    public function test_a_guarded_fetch_through_a_proxy_is_refused_unless_allowed_and_then_warns_once(): void
    {
        $this->setProxyEnvironment(['HTTPS_PROXY' => 'http://proxy.local:3128']);
        Http::fake([
            'https://example.com/start' => Http::response('', 302, ['Location' => '/page']),
            'https://example.com/page' => Http::response(self::PAGE, 200, ['Content-Type' => 'text/html']),
        ]);
        $guarded = new FetchOptions(hopGuard: fn () => ['93.184.215.14']);

        $e = $this->failure('https://user:secret@example.com/start', $guarded);
        $this->assertSame('Refusing to fetch https://example.com/start through a proxy: address pinning cannot be enforced. Set bfsg.mcp.allow_proxy to true to allow it.', $e->getMessage());
        Http::assertNothingSent();

        config()->set('bfsg.mcp.allow_proxy', true);
        $page = $this->fetcher()->fetch('https://example.com/start', $guarded);

        $this->assertSame('https://example.com/page', $page->finalUrl);
        $this->assertSame(['Address pinning is not enforced because a proxy is used.'], $page->warnings, 'one warning, though both hops were proxied');
    }

    public function test_the_proxy_refusal_shows_the_url_without_controls_and_truncated(): void
    {
        $this->setProxyEnvironment(['HTTPS_PROXY' => 'http://proxy.local:3128']);
        $url = "https://example.com/\u{1B}]8;;x\u{07}".str_repeat('a', 500);

        $message = FetchFailed::proxyNotAllowed($url)->getMessage();

        $this->assertStringNotContainsString("\u{1B}", $message);
        $this->assertStringNotContainsString("\u{07}", $message);
        $this->assertStringNotContainsString(str_repeat('a', 300), $message);
        $this->assertStringContainsString('…', $message);
    }

    public function test_unguarded_fetches_and_bfsg_check_ignore_the_proxy_rule(): void
    {
        $this->setProxyEnvironment(['HTTPS_PROXY' => 'http://proxy.local:3128']);
        Http::fake(['https://example.com/*' => Http::response(self::PAGE, 200, ['Content-Type' => 'text/html'])]);

        $this->assertSame([], $this->fetcher()->fetch('https://example.com/')->warnings);
        $this->assertSame(0, Artisan::call('bfsg:check', ['url' => 'https://example.com/']));
        $this->assertStringNotContainsString('proxy', Artisan::output());
    }

    /** A page whose paragraph gets its colours from the stylesheets in $head (F3, F10). */
    private static function contrastPage(string $head): string
    {
        return '<!DOCTYPE html><html lang="en"><head><title>Page</title>'.$head.'</head><body><main><h1>Hi</h1><p class="t">Fine print</p></main></body></html>';
    }

    /** @return list<array{0: string, 1: Severity, 2: array<string, scalar>}> key, severity and params of each contrast finding */
    private static function contrast(string $html): array
    {
        return array_map(fn (Violation $violation) => [$violation->key, $violation->severity, $violation->params], (new Bfsg)->only(['contrast'])->analyze($html)->all());
    }

    // F3: stylesheets on other origins are not loaded, but one warning says how many, and the analysis knows it.
    public function test_cross_origin_stylesheets_give_one_fetch_warning(): void
    {
        Http::fake([
            'https://example.com/page' => Http::response(self::contrastPage('<link rel="stylesheet" href="https://cdn.example.net/build/app.css"><link rel="stylesheet" href="//static.example.org/x.css"><link rel="stylesheet" href="/off.css" disabled><link rel="stylesheet" media="(prefers-color-scheme: dark)" href="https://cdn.example.net/dark.css"><link rel="stylesheet" href="/app.css">'), 200),
            'https://example.com/one' => Http::response(self::contrastPage('<link rel="stylesheet" href="https://cdn.example.net/build/app.css">'), 200),
            'https://example.com/app.css' => Http::response('.t{color:#ccc;background:#fff}', 200),
        ]);

        $page = $this->fetcher()->fetch('https://example.com/page');

        $this->assertSame(['2 stylesheets on other origins were not inlined; contrast results are approximate.'], $page->warnings);
        $this->assertSame([
            ['contrast.insufficient', Severity::Warning, ['ratio' => '1.61', 'required' => 4.5, 'foreground' => '#cccccc', 'background' => '#ffffff']],
            ['contrast.stylesheets_missing', Severity::Notice, ['count' => 2]],
        ], self::contrast($page->html));
        Http::assertNotSent(fn (Request $request) => ! str_starts_with($request->url(), 'https://example.com/'));
        Http::assertNotSent(fn (Request $request) => $request->url() === 'https://example.com/off.css'); // a disabled link applies nothing
        $this->assertStringContainsString('<link rel="stylesheet" href="/off.css" disabled>', $page->html);

        $this->assertSame(['1 stylesheet on another origin was not inlined; contrast results are approximate.'], $this->fetcher()->fetch('https://example.com/one')->warnings);
        app()->setLocale('de');
        $this->assertSame(['2 Stylesheets anderer Ursprünge wurden nicht eingebunden; die Kontrastergebnisse sind Näherungen.'], $this->fetcher()->fetch('https://example.com/page')->warnings);
    }

    // F10: a leading @import of a same-origin sheet is loaded in its place, through the same loader.
    public function test_same_origin_import_is_loaded_and_counted(): void
    {
        Http::fake([
            'https://example.com/page' => Http::response(self::contrastPage('<link rel="stylesheet" href="/css/app.css">'), 200),
            'https://example.com/css/app.css' => Http::response('@import url("colors.css"); .x{margin:0}', 200),
            'https://example.com/css/colors.css' => Http::response('.t{color:#ccc;background:#fff}', 200),
        ]);

        $page = $this->fetcher()->fetch('https://example.com/page');

        $this->assertStringContainsString('<style data-bfsg-inlined="/css/app.css">.t{color:#ccc;background:#fff} .x{margin:0}</style>', $page->html);
        $this->assertSame([], $page->warnings);
        $this->assertSame([['contrast.insufficient', Severity::Error, ['ratio' => '1.61', 'required' => 4.5, 'foreground' => '#cccccc', 'background' => '#ffffff']]], self::contrast($page->html));

        $exit = Artisan::call('bfsg:check', ['url' => 'https://example.com/page', '--only' => 'contrast']);
        $output = Artisan::output();
        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('#cccccc', $output);
    }

    // F10: an import beyond fetch.max_stylesheets is not loaded: a warning, and the document stays incomplete.
    public function test_import_over_max_stylesheets_gives_a_warning(): void
    {
        config()->set('bfsg.fetch.max_stylesheets', 1);
        Http::fake([
            'https://example.com/page' => Http::response(self::contrastPage('<link rel="stylesheet" href="/css/app.css">'), 200),
            'https://example.com/css/app.css' => Http::response('@import url("colors.css"); .x{margin:0}', 200),
            'https://example.com/css/colors.css' => Http::response('.t{color:#ccc;background:#fff}', 200),
        ]);

        $page = $this->fetcher()->fetch('https://example.com/page');

        $this->assertSame(['Stylesheet colors.css (imported by /css/app.css) was not inlined: more than 1 stylesheets.'], $page->warnings);
        $this->assertStringContainsString('<style data-bfsg-inlined="/css/app.css">@import url("colors.css"); .x{margin:0}</style>', $page->html);
        $this->assertSame([['contrast.stylesheets_missing', Severity::Notice, ['count' => 1]]], self::contrast($page->html));
        Http::assertNotSent(fn (Request $request) => $request->url() === 'https://example.com/css/colors.css');
    }

    // F10: conditions and layers wrap the imported text; cycles end, depth is bounded, other origins and print are not loaded.
    public function test_imports_keep_their_conditions_and_layers_cycles_end_and_depth_is_bounded(): void
    {
        config()->set('bfsg.fetch.max_stylesheets', 10);
        Http::fake([
            'https://example.com/page' => Http::response(self::contrastPage('<link rel="stylesheet" href="/css/app.css">'), 200),
            'https://example.com/css/app.css' => Http::response('@charset "utf-8"; @layer base, utilities; @import url(dark.css) screen and (prefers-color-scheme: dark); @import "reset.css" layer(base); '
                .'@import url(grid.css) supports(display: grid); @import url(anon.css) layer; @import url(print.css) print; @import url(app.css); @import url(https://cdn.example.net/x.css); @import url(all.css) layer(base) supports(display:grid) screen; p{color:#111} @import url(late.css);', 200),
            'https://example.com/css/all.css' => Http::response('.all{color:#111}', 200),
            'https://example.com/css/dark.css' => Http::response('p{color:#eee}', 200),
            'https://example.com/css/reset.css' => Http::response('@import url(/css/app.css); @import url(a1.css); *{margin:0}', 200),
            'https://example.com/css/a1.css' => Http::response('@import url(a2.css); .a1{color:#111}', 200),
            'https://example.com/css/a2.css' => Http::response('@import url(a3.css); .a2{color:#111}', 200),
            'https://example.com/css/grid.css' => Http::response('.g{display:grid}', 200),
            'https://example.com/css/anon.css' => Http::response('.n{color:#111}', 200),
        ]);

        $page = $this->fetcher()->fetch('https://example.com/page');
        $css = HtmlDocument::fromHtml($page->html)->styleSheets()[0];

        $this->assertStringStartsWith('@import url("a3.css");@import url("https://cdn.example.net/x.css");@charset "utf-8"; @layer base, utilities; @media screen and (prefers-color-scheme: dark){p{color:#eee}} @layer base{', $css);
        $this->assertStringContainsString('.a1{color:#111}', $css);
        $this->assertStringContainsString('.a2{color:#111}', $css);
        $this->assertStringContainsString('*{margin:0}}', $css);
        $this->assertStringContainsString(' @supports (display: grid){.g{display:grid}} @layer{.n{color:#111}}', $css);
        $this->assertStringContainsString(' @media screen{@supports (display:grid){@layer base{.all{color:#111}}}}', $css, 'media outside supports outside layer');
        $this->assertStringEndsWith(' p{color:#111} @import url(late.css);', $css);
        $this->assertSame([
            'Stylesheet a3.css (imported by a2.css) was not inlined: imports nested more than 3 levels deep.',
            '1 stylesheet on another origin was not inlined; contrast results are approximate.',
        ], $page->warnings);
        $this->assertSame(2, HtmlDocument::fromHtml($page->html)->cssParser()->missingStylesheets());

        foreach (['print.css', 'late.css', 'a3.css'] as $file) {
            Http::assertNotSent(fn (Request $request) => $request->url() === 'https://example.com/css/'.$file);
        }

        Http::assertSentCount(9); // the page, app.css once, dark, reset, a1, a2, grid, anon, all: none twice
    }

    // F10: a sheet of 450 KB of imports costs one pass, at most max_stylesheets loads and at most 21 warnings.
    #[Group('performance')]
    public function test_a_sheet_of_twenty_five_thousand_imports_is_inlined_within_the_time_budget(): void
    {
        $imports = '';

        for ($i = 0; $i < 25000; $i++) {
            $imports .= "@import'i{$i}.css';";
        }

        $this->assertLessThan(512 * 1024, strlen($imports));
        Http::fake([
            'https://example.com/page' => Http::response(self::contrastPage('<link rel="stylesheet" href="/css/app.css">'), 200),
            'https://example.com/css/app.css' => Http::response($imports.'.t{color:#111}', 200),
            'https://example.com/css/*' => Http::response('/* unclosed', 200),
        ]);

        $start = microtime(true);
        $page = $this->fetcher()->fetch('https://example.com/page');
        $elapsed = microtime(true) - $start;

        $this->assertCount(21, $page->warnings);
        $this->assertSame('24976 more imported stylesheets were not inlined.', $page->warnings[20]);
        $this->assertSame(24996, HtmlDocument::fromHtml($page->html)->cssParser()->missingStylesheets());
        $this->assertStringContainsString(str_repeat('/* unclosed*/', 4).'.t{color:#111}</style>', $page->html, 'an unclosed comment of an imported sheet is closed');
        $this->assertLessThan(PerformanceBudget::seconds(1.0), $elapsed, sprintf('took %.3f s', $elapsed));
    }

    // Fix round 1: a byte order mark, braces in an unquoted url() and a stray `}` do not hide or misplace imports.
    public function test_a_byte_order_mark_braces_in_urls_and_a_stray_brace_keep_imports_in_place(): void
    {
        Http::fake([
            'https://example.com/bom' => Http::response(self::contrastPage('<link rel="stylesheet" href="/css/bom.css">'), 200),
            'https://example.com/css/bom.css' => Http::response("\xEF\xBB\xBF@import url(colors.css); .x{margin:0}", 200),
            'https://example.com/css/colors.css' => Http::response('.t{color:#ccc;background:#fff}', 200),
            'https://example.com/data' => Http::response(self::contrastPage('<link rel="stylesheet" href="/css/data.css">'), 200),
            'https://example.com/css/data.css' => Http::response('@import url(data:text/css,.q{color:red}); @import url(https://cdn.test/c.css); .t{color:#111}', 200),
            'https://example.com/stray' => Http::response(self::contrastPage('<link rel="stylesheet" href="/css/stray.css">'), 200),
            'https://example.com/css/stray.css' => Http::response('@import url(dark.css) (prefers-color-scheme: dark); .t{color:#111}', 200),
            'https://example.com/css/dark.css' => Http::response('.a{color:red}} .t{color:#eee}', 200),
        ]);

        $bom = $this->fetcher()->fetch('https://example.com/bom');
        $this->assertStringContainsString('<style data-bfsg-inlined="/css/bom.css">.t{color:#ccc;background:#fff} .x{margin:0}</style>', $bom->html);
        $this->assertSame([['contrast.insufficient', Severity::Error, ['ratio' => '1.61', 'required' => 4.5, 'foreground' => '#cccccc', 'background' => '#ffffff']]], self::contrast($bom->html));

        $data = $this->fetcher()->fetch('https://example.com/data');
        $this->assertSame(['2 stylesheets on other origins were not inlined; contrast results are approximate.'], $data->warnings);
        $this->assertSame(2, HtmlDocument::fromHtml($data->html)->cssParser()->missingStylesheets());

        $stray = $this->fetcher()->fetch('https://example.com/stray');
        $this->assertStringContainsString('@media (prefers-color-scheme: dark){.a{color:red} .t{color:#eee}} .t{color:#111}', $stray->html);
        $this->assertSame([], self::contrast($stray->html), 'the dark rule stays behind its preference');
    }

    // Fix round 2: slashes and braces inside blocks and unquoted url() tokens of an imported sheet are kept as they are.
    public function test_slashes_and_url_braces_in_an_imported_sheet_are_kept(): void
    {
        // `.c` comes last: the parser itself still splits rules at a `}` inside an unquoted url() (3.2 backlog).
        $imported = '.a{font:12px/1.5 serif} .t{color:#ccc;background:#fff} .b{background:url(/img/x.png);color:rgb(0 0 0 / 50%);grid-area:1 / -1;aspect-ratio:16/9} .c{background:url(a}b.png)}';

        Http::fake([
            'https://example.com/slash' => Http::response(self::contrastPage('<link rel="stylesheet" href="/css/main.css">'), 200),
            'https://example.com/css/main.css' => Http::response('@import url(parts.css); .z{margin:0}', 200),
            'https://example.com/css/parts.css' => Http::response($imported, 200),
        ]);

        $page = $this->fetcher()->fetch('https://example.com/slash');

        $this->assertStringContainsString('<style data-bfsg-inlined="/css/main.css">'.$imported.' .z{margin:0}</style>', $page->html);
        $this->assertSame([['contrast.insufficient', Severity::Error, ['ratio' => '1.61', 'required' => 4.5, 'foreground' => '#cccccc', 'background' => '#ffffff']]], self::contrast($page->html));
    }
}
