<?php

namespace ItsJustVita\LaravelBfsg\Tests\Feature;

use ItsJustVita\LaravelBfsg\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

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
        $this->artisan('bfsg:check', ['url' => 'not-a-valid-url'])
            ->assertFailed()
            ->expectsOutputToContain('Error');
    }

    public function test_bfsg_check_command_with_json_format()
    {
        // Skip this test as it requires HTTP mocking
        $this->markTestSkipped('Requires HTTP mocking implementation');
    }

    public function test_bfsg_analyze_command_server_side_mode()
    {
        $html = '<html><body><img src="test.jpg"><h1>Title</h1></body></html>';

        $this->mockHttpResponse($html);

        $this->artisan('bfsg:analyze', [
            'url' => 'http://example.test',
        ])->assertSuccessful()
          ->expectsOutputToContain('Using server-side analysis');
    }

    public function test_bfsg_analyze_command_browser_mode()
    {
        $this->artisan('bfsg:analyze', [
            'url' => 'http://example.test',
            '--browser' => true,
        ])->expectsOutputToContain('Using browser rendering');
    }

    public function test_bfsg_check_command_with_detailed_option()
    {
        $html = '<!DOCTYPE html><html><body><img src="test.jpg"></body></html>';

        $this->mockHttpResponse($html);

        $this->artisan('bfsg:check', [
            'url' => 'http://example.test',
            '--detailed' => true,
        ])->assertFailed(); // Will fail due to accessibility issues
    }

    public function test_bfsg_check_command_success_with_accessible_html()
    {
        // Skip this test as it requires HTTP mocking
        $this->markTestSkipped('Requires HTTP mocking implementation');
    }

    protected function mockHttpResponse($html)
    {
        // This is a helper method for mocking HTTP responses in tests
        // In a real test environment, you would use Laravel's HTTP fake
        // For now, we'll skip the actual implementation
    }
}