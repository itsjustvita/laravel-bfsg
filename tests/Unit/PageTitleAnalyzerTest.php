<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Analyzers\PageTitleAnalyzer;
use ItsJustVita\LaravelBfsg\Contracts\Analyzer;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\Support\AnalyzerTestCase;

class PageTitleAnalyzerTest extends AnalyzerTestCase
{
    protected function analyzer(): Analyzer
    {
        return new PageTitleAnalyzer;
    }

    private function titled(string $title): string
    {
        return '<!DOCTYPE html><html lang="en"><head><title>'.$title.'</title></head><body><p>x</p></body></html>';
    }

    public function test_missing_title_ignores_svg_titles(): void
    {
        $violations = $this->analyze('<!DOCTYPE html><html><head></head><body><svg><title>Logo</title></svg></body></html>');

        $violation = $this->assertHasViolation($violations, 'page_title.missing_title', severity: Severity::Error);
        $this->assertSame('2.4.2', $violation->rule);
        $this->assertNull($violation->element);
        $this->assertCount(1, $violations);
    }

    public function test_fragments_are_skipped(): void
    {
        $this->assertSame([], $this->analyze('<div><p>Partial</p></div>'));
    }

    public function test_empty_title_is_nbsp_aware(): void
    {
        $violation = $this->assertHasViolation($this->analyze($this->titled("&nbsp; \u{00A0}")), 'page_title.empty_title', element: 'title', severity: Severity::Error);

        $this->assertSame([], $violation->params);
    }

    public function test_short_title(): void
    {
        $violation = $this->assertHasViolation($this->analyze($this->titled('AB')), 'page_title.short_title', element: 'title', severity: Severity::Warning);

        $this->assertSame(['length' => 2], $violation->params);
    }

    public function test_generic_titles_en_and_de(): void
    {
        foreach (['Home', 'Startseite', 'Willkommen!', 'UNTITLED DOCUMENT', 'Home | Welcome', 'Startseite - Willkommen'] as $title) {
            $violation = $this->assertHasViolation($this->analyze($this->titled($title)), 'page_title.generic_title', severity: Severity::Warning);
            $this->assertSame(['title' => $title], $violation->params, $title);
        }
    }

    public function test_a_generic_page_segment_next_to_the_site_name_is_a_notice(): void
    {
        foreach (['Home | Acme', 'Startseite – Firma', 'Acme | Home', 'Firma – Startseite'] as $title) {
            $violation = $this->assertHasViolation($this->analyze($this->titled($title)), 'page_title.generic_title', element: 'title', severity: Severity::Notice);
            $this->assertSame(['title' => $title], $violation->params, $title);
        }
    }

    public function test_specific_titles_pass(): void
    {
        foreach (['Kontakt | Firma', 'Home - Welcome to the Acme Corporation', 'Test Page - Company', 'Über uns – Beispiel GmbH'] as $title) {
            $this->assertSame([], $this->analyze($this->titled($title)), $title);
        }
    }

    public function test_long_title_is_a_notice(): void
    {
        $violation = $this->assertHasViolation($this->analyze($this->titled(str_repeat('Ä', 71))), 'page_title.long_title', severity: Severity::Notice);

        $this->assertSame(['length' => 71], $violation->params);
    }

    public function test_multiple_titles(): void
    {
        $violations = $this->analyze('<!DOCTYPE html><html><head><title>Kontakt | Firma</title><title>Second</title></head><body></body></html>');

        $violation = $this->assertHasViolation($violations, 'page_title.multiple_titles', element: 'title', severity: Severity::Warning);
        $this->assertSame('/html[1]/head[1]/title[2]', $violation->selector);
        $this->assertCount(1, $violations);
    }
}
