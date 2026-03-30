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
        $html = '<!DOCTYPE html><html lang="en"><head><title>Test Page</title></head><body>'
            .'<header><nav><a href="#main">Skip to content</a></nav></header>'
            .'<main id="main"><h1>Welcome</h1>'
            .'<img src="photo.jpg" alt="A descriptive alt text">'
            .'<form aria-label="Contact"><label for="email">Email</label>'
            .'<input type="email" id="email" name="email"></form>'
            .'<a href="/about">Learn more about our company</a>'
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
        $html = '<!DOCTYPE html><html lang="en"><head><title>Test Page</title></head><body>'
            .'<header><nav><a href="#main">Skip to content</a></nav></header>'
            .'<main id="main"><h1>Welcome</h1>'
            .'<img src="photo.jpg" alt="A descriptive alt text">'
            .'<form aria-label="Contact"><label for="email">Email</label>'
            .'<input type="email" id="email" name="email"></form>'
            .'<a href="/about">Learn more about our company</a>'
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

    public function test_bfsg_check_with_bearer_auth()
    {
        $html = '<!DOCTYPE html><html lang="en"><head><title>Test Page</title></head><body>'
            .'<header><nav><a href="#main">Skip to content</a></nav></header>'
            .'<main id="main"><h1>Welcome</h1>'
            .'<img src="photo.jpg" alt="A descriptive alt text">'
            .'<form aria-label="Contact"><label for="email">Email</label>'
            .'<input type="email" id="email" name="email"></form>'
            .'<a href="/about">Learn more about our company</a>'
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
