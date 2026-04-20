<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use DOMDocument;
use ItsJustVita\LaravelBfsg\Analyzers\LinkAnalyzer;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class LinkAnalyzerTest extends TestCase
{
    protected LinkAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new LinkAnalyzer;
    }

    public function test_detects_non_descriptive_link_text()
    {
        $html = '
            <a href="/page1">Click here</a>
            <a href="/page2">Read more</a>
            <a href="/page3">More</a>
        ';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        $this->assertNotEmpty($results['issues']);
        $this->assertGreaterThanOrEqual(3, count($results['issues']));
    }

    public function test_detects_empty_links()
    {
        $html = '
            <a href="/page1"></a>
            <a href="/page2">  </a>
        ';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        $this->assertNotEmpty($results['issues']);
        $this->assertStringContainsString('Empty link', $results['issues'][0]['message']);
    }

    public function test_detects_links_without_href()
    {
        $html = '<a>Link without href</a>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        $this->assertNotEmpty($results['issues']);
        $this->assertStringContainsString('without href', $results['issues'][0]['message']);
    }

    public function test_detects_new_window_links_without_warning()
    {
        // Both issues apply (missing warning + missing rel) → combined into ONE finding (v2.2.0).
        $html = '<a href="https://example.com" target="_blank">External Site</a>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        $this->assertNotEmpty($results['issues']);
        $this->assertCount(1, $results['issues'], 'Both warning+rel missing should be combined into 1 finding');
        $this->assertStringContainsString('opens in new window without warning', $results['issues'][0]['message']);
        $this->assertStringContainsString('rel="noopener noreferrer"', $results['issues'][0]['message']);
    }

    public function test_detects_file_downloads_without_indication()
    {
        $html = '
            <a href="/document.pdf">Annual Report</a>
            <a href="/data.xlsx">Spreadsheet</a>
        ';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        $this->assertNotEmpty($results['issues']);
        $this->assertStringContainsString('File download', $results['issues'][0]['message']);
    }

    public function test_detects_url_as_link_text()
    {
        $html = '<a href="https://www.example.com">https://www.example.com</a>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        $this->assertNotEmpty($results['issues']);
        $this->assertStringContainsString('URL used as link text', $results['issues'][0]['message']);
    }

    public function test_detects_german_non_descriptive_link_text()
    {
        // v2.2.0 Fix 5: German non-descriptive link text is now caught.
        $html = '
            <a href="/page1">hier klicken</a>
            <a href="/page2">mehr erfahren</a>
            <a href="/page3">weiterlesen</a>
        ';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        $nonDescriptive = collect($results['issues'])->filter(
            fn ($i) => str_contains($i['message'], 'Non-descriptive link text')
        );

        $this->assertGreaterThanOrEqual(3, $nonDescriptive->count());
    }

    public function test_detects_german_hier_klicken_as_non_descriptive()
    {
        // v2.2.0 Fix 5: "hier klicken" is the canonical German example.
        $html = '<a href="/page">hier klicken</a>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        $nonDescriptive = collect($results['issues'])->first(
            fn ($i) => str_contains($i['message'], "'hier klicken'")
        );

        $this->assertNotNull($nonDescriptive);
        $this->assertSame('error', $nonDescriptive['type']);
    }

    public function test_combined_warning_and_rel_missing_yields_one_finding_per_link()
    {
        // v2.2.0 Fix 8: 3 bad links × 2 issues → now 3 findings (not 6).
        $html = '
            <a href="https://a.com" target="_blank">A</a>
            <a href="https://b.com" target="_blank">B</a>
            <a href="https://c.com" target="_blank">C</a>
        ';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        // Filter for the combined-finding message only (avoid other checks like short text).
        $combined = collect($results['issues'])->filter(
            fn ($i) => str_contains($i['message'], 'without warning and lacks rel')
        );

        $this->assertCount(3, $combined, 'Each link should yield exactly one combined finding');
    }

    public function test_only_warning_missing_yields_solo_warning_finding()
    {
        // v2.2.0 Fix 8: warning missing but rel present → solo "new window" finding.
        $html = '<a href="https://example.com" target="_blank" rel="noopener noreferrer">External Site</a>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        $newWindow = collect($results['issues'])->filter(
            fn ($i) => $i['message'] === 'Link opens in new window without warning'
        );
        $security = collect($results['issues'])->filter(
            fn ($i) => str_contains($i['message'], 'missing rel=')
        );

        $this->assertCount(1, $newWindow);
        $this->assertCount(0, $security);
    }

    public function test_only_rel_missing_yields_solo_security_finding()
    {
        // v2.2.0 Fix 8: warning present but rel missing → solo security finding.
        $html = '<a href="https://example.com" target="_blank">Open Example.com (opens in new window)</a>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        $newWindow = collect($results['issues'])->filter(
            fn ($i) => $i['message'] === 'Link opens in new window without warning'
        );
        $security = collect($results['issues'])->filter(
            fn ($i) => str_contains($i['message'], 'missing rel=')
        );

        $this->assertCount(0, $newWindow);
        $this->assertCount(1, $security);
    }

    public function test_descriptive_links_pass()
    {
        $html = '
            <a href="/about">About our company</a>
            <a href="/contact">Contact us today</a>
            <a href="https://example.com" target="_blank" rel="noopener noreferrer">
                Visit Example.com (opens in new window)
            </a>
            <a href="/report.pdf">Download Annual Report (PDF, 2.3MB)</a>
        ';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        $this->assertEmpty($results['issues']);
    }
}
