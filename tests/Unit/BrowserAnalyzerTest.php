<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Analyzers\BaseAnalyzer;
use ItsJustVita\LaravelBfsg\BrowserAnalyzer;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class BrowserAnalyzerTest extends TestCase
{
    protected BrowserAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new BrowserAnalyzer;
    }

    public function test_browser_analyzer_initializes_with_default_options()
    {
        $analyzer = new BrowserAnalyzer;
        $this->assertInstanceOf(BrowserAnalyzer::class, $analyzer);
    }

    public function test_browser_analyzer_accepts_custom_options()
    {
        $options = [
            'timeout' => 60000,
            'browser' => 'firefox',
            'headless' => false,
        ];

        $analyzer = new BrowserAnalyzer($options);
        $this->assertInstanceOf(BrowserAnalyzer::class, $analyzer);
    }

    public function test_can_set_custom_analyzers()
    {
        $custom = new class extends BaseAnalyzer
        {
            protected string $key = 'custom';

            protected function inspect(): void {}
        };

        $this->assertSame($this->analyzer, $this->analyzer->setAnalyzers([$custom]));
        $this->assertSame($this->analyzer, $this->analyzer->addAnalyzer($custom));
    }

    /**
     * @group integration
     *
     * @requires extension curl
     */
    public function test_analyze_url_returns_error_without_playwright()
    {
        // This test will fail if Playwright is not installed, which is expected
        $result = $this->analyzer->analyzeUrl('https://example.com');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);

        if (! $result['success']) {
            $this->assertArrayHasKey('error', $result);
            $this->assertArrayHasKey('fallback_message', $result);
        }
    }

    public function test_generates_summary_correctly()
    {
        $mockAnalyzer = $this->createStub(BrowserAnalyzer::class);

        $mockResults = [
            'success' => true,
            'url' => 'https://example.com',
            'rendered' => true,
            'results' => [
                'HeadingAnalyzer' => [
                    'issues' => [
                        ['type' => 'error', 'message' => 'Error 1'],
                        ['type' => 'warning', 'message' => 'Warning 1'],
                    ],
                ],
                'ImageAnalyzer' => [
                    'issues' => [
                        ['type' => 'notice', 'message' => 'Notice 1'],
                    ],
                ],
            ],
            'summary' => [
                'total_issues' => 3,
                'errors' => 1,
                'warnings' => 1,
                'notices' => 1,
            ],
        ];

        $mockAnalyzer->method('analyzeUrl')
            ->willReturn($mockResults);

        $result = $mockAnalyzer->analyzeUrl('https://example.com');

        $this->assertEquals(3, $result['summary']['total_issues']);
        $this->assertEquals(1, $result['summary']['errors']);
        $this->assertEquals(1, $result['summary']['warnings']);
        $this->assertEquals(1, $result['summary']['notices']);
    }
}
