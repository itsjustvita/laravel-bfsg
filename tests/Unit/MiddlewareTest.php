<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use ItsJustVita\LaravelBfsg\Middleware\CheckAccessibility;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class MiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Enable middleware by default for tests
        config()->set('bfsg.middleware.enabled', true);
        config()->set('bfsg.middleware.log_violations', false);
        config()->set('bfsg.middleware.ignored_paths', []);
        config()->set('app.debug', true);
    }

    public function test_skips_non_get_requests(): void
    {
        $request = Request::create('/test', 'POST');
        $response = new Response('<html><body><img src="test.jpg"></body></html>', 200, ['Content-Type' => 'text/html']);

        $middleware = new CheckAccessibility;
        $result = $middleware->handle($request, function () use ($response) {
            return $response;
        });

        $this->assertFalse($result->headers->has('X-BFSG-Violations'));
    }

    public function test_skips_non_html_responses(): void
    {
        $request = Request::create('/test', 'GET');
        $response = new Response('{"key": "value"}', 200, ['Content-Type' => 'application/json']);

        $middleware = new CheckAccessibility;
        $result = $middleware->handle($request, function () use ($response) {
            return $response;
        });

        $this->assertFalse($result->headers->has('X-BFSG-Violations'));
    }

    public function test_skips_when_disabled(): void
    {
        config()->set('bfsg.middleware.enabled', false);

        $request = Request::create('/test', 'GET');
        $response = new Response('<html><body><img src="test.jpg"></body></html>', 200, ['Content-Type' => 'text/html']);

        $middleware = new CheckAccessibility;
        $result = $middleware->handle($request, function () use ($response) {
            return $response;
        });

        $this->assertFalse($result->headers->has('X-BFSG-Violations'));
    }

    public function test_skips_ignored_paths(): void
    {
        config()->set('bfsg.middleware.ignored_paths', ['admin/*']);

        $request = Request::create('/admin/dashboard', 'GET');
        $response = new Response('<html><body><img src="test.jpg"></body></html>', 200, ['Content-Type' => 'text/html']);

        $middleware = new CheckAccessibility;
        $result = $middleware->handle($request, function () use ($response) {
            return $response;
        });

        $this->assertFalse($result->headers->has('X-BFSG-Violations'));
    }

    public function test_detects_violations_and_adds_debug_header(): void
    {
        config()->set('app.debug', true);

        $html = '<html><body><img src="test.jpg"></body></html>';
        $request = Request::create('/test', 'GET');
        $response = new Response($html, 200, ['Content-Type' => 'text/html']);

        $middleware = new CheckAccessibility;
        $result = $middleware->handle($request, function () use ($response) {
            return $response;
        });

        $this->assertTrue($result->headers->has('X-BFSG-Violations'));
        $this->assertGreaterThan(0, (int) $result->headers->get('X-BFSG-Violations'));
    }

    public function test_does_not_add_header_when_not_debug(): void
    {
        config()->set('app.debug', false);

        $html = '<html><body><img src="test.jpg"></body></html>';
        $request = Request::create('/test', 'GET');
        $response = new Response($html, 200, ['Content-Type' => 'text/html']);

        $middleware = new CheckAccessibility;
        $result = $middleware->handle($request, function () use ($response) {
            return $response;
        });

        $this->assertFalse($result->headers->has('X-BFSG-Violations'));
    }

    public function test_logs_violations_when_enabled(): void
    {
        config()->set('bfsg.middleware.log_violations', true);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, 'BFSG:');
            });

        $html = '<html><body><img src="test.jpg"></body></html>';
        $request = Request::create('/test', 'GET');
        $response = new Response($html, 200, ['Content-Type' => 'text/html']);

        $middleware = new CheckAccessibility;
        $middleware->handle($request, function () use ($response) {
            return $response;
        });
    }

    public function test_no_violations_for_accessible_html(): void
    {
        config()->set('app.debug', true);

        $html = '<!DOCTYPE html><html lang="en"><head><title>Test</title></head><body>'
            .'<a href="#main" class="skip-link">Skip to main content</a>'
            .'<header><h1>Page Title</h1></header>'
            .'<nav><a href="/about">About us</a></nav>'
            .'<main id="main">'
            .'<p>Some accessible content.</p>'
            .'<img src="photo.jpg" alt="A descriptive alt text">'
            .'</main>'
            .'<footer><p>Footer content</p></footer>'
            .'</body></html>';

        $request = Request::create('/test', 'GET');
        $response = new Response($html, 200, ['Content-Type' => 'text/html']);

        $middleware = new CheckAccessibility;
        $result = $middleware->handle($request, function () use ($response) {
            return $response;
        });

        $this->assertFalse($result->headers->has('X-BFSG-Violations'));
    }
}
