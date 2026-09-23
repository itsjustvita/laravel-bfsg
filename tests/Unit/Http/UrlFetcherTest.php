<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit\Http;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelBfsg\Http\AuthenticatedHttpClient;
use ItsJustVita\LaravelBfsg\Http\FetchedPage;
use ItsJustVita\LaravelBfsg\Http\FetchFailed;
use ItsJustVita\LaravelBfsg\Http\FetchOptions;
use ItsJustVita\LaravelBfsg\Http\InProcessFetcher;
use ItsJustVita\LaravelBfsg\Http\UrlFetcher;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UrlFetcherTest extends TestCase
{
    private const PAGE = '<!DOCTYPE html><html lang="en"><head><title>Page</title></head><body><main><h1>Hi</h1></main></body></html>';

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

        $this->app['auth']->forgetGuards();
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
        $this->assertSame(['Stylesheet missing.css could not be loaded.'], $page->warnings);
        Http::assertNotSent(fn (Request $request) => in_array($request->url(), ['https://cdn.example.net/lib.css', 'https://example.com/print.css', 'https://example.com/alt.css'], true));
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
}
