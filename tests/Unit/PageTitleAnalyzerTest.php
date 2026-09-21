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

    public function test_detects_missing_title(): void
    {
        $violations = $this->analyze('<html><head></head><body><p>Hello</p></body></html>');

        $violation = $this->assertHasViolation($violations, 'page_title.missing_title', severity: Severity::Error);
        $this->assertNull($violation->element);
        $this->assertSame('2.4.2', $violation->rule);
        $this->assertCount(1, $violations);
    }

    public function test_detects_empty_title(): void
    {
        $violations = $this->analyze('<html><head><title></title></head><body><p>Hello</p></body></html>');

        $violation = $this->assertHasViolation($violations, 'page_title.empty_title', element: 'title', severity: Severity::Error);
        $this->assertSame('2.4.2', $violation->rule);
        $this->assertCount(1, $violations);
    }

    public function test_detects_generic_title(): void
    {
        $violations = $this->analyze('<html><head><title>Home</title></head><body><p>Hello</p></body></html>');

        $violation = $this->assertHasViolation($violations, 'page_title.generic_title', element: 'title', severity: Severity::Warning);
        $this->assertSame(['title' => 'Home'], $violation->params);
    }

    public function test_detects_too_short_title(): void
    {
        $violations = $this->analyze('<html><head><title>Ab</title></head><body><p>Hello</p></body></html>');

        $violation = $this->assertHasViolation($violations, 'page_title.short_title', element: 'title', severity: Severity::Warning);
        $this->assertSame(['length' => 2], $violation->params);
        $this->assertNoViolation($violations, 'page_title.long_title');
    }

    public function test_detects_too_long_title(): void
    {
        $longTitle = str_repeat('A very long page title ', 5);
        $violations = $this->analyze('<html><head><title>'.$longTitle.'</title></head><body><p>Hello</p></body></html>');

        $violation = $this->assertHasViolation($violations, 'page_title.long_title', element: 'title', severity: Severity::Warning);
        $this->assertSame(['length' => 114], $violation->params);
        $this->assertNoViolation($violations, 'page_title.short_title');
    }

    public function test_detects_german_generic_title_startseite(): void
    {
        // v2.2.0 Fix 6: German "Startseite" is as generic as English "Home".
        $violations = $this->analyze('<html><head><title>Startseite</title></head><body><p>Hello</p></body></html>');

        $violation = $this->assertHasViolation($violations, 'page_title.generic_title', element: 'title', severity: Severity::Warning);
        $this->assertSame(['title' => 'Startseite'], $violation->params);
    }

    public function test_detects_german_generic_title_willkommen(): void
    {
        // v2.2.0 Fix 6: German "Willkommen" is a generic welcome page title.
        $violations = $this->analyze('<html><head><title>Willkommen</title></head><body><p>Hi</p></body></html>');

        $this->assertHasViolation($violations, 'page_title.generic_title', element: 'title', severity: Severity::Warning);
    }

    public function test_accepts_good_descriptive_title(): void
    {
        $this->assertSame([], $this->analyze('<html><head><title>About Us - Company Name</title></head><body><p>Hello</p></body></html>'));
    }
}
