<?php

namespace ItsJustVita\LaravelBfsg\Tests\Feature;

use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class CommandsTest extends TestCase
{
    public function test_bfsg_check_command_exists()
    {
        $this->artisan('list')
            ->assertSuccessful()
            ->expectsOutputToContain('bfsg:check');
    }

    public function test_bfsg_analyze_command_exists()
    {
        $this->artisan('list')
            ->assertSuccessful()
            ->expectsOutputToContain('bfsg:analyze');
    }

    public function test_bfsg_check_command_with_invalid_url()
    {
        Http::fake([
            'http://invalid-server.example/*' => Http::response('', 500),
        ]);

        $this->artisan('bfsg:check', ['url' => 'http://invalid-server.example/page'])
            ->assertFailed()
            ->expectsOutputToContain('Error');
    }

    public function test_bfsg_check_command_with_json_format()
    {
        $html = '<!DOCTYPE html><html lang="en"><head><title>Test Page - Company</title></head><body>'
            .'<header><nav><a href="#main">Skip to content</a></nav></header>'
            .'<main id="main"><h1>Welcome</h1>'
            .'<img src="photo.jpg" alt="A descriptive alt text">'
            .'<form aria-label="Contact"><label for="email">Email</label>'
            .'<input type="email" id="email" name="email" autocomplete="email"></form>'
            .'<a href="/about">Learn more about our company</a>'
            .'<div aria-live="polite"></div>'
            .'</main><footer><p>Footer content</p></footer>'
            .'</body></html>';

        Http::fake([
            'http://example.com/*' => Http::response($html, 200),
        ]);

        $this->artisan('bfsg:check', [
            'url' => 'http://example.com/page',
            '--format' => 'json',
        ])->assertSuccessful();
    }

    public function test_bfsg_check_command_with_violations()
    {
        $html = '<!DOCTYPE html><html><body><img src="test.jpg"></body></html>';

        Http::fake([
            'http://example.com/*' => Http::response($html, 200),
        ]);

        $this->artisan('bfsg:check', ['url' => 'http://example.com/page'])
            ->assertFailed();
    }

    public function test_bfsg_check_command_with_detailed_option()
    {
        $html = '<!DOCTYPE html><html><body><img src="test.jpg"></body></html>';

        Http::fake([
            'http://example.com/*' => Http::response($html, 200),
        ]);

        $this->artisan('bfsg:check', [
            'url' => 'http://example.com/page',
            '--detailed' => true,
        ])->assertFailed();
    }

    public function test_bfsg_check_command_success_with_accessible_html()
    {
        $html = '<!DOCTYPE html><html lang="en"><head><title>Test Page - Company</title></head><body>'
            .'<header><nav><a href="#main">Skip to content</a></nav></header>'
            .'<main id="main"><h1>Welcome</h1>'
            .'<img src="photo.jpg" alt="A descriptive alt text">'
            .'<form aria-label="Contact"><label for="email">Email</label>'
            .'<input type="email" id="email" name="email" autocomplete="email"></form>'
            .'<a href="/about">Learn more about our company</a>'
            .'<div aria-live="polite"></div>'
            .'</main><footer><p>Footer content</p></footer>'
            .'</body></html>';

        Http::fake([
            'http://example.com/*' => Http::response($html, 200),
        ]);

        $this->artisan('bfsg:check', ['url' => 'http://example.com/page'])
            ->assertSuccessful();
    }

    public function test_bfsg_analyze_command_server_side_mode()
    {
        $html = '<!DOCTYPE html><html lang="en"><head><title>Test</title></head>'
            .'<body><h1>Title</h1></body></html>';

        Http::fake([
            'http://example.com/*' => Http::response($html, 200),
        ]);

        $this->artisan('bfsg:analyze', [
            'url' => 'http://example.com/page',
        ])->assertSuccessful()
            ->expectsOutputToContain('Using server-side analysis');
    }

    public function test_bfsg_analyze_command_browser_mode()
    {
        $this->artisan('bfsg:analyze', [
            'url' => 'http://example.com/page',
            '--browser' => true,
        ])->expectsOutputToContain('Using browser rendering');
    }

    protected function accessibleHtml(): string
    {
        return '<!DOCTYPE html><html lang="en"><head><title>Test Page - Company</title></head><body>'
            .'<header><nav><a href="#main">Skip to content</a></nav></header>'
            .'<main id="main"><h1>Welcome</h1>'
            .'<img src="photo.jpg" alt="A descriptive alt text">'
            .'<form aria-label="Contact"><label for="email">Email</label>'
            .'<input type="email" id="email" name="email" autocomplete="email"></form>'
            .'<a href="/about">Learn more about our company</a>'
            .'<div aria-live="polite"></div>'
            .'</main><footer><p>Footer content</p></footer>'
            .'</body></html>';
    }

    public function test_bfsg_check_with_jwt_auth_without_auth_flag()
    {
        Http::fake(['http://example.com/*' => Http::response($this->accessibleHtml(), 200)]);

        $this->artisan('bfsg:check', [
            'url' => 'http://example.com/page',
            '--jwt' => 'jwt-token-123',
        ])->assertSuccessful();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer jwt-token-123'));
    }

    public function test_bfsg_check_with_api_key_auth_without_auth_flag()
    {
        Http::fake(['http://example.com/*' => Http::response($this->accessibleHtml(), 200)]);

        $this->artisan('bfsg:check', [
            'url' => 'http://example.com/page',
            '--api-key' => 'key-456',
            '--api-key-header' => 'X-Custom-Auth',
        ])->assertSuccessful();

        Http::assertSent(fn ($request) => $request->hasHeader('X-Custom-Auth', 'key-456'));
    }

    public function test_bfsg_check_resolves_default_login_url_against_origin()
    {
        Http::fake([
            'https://example.com/login' => Http::response('', 302, ['Set-Cookie' => 'laravel_session=abc; Path=/']),
            'https://example.com/dashboard' => Http::response($this->accessibleHtml(), 200),
            '*' => Http::response('Not Found', 404),
        ]);

        $this->artisan('bfsg:check', [
            'url' => 'https://example.com/dashboard',
            '--auth' => true,
            '--email' => 'user@example.com',
            '--password' => 'secret',
        ])->assertSuccessful();

        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://example.com/login');
    }

    public function test_bfsg_check_resolves_relative_login_url_against_origin()
    {
        Http::fake([
            'https://example.com/admin/login' => Http::response('', 302, ['Set-Cookie' => 'laravel_session=abc; Path=/']),
            'https://example.com/dashboard' => Http::response($this->accessibleHtml(), 200),
            '*' => Http::response('Not Found', 404),
        ]);

        $this->artisan('bfsg:check', [
            'url' => 'https://example.com/dashboard',
            '--auth' => true,
            '--email' => 'user@example.com',
            '--password' => 'secret',
            '--login-url' => '/admin/login',
        ])->assertSuccessful();

        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://example.com/admin/login');
    }

    public function test_bfsg_check_accepts_absolute_login_url()
    {
        Http::fake([
            'https://auth.example.com/login' => Http::response('', 302, ['Set-Cookie' => 'laravel_session=abc; Path=/']),
            'https://example.com/dashboard' => Http::response($this->accessibleHtml(), 200),
            '*' => Http::response('Not Found', 404),
        ]);

        $this->artisan('bfsg:check', [
            'url' => 'https://example.com/dashboard',
            '--auth' => true,
            '--email' => 'user@example.com',
            '--password' => 'secret',
            '--login-url' => 'https://auth.example.com/login',
        ])->assertSuccessful();

        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://auth.example.com/login');
    }

    public function test_bfsg_check_sanctum_uses_origin_for_csrf_cookie()
    {
        Http::fake([
            'https://app.example.com/sanctum/csrf-cookie' => Http::response('', 204, [
                'Set-Cookie' => ['XSRF-TOKEN=csrf; Path=/', 'laravel_session=abc; Path=/'],
            ]),
            'https://app.example.com/login' => Http::response(['message' => 'ok'], 200),
            'https://app.example.com/dashboard' => Http::response($this->accessibleHtml(), 200),
            '*' => Http::response('Not Found', 404),
        ]);

        $this->artisan('bfsg:check', [
            'url' => 'https://app.example.com/dashboard',
            '--auth' => true,
            '--sanctum' => true,
            '--email' => 'user@example.com',
            '--password' => 'secret',
        ])->assertSuccessful();

        Http::assertSent(fn ($request) => $request->url() === 'https://app.example.com/sanctum/csrf-cookie');
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://app.example.com/login');
    }

    public function test_bfsg_check_applies_verify_ssl_option_to_login_request()
    {
        $verify = [];

        Http::fake(function ($request, $options) use (&$verify) {
            $verify[$request->url()] = $options['verify'] ?? true;

            return match ($request->url()) {
                'https://example.com/login' => Http::response('', 302, ['Set-Cookie' => 'laravel_session=abc; Path=/']),
                default => Http::response($this->accessibleHtml(), 200),
            };
        });

        $this->artisan('bfsg:check', [
            'url' => 'https://example.com/dashboard',
            '--auth' => true,
            '--email' => 'user@example.com',
            '--password' => 'secret',
            '--verify-ssl' => 'false',
        ])->assertSuccessful();

        $this->assertFalse($verify['https://example.com/login']);
        $this->assertFalse($verify['https://example.com/dashboard']);
    }

    public function test_bfsg_check_with_bearer_auth()
    {
        $html = '<!DOCTYPE html><html lang="en"><head><title>Test Page - Company</title></head><body>'
            .'<header><nav><a href="#main">Skip to content</a></nav></header>'
            .'<main id="main"><h1>Welcome</h1>'
            .'<img src="photo.jpg" alt="A descriptive alt text">'
            .'<form aria-label="Contact"><label for="email">Email</label>'
            .'<input type="email" id="email" name="email" autocomplete="email"></form>'
            .'<a href="/about">Learn more about our company</a>'
            .'<div aria-live="polite"></div>'
            .'</main><footer><p>Footer content</p></footer>'
            .'</body></html>';

        Http::fake([
            'http://example.com/*' => Http::response($html, 200),
        ]);

        $this->artisan('bfsg:check', [
            'url' => 'http://example.com/page',
            '--bearer' => 'test-token-123',
        ])->assertSuccessful();

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer test-token-123');
        });
    }
}
