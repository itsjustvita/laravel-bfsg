<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Analyzers\FocusAnalyzer;
use ItsJustVita\LaravelBfsg\Contracts\Analyzer;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\Support\AnalyzerTestCase;

class FocusAnalyzerTest extends AnalyzerTestCase
{
    protected function analyzer(): Analyzer
    {
        return new FocusAnalyzer;
    }

    public function test_detects_inline_outline_none_on_button(): void
    {
        $violations = $this->analyze('<button style="outline: none;">Click me</button>');

        $violation = $this->assertHasViolation($violations, 'focus.outline_removed_inline', element: 'button', severity: Severity::Error);
        $this->assertSame('2.4.7', $violation->rule);
        $this->assertSame(['tag' => 'button'], $violation->params);
        $this->assertFalse($violation->autoFixable);
        $this->assertCount(1, $violations);
    }

    public function test_detects_global_focus_reset_in_style_block(): void
    {
        $violations = $this->analyze('<html><head><style>*:focus { outline: none; }</style></head><body><a href="#">Link</a></body></html>');

        $violation = $this->assertHasViolation(
            $violations,
            'focus.outline_removed_global',
            element: 'style',
            severity: Severity::Warning,
            selector: '/html[1]/head[1]/style[1]',
        );
        $this->assertSame('2.4.7', $violation->rule);
        $this->assertSame([], $violation->params);
        $this->assertCount(1, $violations);
    }

    public function test_detects_outline_removed_for_a_specific_selector(): void
    {
        $violations = $this->analyze('<html><head><style>a:focus { outline: none; }</style></head><body><a href="#">Link</a></body></html>');

        $violation = $this->assertHasViolation($violations, 'focus.outline_removed', element: 'style', severity: Severity::Error);
        $this->assertSame('2.4.7', $violation->rule);
        $this->assertSame(['selector' => 'a:focus'], $violation->params);
        $this->assertSame(['selector' => 'a:focus'], $violation->meta);
        $this->assertCount(1, $violations);
    }

    public function test_ignores_stylesheets_for_other_media(): void
    {
        $violations = $this->analyze('<html><head><style media="print">a:focus { outline: none; }</style></head><body><a href="#">Link</a></body></html>');

        $this->assertSame([], $violations);
    }

    public function test_accepts_focus_removal_with_alternative(): void
    {
        $this->assertSame([], $this->analyze('<html><head><style>a:focus { outline: none; box-shadow: 0 0 3px blue; }</style></head><body><a href="#">Link</a></body></html>'));
    }

    public function test_no_issues_without_focus_problems(): void
    {
        $this->assertSame([], $this->analyze('<html><head></head><body><a href="#">Link</a><button>Click</button></body></html>'));
    }
}
