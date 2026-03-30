<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use DOMDocument;
use ItsJustVita\LaravelBfsg\Analyzers\StatusMessageAnalyzer;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class StatusMessageAnalyzerTest extends TestCase
{
    public function test_detects_page_with_forms_but_no_live_region(): void
    {
        $html = '<html><body><form><input type="text" name="search"><button type="submit">Search</button></form></body></html>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $analyzer = new StatusMessageAnalyzer;
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertCount(1, $violations);
        $this->assertEquals('warning', $violations[0]['type']);
        $this->assertEquals('WCAG 4.1.3', $violations[0]['rule']);
        $this->assertStringContainsString('no aria-live region', $violations[0]['message']);
    }

    public function test_accepts_page_with_aria_live_polite(): void
    {
        $html = '<html><body><form><button type="submit">Submit</button></form><div aria-live="polite"></div></body></html>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $analyzer = new StatusMessageAnalyzer;
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertEmpty($violations);
    }

    public function test_accepts_page_with_role_status(): void
    {
        $html = '<html><body><form><button type="submit">Submit</button></form><div role="status"></div></body></html>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $analyzer = new StatusMessageAnalyzer;
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertEmpty($violations);
    }

    public function test_no_issues_without_dynamic_content(): void
    {
        $html = '<html><body><p>Static content only</p></body></html>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $analyzer = new StatusMessageAnalyzer;
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertEmpty($violations);
    }
}
