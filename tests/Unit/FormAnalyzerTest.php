<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Analyzers\FormAnalyzer;
use ItsJustVita\LaravelBfsg\Contracts\Analyzer;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\Support\AnalyzerTestCase;

class FormAnalyzerTest extends AnalyzerTestCase
{
    protected function analyzer(): Analyzer
    {
        return new FormAnalyzer;
    }

    public function test_detects_inputs_without_labels(): void
    {
        $html = '<form><h2>Contact Form</h2><input type="text" name="email"></form>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'forms.control_missing_label', element: 'input', severity: Severity::Error);
        $this->assertSame('4.1.2', $violation->rule);
        $this->assertSame(['1.3.1', '3.3.2'], $violation->related);
        $this->assertSame(['name' => 'email', 'type' => 'input'], $violation->params);
        $this->assertCount(1, $violations);
    }

    public function test_accepts_inputs_with_labels(): void
    {
        $html = '<form><h2>Form</h2><label for="email">Email</label><input type="text" id="email" name="email"></form>';

        $this->assertSame([], $this->analyze($html));
    }

    public function test_accepts_inputs_with_aria_label(): void
    {
        $html = '<form aria-label="Contact Form"><input type="text" name="email" aria-label="Email address"></form>';

        $this->assertSame([], $this->analyze($html));
    }

    public function test_warns_about_required_fields_without_aria_required(): void
    {
        $html = '<form><legend>Form</legend><input type="text" name="email" required aria-label="Email"></form>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'forms.required_missing_aria_required', element: 'input', severity: Severity::Warning);
        $this->assertSame('3.3.2', $violation->rule);
        $this->assertSame(['name' => 'email'], $violation->params);
        $this->assertCount(1, $violations);
    }

    public function test_detects_textareas_without_labels(): void
    {
        $html = '<form><legend>Contact</legend><textarea name="message"></textarea></form>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'forms.control_missing_label', element: 'textarea', severity: Severity::Error);
        $this->assertSame(['name' => 'message', 'type' => 'textarea'], $violation->params);
        $this->assertCount(1, $violations);
    }

    public function test_detects_selects_without_labels(): void
    {
        $html = '<form><legend>Contact</legend><select name="country"><option>DE</option></select></form>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'forms.control_missing_label', element: 'select', severity: Severity::Error);
        $this->assertSame(['name' => 'country', 'type' => 'select'], $violation->params);
        $this->assertCount(1, $violations);
    }

    public function test_warns_about_form_without_descriptive_label_or_heading(): void
    {
        $html = '<form><input type="text" name="email" aria-label="Email"></form>';

        $violations = $this->analyze($html);

        $this->assertHasViolation($violations, 'forms.form_missing_name', element: 'form', severity: Severity::Warning);
        $this->assertViolationCount($violations, 'forms.form_missing_name', 1);
    }

    public function test_uses_id_as_fallback_name_when_no_name_attribute(): void
    {
        $html = '<form><h2>Form</h2><input type="text" id="phone"></form>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'forms.control_missing_label', element: 'input#phone');
        $this->assertSame('phone', $violation->params['name']);
    }
}
