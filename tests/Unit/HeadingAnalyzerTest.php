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

    public function test_missing_h1_is_a_document_level_notice(): void
    {
        $violations = $this->analyze('<html><body><h2>Section</h2><p>Content</p></body></html>');

        $violation = $this->assertHasViolation($violations, 'headings.missing_h1', severity: Severity::Notice);
        $this->assertNull($violation->element);
        $this->assertSame('1.3.1', $violation->rule);
        $this->assertSame([], $violation->related);
        $this->assertCount(1, $violations);
    }

    public function test_missing_h1_is_skipped_on_fragments_and_satisfied_by_role_heading_level_one(): void
    {
        $this->assertSame([], $this->analyze('<h2>Section</h2>'));
        $this->assertSame([], $this->analyze('<html><body><div role="heading" aria-level="1">Title</div></body></html>'));
    }

    public function test_hidden_h1_does_not_count(): void
    {
        $this->assertHasViolation($this->analyze('<html><body><h1 hidden>Title</h1><h2>Section</h2></body></html>'), 'headings.missing_h1');
    }

    public function test_skipped_level_is_a_warning(): void
    {
        $violations = $this->analyze('<h1>Main</h1><h3>Skipped h2</h3>');

        $violation = $this->assertHasViolation($violations, 'headings.skipped_level', element: 'h3', severity: Severity::Warning);
        $this->assertSame('1.3.1', $violation->rule);
        $this->assertSame(['from' => 'h1', 'to' => 'h3', 'content' => 'Skipped h2'], $violation->params);
        $this->assertCount(1, $violations);
    }

    public function test_role_heading_levels_take_part_in_the_hierarchy(): void
    {
        $violations = $this->analyze('<h1>Main</h1><div role="heading" aria-level="4">Deep</div><h2 aria-level="3">Level three</h2>');

        $violation = $this->assertHasViolation($violations, 'headings.skipped_level', element: 'div');
        $this->assertSame(['from' => 'h1', 'to' => 'h4', 'content' => 'Deep'], $violation->params);
        $this->assertViolationCount($violations, 'headings.skipped_level', 1);
    }

    public function test_hidden_and_re_roled_headings_are_ignored(): void
    {
        $this->assertSame([], $this->analyze('<h1>Main</h1><h4 aria-hidden="true">Hidden</h4><h4 role="presentation">Styled</h4><h2>Section</h2>'));
    }

    public function test_accepts_proper_heading_hierarchy(): void
    {
        $this->assertSame([], $this->analyze('<h1>Main</h1><h2>Section</h2><h3>Subsection</h3><h2>Next</h2>'));
    }

    public function test_empty_heading_uses_the_accessible_name(): void
    {
        $violations = $this->analyze('<h1><img src="logo.png" alt="ACME Corporation"></h1><h2>   </h2><h2><svg><title>Charts</title></svg></h2><h2><span aria-hidden="true">★</span></h2>');

        $violation = $this->assertHasViolation($violations, 'headings.empty_heading', element: 'h2', severity: Severity::Error);
        $this->assertSame(['level' => 'h2'], $violation->params);
        $this->assertSame(['2.4.6'], $violation->related);
        $this->assertViolationCount($violations, 'headings.empty_heading', 2);
        $this->assertNoViolation($violations, 'headings.short_heading');
    }

    public function test_short_heading_is_a_notice(): void
    {
        $violations = $this->analyze('<h1>Hi</h1>');

        $violation = $this->assertHasViolation($violations, 'headings.short_heading', element: 'h1', severity: Severity::Notice);
        $this->assertSame('2.4.6', $violation->rule);
        $this->assertSame(['content' => 'Hi'], $violation->params);
    }

    public function test_one_notice_per_additional_h1(): void
    {
        $violations = $this->analyze('<h1>First</h1><h1>Second</h1><h1>Third</h1>');

        $violation = $this->assertHasViolation($violations, 'headings.multiple_h1', element: 'h1', severity: Severity::Notice);
        $this->assertSame('1.3.1', $violation->rule);
        $this->assertSame(['content' => 'Second'], $violation->params);
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
