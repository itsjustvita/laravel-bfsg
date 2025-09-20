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
        $this->analyzer = new LinkAnalyzer();
    }

    public function test_detects_non_descriptive_link_text()
    {
        $html = '
            <a href="/page1">Click here</a>
            <a href="/page2">Read more</a>
            <a href="/page3">More</a>
        ';
        $dom = new DOMDocument();
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
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        $this->assertNotEmpty($results['issues']);
        $this->assertStringContainsString('Empty link', $results['issues'][0]['message']);
    }

    public function test_detects_links_without_href()
    {
        $html = '<a>Link without href</a>';
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        $this->assertNotEmpty($results['issues']);
        $this->assertStringContainsString('without href', $results['issues'][0]['message']);
    }

    public function test_detects_new_window_links_without_warning()
    {
        $html = '<a href="https://example.com" target="_blank">External Site</a>';
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        $this->assertNotEmpty($results['issues']);
        $this->assertCount(2, $results['issues']); // Should detect both new window and missing rel
    }

    public function test_detects_file_downloads_without_indication()
    {
        $html = '
            <a href="/document.pdf">Annual Report</a>
            <a href="/data.xlsx">Spreadsheet</a>
        ';
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        $this->assertNotEmpty($results['issues']);
        $this->assertStringContainsString('File download', $results['issues'][0]['message']);
    }

    public function test_detects_url_as_link_text()
    {
        $html = '<a href="https://www.example.com">https://www.example.com</a>';
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        $this->assertNotEmpty($results['issues']);
        $this->assertStringContainsString('URL used as link text', $results['issues'][0]['message']);
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
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        $this->assertEmpty($results['issues']);
    }
}