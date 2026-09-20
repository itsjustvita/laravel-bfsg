<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Analyzers\HeadingAnalyzer;
use ItsJustVita\LaravelBfsg\Contracts\Analyzer;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\Support\AnalyzerTestCase;

class HeadingAnalyzerTest extends AnalyzerTestCase
{
    protected function analyzer(): Analyzer
    {
        return new HeadingAnalyzer;
    }

    public function test_detects_missing_h1(): void
    {
        $violations = $this->analyze('<h2>Section</h2><p>Content</p>');

        $violation = $this->assertHasViolation($violations, 'headings.missing_h1', severity: Severity::Warning);
        $this->assertNull($violation->element);
        $this->assertSame('1.3.1', $violation->rule);
        $this->assertSame(['2.4.6'], $violation->related);
        $this->assertCount(1, $violations);
    }

    public function test_detects_broken_heading_hierarchy(): void
    {
        $violations = $this->analyze('<h1>Main</h1><h3>Skipped h2</h3>');

        $violation = $this->assertHasViolation($violations, 'headings.skipped_level', element: 'h3', severity: Severity::Error);
        $this->assertSame('1.3.1', $violation->rule);
        $this->assertSame(['from' => 'h1', 'to' => 'h3', 'content' => 'Skipped h2'], $violation->params);
        $this->assertCount(1, $violations);
    }

    public function test_accepts_proper_heading_hierarchy(): void
    {
        $this->assertSame([], $this->analyze('<h1>Main</h1><h2>Section</h2><h3>Subsection</h3>'));
    }

    public function test_detects_empty_headings(): void
    {
        $violations = $this->analyze('<h1></h1><h2>   </h2>');

        $violation = $this->assertHasViolation($violations, 'headings.empty_heading', element: 'h1', severity: Severity::Error);
        $this->assertSame(['level' => 'h1'], $violation->params);
        $this->assertHasViolation($violations, 'headings.empty_heading', element: 'h2', severity: Severity::Error);
        $this->assertViolationCount($violations, 'headings.empty_heading', 2);
        $this->assertNoViolation($violations, 'headings.short_heading');
    }

    public function test_warns_about_very_short_heading_text(): void
    {
        $violations = $this->analyze('<h1>Hi</h1>');

        $violation = $this->assertHasViolation($violations, 'headings.short_heading', element: 'h1', severity: Severity::Warning);
        $this->assertSame('2.4.6', $violation->rule);
        $this->assertSame(['content' => 'Hi'], $violation->params);
    }

    public function test_warns_about_multiple_h1_tags(): void
    {
        $violations = $this->analyze('<h1>First</h1><h1>Second</h1>');

        $violation = $this->assertHasViolation($violations, 'headings.multiple_h1', element: 'h1', severity: Severity::Notice);
        $this->assertSame('1.3.1', $violation->rule);
        $this->assertSame(['index' => 2, 'content' => 'Second'], $violation->params);
        $this->assertViolationCount($violations, 'headings.multiple_h1', 1);
    }

    public function test_reports_one_notice_per_additional_h1(): void
    {
        $violations = $this->analyze('<h1>First</h1><h1>Second</h1><h1>Third</h1>');

        $this->assertViolationCount($violations, 'headings.multiple_h1', 2);
    }

    public function test_truncated_heading_content_stays_valid_utf8(): void
    {
        $violations = $this->analyze('<h1>Main</h1><h3>'.str_repeat('a', 49).'ü…</h3>');

        $violation = $this->assertHasViolation($violations, 'headings.skipped_level', element: 'h3');
        $this->assertTrue(mb_check_encoding($violation->params['content'], 'UTF-8'));
        $this->assertLessThanOrEqual(50, mb_strlen($violation->params['content']));
    }
}
