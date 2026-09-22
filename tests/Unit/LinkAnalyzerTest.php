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

    public function test_missing_name_covers_empty_icon_only_and_unnamed_image_links(): void
    {
        $html = '<a href="/a" id="empty"></a><a href="/b" id="ws">   </a><a href="/c" id="icon"><i class="fa fa-home"></i></a>'
            .'<a href="/d" id="img"><img src="x.png"></a><a href="/e"><img src="x.png" alt="Home"></a><a href="/f" aria-label="Profile"><svg></svg></a>'
            .'<a href="/g" aria-hidden="true" tabindex="-1"></a>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'links.missing_name', element: 'a#empty', severity: Severity::Error);
        $this->assertSame('2.4.4', $violation->rule);
        $this->assertSame(['4.1.2'], $violation->related);
        $this->assertSame(['href' => '/a'], $violation->params);
        $this->assertViolationCount($violations, 'links.missing_name', 4);
    }

    public function test_non_descriptive_link_text_en_and_de(): void
    {
        $html = '<div><a href="/1">Click here</a><a href="/2">Weiterlesen »</a><a href="/3">Mehr erfahren →</a><a href="/4">»</a><a href="/5">hier klicken</a></div>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'links.non_descriptive', severity: Severity::Warning);
        $this->assertSame('2.4.4', $violation->rule);
        $this->assertSame([], $violation->related);
        $this->assertSame(['text' => 'Click here', 'href' => '/1'], $violation->params);
        $this->assertViolationCount($violations, 'links.non_descriptive', 5);
    }

    public function test_differing_aria_label_or_title_makes_a_generic_text_descriptive(): void
    {
        $html = '<div><a href="/1" aria-label="Read more about pricing">Read more</a><a href="/2" title="Mehr über das Produkt">Mehr</a>'
            .'<span id="p">Pricing details</span><a href="/3" aria-labelledby="p">More</a><a href="/4" aria-label="more">more</a></div>';

        $violations = $this->analyze($html);

        $this->assertViolationCount($violations, 'links.non_descriptive', 1);
        $this->assertHasViolation($violations, 'links.non_descriptive', element: 'a');
    }

    public function test_generic_text_with_context_is_a_notice(): void
    {
        $html = '<ul><li>Our annual report 2025 <a href="/r">more</a></li></ul>'
            .'<p>Read the privacy policy <a href="/p">here</a>.</p>'
            .'<h3>Pricing</h3><a href="/pricing">Read more</a>'
            .'<ul><li><a href="/alone">more</a></li></ul>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'links.non_descriptive_in_context', severity: Severity::Notice);
        $this->assertSame('2.4.4', $violation->rule);
        $this->assertViolationCount($violations, 'links.non_descriptive_in_context', 3);
        $this->assertViolationCount($violations, 'links.non_descriptive', 1);
    }

    public function test_url_as_text_is_a_notice(): void
    {
        $html = '<div><a href="https://example.com">https://example.com</a><a href="https://example.com/x">www.example.com/x</a>'
            .'<a href="https://bfsg.de">bfsg-gesetz.de</a><a href="/r.pdf">Bericht.pdf</a><a href="/v">Version 2.1</a></div>';

        $violations = $this->analyze($html);

        $this->assertHasViolation($violations, 'links.url_as_text', severity: Severity::Notice);
        $this->assertViolationCount($violations, 'links.url_as_text', 3);
    }

    public function test_new_window_notice_is_tagged_aaa(): void
    {
        $html = '<div><a href="/a" target="_blank">Report</a><a href="/b" target="_BLANK">Bericht (öffnet in neuem Fenster)</a>'
            .'<a href="/c" target="_blank" title="opens in a new tab">Docs</a><a href="/d" target="_blank">Partner (extern)</a></div>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'links.new_window_unannounced', severity: Severity::Notice);
        $this->assertSame('3.2.5', $violation->rule);
        $this->assertSame(['aaa'], $violation->tags);
        $this->assertViolationCount($violations, 'links.new_window_unannounced', 1);
    }

    public function test_missing_noopener_only_for_external_links(): void
    {
        $html = '<div><a href="https://ext.example/a" target="_blank">External page (new window)</a>'
            .'<a href="https://ext.example/b" target="_blank" rel="noopener">Safe page (new window)</a>'
            .'<a href="//ext.example/c" target="_blank" rel="NOREFERRER">Also safe (new window)</a>'
            .'<a href="/internal" target="_blank">Internal page (new window)</a></div>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'links.missing_noopener', severity: Severity::Notice);
        $this->assertNull($violation->rule);
        $this->assertSame(['security'], $violation->tags);
        $this->assertTrue($violation->autoFixable);
        $this->assertSame(['href' => 'https://ext.example/a'], $violation->params);
        $this->assertViolationCount($violations, 'links.missing_noopener', 1);
    }

    public function test_download_without_file_type_hint(): void
    {
        $html = '<div><a href="/files/report.PDF?v=2#p1">Annual report</a><a href="/files/a.pdf">Annual report (PDF, 2 MB)</a>'
            .'<a href="/files/b.xlsx">Preisliste als Datei</a><a href="/files/c.docx" download>Contract</a><a href="/files/d.csv">Export CSV</a>'
            .'<a href="/files/e.zip">Sources</a></div>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'links.download_unannounced', severity: Severity::Notice);
        $this->assertSame(['href' => '/files/report.PDF?v=2#p1', 'type' => 'PDF'], $violation->params);
        $this->assertViolationCount($violations, 'links.download_unannounced', 2);
    }

    public function test_adjacent_duplicate_needs_same_href_and_name(): void
    {
        $html = '<div><a href="/p"><img src="p.jpg" alt="Product X"></a><a href="/p">Product X</a>'
            .'<a href="/q">Product Y image</a><a href="/q">Product Y</a></div>';

        $violations = $this->analyze($html);

        $this->assertHasViolation($violations, 'links.adjacent_duplicate', severity: Severity::Notice);
        $this->assertViolationCount($violations, 'links.adjacent_duplicate', 1);
    }

    public function test_pseudo_links(): void
    {
        $html = '<div><a href="#" onclick="go()">Open dialog</a><a href="javascript:void(0)" role="button">Toggle menu</a><a href="#">Back to top</a></div>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'links.pseudo_link', severity: Severity::Notice);
        $this->assertSame('4.1.2', $violation->rule);
        $this->assertViolationCount($violations, 'links.pseudo_link', 2);
    }

    public function test_links_without_href_and_hidden_links_are_ignored(): void
    {
        $this->assertSame([], $this->analyze('<div><a name="top"></a><a>Placeholder</a><div hidden><a href="/x">here</a></div></div>'));
    }

    public function test_truncated_link_text_stays_valid_utf8(): void
    {
        $violations = $this->analyze('<a href="https://example.com/'.str_repeat('ä', 80).'">https://example.com/'.str_repeat('ä', 80).'</a>');

        $violation = $this->assertHasViolation($violations, 'links.url_as_text');
        $this->assertTrue(mb_check_encoding($violation->params['text'], 'UTF-8'));
        $this->assertLessThanOrEqual(50, mb_strlen($violation->params['text']));
    }

    public function test_descriptive_links_pass(): void
    {
        $this->assertSame([], $this->analyze('<nav><a href="/about">About our company</a><a href="/contact">Kontakt aufnehmen</a></nav>'));
    }
}
