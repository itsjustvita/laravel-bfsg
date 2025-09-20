<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use DOMDocument;
use ItsJustVita\LaravelBfsg\Analyzers\ContrastAnalyzer;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class ContrastAnalyzerTest extends TestCase
{
    protected ContrastAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new ContrastAnalyzer();
    }

    public function test_detects_low_contrast_text()
    {
        $html = '
            <p style="color: #999; background-color: #fff;">Low contrast text</p>
            <p style="color: #aaa; background-color: #fff;">Very low contrast</p>
        ';
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        $this->assertNotEmpty($results['issues']);
        $this->assertStringContainsString('Insufficient color contrast', $results['issues'][0]['message']);
    }

    public function test_detects_light_gray_text()
    {
        $html = '<p style="color: #ccc;">Light gray text</p>';
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        $this->assertNotEmpty($results['issues']);
        $this->assertStringContainsString('Light gray text', $results['issues'][0]['message']);
    }

    public function test_detects_placeholder_contrast_issues()
    {
        $html = '<input type="text" placeholder="Enter text here">';
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        $this->assertNotEmpty($results['issues']);
        $this->assertStringContainsString('Placeholder text', $results['issues'][0]['message']);
    }

    public function test_high_contrast_passes()
    {
        $html = '
            <p style="color: #000; background-color: #fff;">High contrast black on white</p>
            <p style="color: #fff; background-color: #000;">High contrast white on black</p>
        ';
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        // Should not flag high contrast text
        $issueCount = count($results['issues']);
        $this->assertEquals(0, $issueCount);
    }

    public function test_calculates_contrast_ratio_correctly()
    {
        $html = '<p style="color: #767676; background-color: #ffffff;">4.54:1 contrast ratio</p>';
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        // This should pass WCAG AA (4.5:1 minimum)
        $this->assertEmpty($results['issues']);
    }
}