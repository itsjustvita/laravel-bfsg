<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Analyzers\LinkAnalyzer;
use ItsJustVita\LaravelBfsg\Contracts\Analyzer;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\Support\AnalyzerTestCase;

class LinkAnalyzerTest extends AnalyzerTestCase
{
    protected function analyzer(): Analyzer
    {
        return new LinkAnalyzer;
    }

    public function test_detects_non_descriptive_link_text(): void
    {
        $html = '
            <a href="/page1">Click here</a>
            <a href="/page2">Read more</a>
            <a href="/page3">More</a>
        ';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'links.non_descriptive', element: 'a', severity: Severity::Error);
        $this->assertSame('2.4.4', $violation->rule);
        $this->assertSame(['2.4.9'], $violation->related);
        $this->assertSame(['text' => 'Click here', 'href' => '/page1'], $violation->params);
        $this->assertViolationCount($violations, 'links.non_descriptive', 3);
    }

    public function test_detects_empty_links(): void
    {
        $html = '
            <a href="/page1"></a>
            <a href="/page2">  </a>
        ';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'links.missing_name', element: 'a', severity: Severity::Error);
        $this->assertSame('2.4.4', $violation->rule);
        $this->assertSame(['4.1.2'], $violation->related);
        $this->assertSame(['href' => '/page1'], $violation->params);
    }

    public function test_detects_link_with_image_lacking_alt_text(): void
    {
        $violations = $this->analyze('<a href="/gallery"><img src="photo.jpg"></a>');

        $violation = $this->assertHasViolation($violations, 'links.missing_name', element: 'a', severity: Severity::Error);
        $this->assertSame('2.4.4', $violation->rule);
        $this->assertSame(['1.1.1'], $violation->related);
        $this->assertSame('image_without_alt', $violation->meta['reason']);
        $this->assertSame(['href' => '/gallery'], $violation->params);
    }

    public function test_accepts_link_with_image_alt_text(): void
    {
        $violations = $this->analyze('<a href="/gallery"><img src="photo.jpg" alt="Our gallery"></a>');

        $this->assertNoViolation($violations, 'links.missing_name');
    }

    public function test_detects_links_without_href(): void
    {
        $violations = $this->analyze('<a>Link without href</a>');

        $violation = $this->assertHasViolation($violations, 'links.missing_href', element: 'a', severity: Severity::Warning);
        $this->assertSame('2.4.4', $violation->rule);
        $this->assertSame(['text' => 'Link without href'], $violation->params);
    }

    public function test_detects_adjacent_duplicate_links(): void
    {
        $violations = $this->analyze('<p><a href="/product">Product image</a><a href="/product">Product name</a></p>');

        $violation = $this->assertHasViolation($violations, 'links.adjacent_duplicate', element: 'a', severity: Severity::Warning);
        $this->assertSame('2.4.4', $violation->rule);
        $this->assertSame(['href' => '/product'], $violation->params);
        $this->assertViolationCount($violations, 'links.adjacent_duplicate', 1);
    }

    public function test_detects_new_window_links_without_warning(): void
    {
        // Warning and rel are both missing → two separate findings, one subject each.
        $violations = $this->analyze('<a href="https://example.com" target="_blank">External Site</a>');

        $newWindow = $this->assertHasViolation($violations, 'links.new_window_unannounced', element: 'a', severity: Severity::Warning);
        $this->assertSame('3.2.5', $newWindow->rule);
        $this->assertSame(['href' => 'https://example.com', 'text' => 'External Site'], $newWindow->params);
        $this->assertFalse($newWindow->autoFixable);

        $noopener = $this->assertHasViolation($violations, 'links.missing_noopener', element: 'a', severity: Severity::Warning);
        $this->assertNull($noopener->rule);
        $this->assertSame(['security'], $noopener->tags);
        $this->assertTrue($noopener->autoFixable);
        $this->assertSame(['href' => 'https://example.com'], $noopener->params);

        $this->assertCount(2, $violations);
    }

