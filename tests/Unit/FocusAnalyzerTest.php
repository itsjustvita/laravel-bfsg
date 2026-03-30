<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use DOMDocument;
use ItsJustVita\LaravelBfsg\Analyzers\FocusAnalyzer;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class FocusAnalyzerTest extends TestCase
{
    public function test_detects_inline_outline_none_on_button(): void
    {
        $html = '<button style="outline: none;">Click me</button>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $analyzer = new FocusAnalyzer;
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertCount(1, $violations);
        $this->assertEquals('error', $violations[0]['type']);
        $this->assertEquals('WCAG 2.4.7', $violations[0]['rule']);
        $this->assertStringContainsString('inline style', $violations[0]['message']);
    }

    public function test_detects_global_focus_reset_in_style_block(): void
    {
        $html = '<html><head><style>*:focus { outline: none; }</style></head><body><a href="#">Link</a></body></html>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $analyzer = new FocusAnalyzer;
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertNotEmpty($violations);
        $hasGlobalReset = false;
        foreach ($violations as $v) {
            if (str_contains($v['message'], 'Global focus outline reset')) {
                $hasGlobalReset = true;
            }
        }
        $this->assertTrue($hasGlobalReset, 'Expected a warning about global focus reset');
    }

    public function test_accepts_focus_removal_with_alternative(): void
    {
        $html = '<html><head><style>a:focus { outline: none; box-shadow: 0 0 3px blue; }</style></head><body><a href="#">Link</a></body></html>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $analyzer = new FocusAnalyzer;
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertEmpty($violations);
    }

    public function test_no_issues_without_focus_problems(): void
    {
        $html = '<html><head></head><body><a href="#">Link</a><button>Click</button></body></html>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $analyzer = new FocusAnalyzer;
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertEmpty($violations);
    }
}
