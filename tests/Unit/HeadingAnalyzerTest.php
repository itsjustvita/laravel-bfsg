<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use DOMDocument;
use ItsJustVita\LaravelBfsg\Analyzers\HeadingAnalyzer;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class HeadingAnalyzerTest extends TestCase
{
    public function test_detects_missing_h1(): void
    {
        $html = '<h2>Section</h2><p>Content</p>';
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $analyzer = new HeadingAnalyzer();
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertCount(1, $violations);
        $this->assertEquals('No h1 heading found on the page', $violations[0]['message']);
        $this->assertEquals('warning', $violations[0]['type']);
    }

    public function test_detects_broken_heading_hierarchy(): void
    {
        $html = '<h1>Main</h1><h3>Skipped h2</h3>';
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $analyzer = new HeadingAnalyzer();
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('Heading hierarchy broken', $violations[0]['message']);
    }

    public function test_accepts_proper_heading_hierarchy(): void
    {
        $html = '<h1>Main</h1><h2>Section</h2><h3>Subsection</h3>';
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $analyzer = new HeadingAnalyzer();
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertEmpty($violations);
    }

    public function test_detects_empty_headings(): void
    {
        $html = '<h1></h1><h2>   </h2>';
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $analyzer = new HeadingAnalyzer();
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertNotEmpty($violations);
        $this->assertStringContainsString('Empty h1 heading found', $violations[0]['message']);
    }

    public function test_warns_about_multiple_h1_tags(): void
    {
        $html = '<h1>First</h1><h1>Second</h1>';
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $analyzer = new HeadingAnalyzer();
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $messages = array_column($violations, 'message');
        $this->assertContains('Multiple h1 headings found (2 total)', $messages);
    }
}