    public function test_reports_both_new_window_findings_per_link(): void
    {
        $html = '
            <a href="https://a.com" target="_blank">A</a>
            <a href="https://b.com" target="_blank">B</a>
            <a href="https://c.com" target="_blank">C</a>
        ';

        $violations = $this->analyze($html);

        $this->assertViolationCount($violations, 'links.new_window_unannounced', 3);
        $this->assertViolationCount($violations, 'links.missing_noopener', 3);
    }

    public function test_only_warning_missing_yields_new_window_finding_only(): void
    {
        $violations = $this->analyze('<a href="https://example.com" target="_blank" rel="noopener noreferrer">External Site</a>');

        $this->assertViolationCount($violations, 'links.new_window_unannounced', 1);
        $this->assertNoViolation($violations, 'links.missing_noopener');
    }

    public function test_only_rel_missing_yields_noopener_finding_only(): void
    {
        $violations = $this->analyze('<a href="https://example.com" target="_blank">Open Example.com (opens in new window)</a>');

        $this->assertNoViolation($violations, 'links.new_window_unannounced');
        $this->assertViolationCount($violations, 'links.missing_noopener', 1);
    }

    public function test_detects_file_downloads_without_indication(): void
    {
        $html = '
            <a href="/document.pdf">Annual Report</a>
            <a href="/data.xlsx">Spreadsheet</a>
        ';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'links.download_unannounced', element: 'a', severity: Severity::Warning);
        $this->assertSame('2.4.4', $violation->rule);
        $this->assertSame('PDF', $violation->params['type']);
        $this->assertSame('/document.pdf', $violation->params['href']);
        $this->assertSame('Annual Report', $violation->params['text']);
        $this->assertViolationCount($violations, 'links.download_unannounced', 2);
    }

    public function test_detects_url_as_link_text(): void
    {
        $violations = $this->analyze('<a href="https://www.example.com">https://www.example.com</a>');

        $violation = $this->assertHasViolation($violations, 'links.url_as_text', element: 'a', severity: Severity::Warning);
        $this->assertSame('2.4.4', $violation->rule);
        $this->assertSame(['href' => 'https://www.example.com', 'text' => 'https://www.example.com'], $violation->params);
    }

    public function test_detects_german_non_descriptive_link_text(): void
    {
        $html = '
            <a href="/page1">hier klicken</a>
            <a href="/page2">mehr erfahren</a>
            <a href="/page3">weiterlesen</a>
        ';

        $violations = $this->analyze($html);

        $this->assertViolationCount($violations, 'links.non_descriptive', 3);
    }

    public function test_detects_german_hier_klicken_as_non_descriptive(): void
    {
        $violations = $this->analyze('<a href="/page">hier klicken</a>');

        $violation = $this->assertHasViolation($violations, 'links.non_descriptive', element: 'a', severity: Severity::Error);
        $this->assertSame('hier klicken', $violation->params['text']);
    }

    public function test_warns_about_very_short_link_text(): void
    {
        $violations = $this->analyze('<a href="/page">ok</a>');

        $violation = $this->assertHasViolation($violations, 'links.non_descriptive', element: 'a', severity: Severity::Warning);
        $this->assertSame('2.4.4', $violation->rule);
        $this->assertSame(['text' => 'ok', 'href' => '/page'], $violation->params);
    }

    public function test_short_link_text_with_aria_label_passes(): void
    {
        $violations = $this->analyze('<a href="/page" aria-label="Go to the product page">ok</a>');

        $this->assertNoViolation($violations, 'links.non_descriptive');
    }

    public function test_truncated_link_text_stays_valid_utf8(): void
    {
        $violations = $this->analyze('<a>'.str_repeat('a', 49).'ü…</a>');

        $violation = $this->assertHasViolation($violations, 'links.missing_href', element: 'a');
        $this->assertTrue(mb_check_encoding($violation->params['text'], 'UTF-8'));
        $this->assertLessThanOrEqual(50, mb_strlen($violation->params['text']));
    }

    public function test_descriptive_links_pass(): void
    {
        $html = '
            <a href="/about">About our company</a>
            <a href="/contact">Contact us today</a>
            <a href="https://example.com" target="_blank" rel="noopener noreferrer">
                Visit Example.com (opens in new window)
            </a>
            <a href="/report.pdf">Download Annual Report (PDF, 2.3MB)</a>
        ';

        $this->assertSame([], $this->analyze($html));
    }
}
