<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelBfsg\Http\AuthenticatedHttpClient;
use ItsJustVita\LaravelBfsg\Http\AuthenticationFailed;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class AuthenticatedHttpClientTest extends TestCase
{
    private const LOGIN_PAGE = '<html><head><meta name="csrf-token" content="meta-token"></head><body><form method="POST" action="/login">'
        .'<input type="hidden" name="_token" value="form-token"><input name="email"><input name="password" type="password"></form></body></html>';

    private function client(): AuthenticatedHttpClient
    {
        return new AuthenticatedHttpClient;
    }

    /** A Laravel-like login page: GET sets XSRF-TOKEN + session, POST answers with $post. */
    private function fakeLogin(\Closure|array $post, string $url = 'https://app.example.com/login'): void
    {
        Http::fake(function (Request $request) use ($post, $url) {
            if ($request->url() === $url && $request->method() === 'GET') {
                return Http::response(self::LOGIN_PAGE, 200, ['Set-Cookie' => ['XSRF-TOKEN=xsrf%3Dvalue; Path=/', 'app_session=guest; Path=/; HttpOnly']]);
            }

            if ($request->url() === $url && $request->method() === 'POST') {
                return is_array($post) ? Http::response(...$post) : $post($request);
            }

            return Http::response('<html><body>Dashboard</body></html>', 200);
        });
    }

    private function failure(\Closure $attempt): AuthenticationFailed
    {
        try {
            $attempt();
        } catch (AuthenticationFailed $e) {
            return $e;
        }

        $this->fail('AuthenticationFailed was not thrown');
    }

    public function test_form_login_sends_the_csrf_token_and_the_cookies_of_the_login_page(): void
    {
        $this->fakeLogin(['', 302, ['Location' => 'https://app.example.com/dashboard', 'Set-Cookie' => 'app_session=authenticated; Path=/; HttpOnly']]);

        $client = $this->client();
        $client->loginWithForm('https://app.example.com/login', 'user@example.com', 'secret', ['remember' => '1']);
        $client->get('https://app.example.com/dashboard');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request['_token'] === 'form-token'
            && $request['email'] === 'user@example.com'
            && $request['password'] === 'secret'
            && $request['remember'] === '1'
            && $request->hasHeader('X-XSRF-TOKEN', 'xsrf=value')
            && str_contains($request->header('Cookie')[0] ?? '', 'app_session=guest'));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://app.example.com/dashboard'
            && str_contains($request->header('Cookie')[0] ?? '', 'app_session=authenticated')
            && str_contains($request->header('Cookie')[0] ?? '', 'XSRF-TOKEN=xsrf%3Dvalue'));
    }

    public function test_form_login_uses_custom_field_names(): void
    {
        $this->fakeLogin(['', 302, ['Location' => '/home']]);

        $this->client()->loginWithForm('https://app.example.com/login', 'jane', 'pw', [], ['username' => 'username', 'password' => 'pass']);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request['username'] === 'jane' && $request['pass'] === 'pw' && ! isset($request['email']));
    }

    public function test_form_login_with_a_2xx_answer_needs_a_session_cookie_and_no_login_form(): void
    {
        $this->fakeLogin(['<html>ok</html>', 200]);

        $this->client()->loginWithForm('https://app.example.com/login', 'u', 'p'); // app_session from the login page
        $this->assertSame('no_session', $this->failure(fn () => $this->client()->loginWithForm('https://other.example.com/login', 'u', 'p'))->reason);
    }

    public function test_a_2xx_answer_that_renders_the_login_form_again_is_a_failure(): void
    {
        $this->fakeLogin([self::LOGIN_PAGE, 200]); // "These credentials do not match" re-rendered with 200, guest session unchanged

        $this->assertSame('credentials', $this->failure(fn () => $this->client()->loginWithForm('https://app.example.com/login', 'u', 'wrong'))->reason);
    }

    public function test_a_2xx_login_form_with_a_regenerated_session_is_a_success(): void
    {
        $this->fakeLogin([self::LOGIN_PAGE, 200, ['Set-Cookie' => 'app_session=regenerated; Path=/']]);

        $this->client()->loginWithForm('https://app.example.com/login', 'u', 'p');
        $this->addToAssertionCount(1);
    }

    public function test_a_two_factor_challenge_is_a_failure(): void
    {
        $this->fakeLogin(['', 302, ['Location' => '/two-factor-challenge']]);
        $this->assertSame('two_factor', $this->failure(fn () => $this->client()->loginWithForm('https://app.example.com/login', 'u', 'p'))->reason);
    }

    public function test_a_json_two_factor_answer_is_a_failure(): void
    {
        Http::fake(['*' => Http::response(['two_factor' => true], 200, ['Set-Cookie' => 'api_session=pending; Path=/'])]);
        $this->assertSame('two_factor', $this->failure(fn () => $this->client()->loginWithJson('https://api.example.com/login', 'u', 'p'))->reason);
    }

    public function test_json_login_redirected_back_to_the_login_page_is_a_failure(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => '/login', 'Set-Cookie' => 'api_session=guest; Path=/'])]);

        $this->assertSame('credentials', $this->failure(fn () => $this->client()->loginWithJson('https://api.example.com/login', 'u', 'p'))->reason);
    }

    public function test_sanctum_login_needs_a_regenerated_session(): void
    {
        Http::fake([
            'https://spa.example.com/sanctum/csrf-cookie' => Http::response('', 204, ['Set-Cookie' => ['XSRF-TOKEN=abc; Path=/', 'spa_session=guest; Path=/']]),
            '*' => Http::response('', 204),
        ]);

        $this->assertSame('no_session', $this->failure(fn () => $this->client()->loginWithSanctum('https://spa.example.com', 'u', 'p'))->reason);
    }

    public function test_form_login_failures_name_their_cause(): void
    {
        $post = [];
        $this->fakeLogin(function () use (&$post) {
            return Http::response(...$post);
        });

        $cases = [
            'csrf' => ['', 419],
            'validation' => [['message' => 'The given data was invalid.', 'errors' => ['email' => ['These credentials do not match our records.']]], 422],
            'credentials' => ['', 401],
        ];

        foreach ($cases as $reason => $response) {
            $post = $response;
            $failure = $this->failure(fn () => $this->client()->loginWithForm('https://app.example.com/login', 'u', 'p'));

            $this->assertSame($reason, $failure->reason, $reason);
            $this->assertStringContainsString('https://app.example.com/login', $failure->getMessage());
        }

        $this->assertStringContainsString('These credentials do not match our records.', $this->failure(function () use (&$post) {
            $post = [['errors' => ['email' => ['These credentials do not match our records.']]], 422];
            $this->client()->loginWithForm('https://app.example.com/login', 'u', 'p');
        })->getMessage());
    }

    public function test_a_redirect_back_to_the_login_page_means_invalid_credentials(): void
    {
        $this->fakeLogin(['', 302, ['Location' => '/login']]);

        $failure = $this->failure(fn () => $this->client()->loginWithForm('https://app.example.com/login', 'u', 'wrong'));

        $this->assertSame('credentials', $failure->reason);
        $this->assertNull($failure->status);
    }

    public function test_an_unreachable_or_missing_login_page_fails_early(): void
    {
        Http::fake(fn (Request $request) => $request->url() === 'https://nowhere.invalid/login'
            ? throw new ConnectionException('cURL error 6: Could not resolve host')
            : Http::response('Not Found', 404));

        $this->assertSame('login_page', $this->failure(fn () => $this->client()->loginWithForm('https://app.example.com/login', 'u', 'p'))->reason);
        $failure = $this->failure(fn () => $this->client()->loginWithForm('https://nowhere.invalid/login', 'u', 'p'));
        $this->assertSame('connection', $failure->reason);
        $this->assertStringContainsString('Could not resolve host', $failure->getMessage());
    }

    public function test_json_login_turns_a_token_into_a_bearer_header(): void
    {
        $payloads = ['a' => ['token' => 't-a'], 'b' => ['access_token' => 't-b', 'token_type' => 'Bearer'], 'c' => ['data' => ['token' => 't-c']]];

        Http::fake(function (Request $request) use ($payloads) {
            preg_match('~https://api\.example\.com/(\w)/login~', $request->url(), $m);

            return $m === [] ? Http::response('{}', 200) : Http::response($payloads[$m[1]], 200);
        });

        foreach (array_keys($payloads) as $api) {
            $client = $this->client();
            $client->loginWithJson("https://api.example.com/$api/login", 'user@example.com', 'secret', ['device' => 'ci']);
            $client->get("https://api.example.com/$api/data");

            Http::assertSent(fn (Request $request) => $request->url() === "https://api.example.com/$api/data" && $request->hasHeader('Authorization', "Bearer t-$api"));
        }

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.example.com/a/login'
            && $request->isJson() && $request['email'] === 'user@example.com' && $request['device'] === 'ci'
            && $request->hasHeader('Accept', 'application/json'));
    }

    public function test_json_login_without_token_or_session_fails(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->assertSame('no_session', $this->failure(fn () => $this->client()->loginWithJson('https://api.example.com/login', 'u', 'p'))->reason);
    }

    public function test_sanctum_login_uses_the_csrf_cookie_origin_and_referer(): void
    {
        Http::fake([
            'https://spa.example.com/sanctum/csrf-cookie' => Http::response('', 204, ['Set-Cookie' => ['XSRF-TOKEN=abc%3D; Path=/', 'spa_session=guest; Path=/']]),
            'https://spa.example.com/auth/login' => Http::response('', 204, ['Set-Cookie' => 'spa_session=user; Path=/']),
            '*' => Http::response('<html></html>', 200),
        ]);

        $client = $this->client();
        $client->loginWithSanctum('https://spa.example.com/', 'user@example.com', 'secret', '/auth/login');
        $client->get('https://spa.example.com/dashboard');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://spa.example.com/auth/login'
            && $request->hasHeader('X-XSRF-TOKEN', 'abc=')
            && $request->hasHeader('Origin', 'https://spa.example.com')
            && $request->hasHeader('Referer', 'https://spa.example.com/')
            && str_contains($request->header('Cookie')[0] ?? '', 'spa_session=guest'));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://spa.example.com/dashboard' && str_contains($request->header('Cookie')[0] ?? '', 'spa_session=user'));
    }

    public function test_sanctum_login_without_xsrf_cookie_is_a_csrf_failure(): void
    {
        Http::fake(['*' => Http::response('', 204)]);

        $this->assertSame('csrf', $this->failure(fn () => $this->client()->loginWithSanctum('https://spa.example.com', 'u', 'p'))->reason);
    }

    public function test_every_set_cookie_header_is_stored_and_sent_back(): void
    {
        Http::fake([
            'https://example.com/start' => Http::response('', 200, ['Set-Cookie' => ['a=1; Path=/', 'b=2; Path=/', 'laravel_session=s; Path=/; HttpOnly']]),
            '*' => Http::response('', 200),
        ]);

        $client = $this->client();
        $client->get('https://example.com/start');
        $client->get('https://example.com/next');
        $client->get('https://elsewhere.example.org/');

        $this->assertTrue($client->hasSessionCookie('https://example.com/'));
        $this->assertFalse($client->hasSessionCookie('https://elsewhere.example.org/'));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://example.com/next' && ($request->header('Cookie')[0] ?? '') === 'a=1; b=2; laravel_session=s');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://elsewhere.example.org/' && ! $request->hasHeader('Cookie'));
    }

    public function test_the_app_session_cookie_name_counts_for_the_same_app(): void
    {
        config()->set('app.url', 'https://shop.example.com');
        config()->set('session.cookie', 'shopcookie');
        Http::fake(['*' => Http::response('', 200, ['Set-Cookie' => 'shopcookie=x; Path=/'])]);

        $client = $this->client();
        $client->get('https://shop.example.com/');

        $this->assertTrue($client->hasSessionCookie('https://shop.example.com/cart'));
    }

    public function test_token_header_and_session_cookie_helpers(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $client = $this->client()
            ->withJwt('jwt-1')
            ->withApiKey('key-2', 'X-Custom-Key')
            ->withHeaders(['X-Tenant' => 'acme'])
            ->withSessionCookie('laravel_session', 'from-browser', 'https://app.example.com/dashboard');
        $client->get('https://app.example.com/dashboard');

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer jwt-1')
            && $request->hasHeader('X-Custom-Key', 'key-2')
            && $request->hasHeader('X-Tenant', 'acme')
            && ($request->header('Cookie')[0] ?? '') === 'laravel_session=from-browser');
        $this->assertSame('Bearer b', $this->client()->withBearer('b')->headers()['Authorization']);
    }

    public function test_request_factory_applies_timeout_tls_user_agent_and_no_redirects(): void
    {
        config()->set('bfsg.fetch.timeout', 12);
        config()->set('bfsg.fetch.verify_ssl', false);
        config()->set('bfsg.fetch.user_agent', 'bfsg-test/1.0');
        $options = [];

        Http::fake(function (Request $request, array $requestOptions) use (&$options) {
            $options = $requestOptions;

            return Http::response('', 302, ['Location' => '/elsewhere']);
        });

        $response = $this->client()->get('https://self-signed.test/');

        $this->assertSame(302, $response->status(), 'redirects are not followed');
        $this->assertFalse($options['verify']);
        $this->assertSame(12, $options['timeout']);
        $this->assertFalse($options['allow_redirects']);
        Http::assertSent(fn (Request $request) => $request->hasHeader('User-Agent', 'bfsg-test/1.0'));
        $this->assertTrue((new AuthenticatedHttpClient(verifySsl: true))->verifiesSsl());
        $this->assertFalse((new AuthenticatedHttpClient)->withVerifySsl(false)->verifiesSsl());
    }

    public function test_credentials_are_read_from_the_environment(): void
    {
        putenv('BFSG_AUTH_EMAIL=ci@example.com');
        putenv('BFSG_AUTH_PASSWORD=from-env');
        putenv('BFSG_AUTH_TOKEN');

        try {
            $this->assertSame(['email' => 'ci@example.com', 'password' => 'from-env', 'token' => null], AuthenticatedHttpClient::credentialsFromEnv());
        } finally {
            putenv('BFSG_AUTH_EMAIL');
            putenv('BFSG_AUTH_PASSWORD');
        }
    }

    public function test_credential_headers_are_bound_to_one_origin(): void
    {
        Http::fake(['https://api.example.com/login' => Http::response(['token' => 'from-login'], 200), '*' => Http::response('', 200)]);

        $unbound = $this->client()->withBearer('first-origin');
        $unbound->get('https://app.example.com/a');
        $unbound->get('https://app.example.com:444/b');
        $unbound->get('https://elsewhere.example.org/c');

        $login = $this->client();
        $login->loginWithJson('https://api.example.com/login', 'u', 'p');
        $login->get('https://api.example.com/data');
        $login->get('http://api.example.com/data');
        $login->get('https://cdn.example.net/x');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://app.example.com/a' && $request->hasHeader('Authorization', 'Bearer first-origin'));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.example.com/data' && $request->hasHeader('Authorization', 'Bearer from-login'));

        foreach (['https://app.example.com:444/b', 'https://elsewhere.example.org/c', 'http://api.example.com/data', 'https://cdn.example.net/x'] as $url) {
            Http::assertNotSent(fn (Request $request) => $request->url() === $url && $request->hasHeader('Authorization'));
        }
    }

    public function test_a_given_session_cookie_is_host_only_and_never_sent_over_http(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $client = $this->client()->withSessionCookie('laravel_session', 'from-browser', 'https://app.example.com/dashboard');
        $this->assertTrue($client->hasSessionCookie('https://app.example.com/'));
        $client->get('https://sub.app.example.com/');
        $client->get('http://app.example.com/');

        Http::assertNotSent(fn (Request $request) => $request->hasHeader('Cookie'));
    }
}
