<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Analyzers\AriaAnalyzer;
use ItsJustVita\LaravelBfsg\Contracts\Analyzer;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\Support\AnalyzerTestCase;

class AriaAnalyzerTest extends AnalyzerTestCase
{
    protected function analyzer(): Analyzer
    {
        return new AriaAnalyzer;
    }

    public function test_detects_invalid_aria_roles(): void
    {
        $html = '<div role="invalid-role">Content</div>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'aria.invalid_role', element: 'div', severity: Severity::Error);
        $this->assertSame('4.1.2', $violation->rule);
        $this->assertSame(['role' => 'invalid-role'], $violation->params);
        $this->assertCount(1, $violations);
    }

    public function test_detects_redundant_aria_roles(): void
    {
        $html = '<input type="checkbox" role="checkbox">';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'aria.redundant_role', element: 'input', severity: Severity::Warning);
        $this->assertSame(['role' => 'checkbox', 'tag' => 'input'], $violation->params);
        $this->assertTrue($violation->autoFixable);
    }

    public function test_detects_missing_required_aria_attributes(): void
    {
        $html = '<div role="slider">Slider</div>';

        $violations = $this->analyze($html);

        // Missing aria-valuenow, aria-valuemin and aria-valuemax.
        $this->assertViolationCount($violations, 'aria.missing_required_state', 3);

        $violation = $this->assertHasViolation($violations, 'aria.missing_required_state', element: 'div', severity: Severity::Error);
        $this->assertSame('slider', $violation->params['role']);
        $this->assertContains($violation->params['attribute'], ['aria-valuenow', 'aria-valuemin', 'aria-valuemax']);
    }

    public function test_detects_invalid_aria_labelledby_references(): void
    {
        $html = '<div aria-labelledby="non-existent">Content</div>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'aria.dangling_idref', element: 'div', severity: Severity::Error);
        $this->assertSame('1.3.1', $violation->rule);
        $this->assertSame(['4.1.2'], $violation->related);
        $this->assertSame(['attribute' => 'aria-labelledby', 'id' => 'non-existent'], $violation->params);
        $this->assertCount(1, $violations);
    }

    public function test_detects_invalid_aria_describedby_references(): void
    {
        $html = '<div aria-describedby="help missing">Content</div><span id="help">Help</span>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'aria.dangling_idref', element: 'div');
        $this->assertSame(['attribute' => 'aria-describedby', 'id' => 'missing'], $violation->params);
        $this->assertCount(1, $violations);
    }

    public function test_detects_focusable_elements_with_aria_hidden(): void
    {
        $html = '<button aria-hidden="true">Hidden Button</button>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'aria.hidden_focusable', element: 'button', severity: Severity::Error);
        $this->assertSame(['tag' => 'button'], $violation->params);
        $this->assertCount(1, $violations);
    }

    public function test_detects_conflicting_label_attributes(): void
    {
        $html = '<div aria-label="Label" aria-labelledby="named">Content</div><span id="named">Named</span>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'aria.label_conflict', element: 'div', severity: Severity::Warning);
        $this->assertSame('4.1.2', $violation->rule);
        $this->assertSame([], $violation->params);
        $this->assertCount(1, $violations);
    }

    public function test_detects_interactive_aria_attributes_on_non_interactive_elements(): void
    {
        $html = '<div aria-pressed="true">Toggle</div>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'aria.unsupported_state', element: 'div', severity: Severity::Warning);
        $this->assertSame(['attribute' => 'aria-pressed', 'tag' => 'div'], $violation->params);
        $this->assertCount(1, $violations);
    }

    public function test_interactive_role_allows_the_state_attribute(): void
    {
        $html = '<div role="button" aria-pressed="true">Toggle</div>';

        $this->assertNoViolation($this->analyze($html), 'aria.unsupported_state');
    }

    public function test_valid_aria_passes(): void
    {
        $html = '
            <button aria-label="Save document">Save</button>
            <div role="navigation" aria-label="Main navigation">Nav</div>
            <input type="text" aria-describedby="help-text">
            <span id="help-text">Enter your name</span>
        ';

        $this->assertSame([], $this->analyze($html));
    }
}
