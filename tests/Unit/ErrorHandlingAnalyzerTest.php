<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Analyzers\ErrorHandlingAnalyzer;
use ItsJustVita\LaravelBfsg\Contracts\Analyzer;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\Support\AnalyzerTestCase;

class ErrorHandlingAnalyzerTest extends AnalyzerTestCase
{
    protected function analyzer(): Analyzer
    {
        return new ErrorHandlingAnalyzer;
    }

    public function test_novalidate_form_without_error_strategy_is_a_notice(): void
    {
        $violations = $this->analyze('<form id="contact" novalidate><label for="e">E-Mail</label><input type="email" id="e" name="email"><button>Send</button></form>');

        $violation = $this->assertHasViolation($violations, 'error_handling.no_error_strategy', element: 'form#contact', severity: Severity::Notice);
        $this->assertSame('3.3.1', $violation->rule);
        $this->assertSame([], $violation->related);
        $this->assertSame(['name' => 'contact'], $violation->params);
        $this->assertCount(1, $violations);
    }

    public function test_forms_using_browser_validation_are_not_reported(): void
    {
        $this->assertSame([], $this->analyze('<form><input type="text" required></form>'));
    }

    public function test_formnovalidate_on_a_submit_control_counts_as_bypass(): void
    {
        $violations = $this->analyze('<form id="f"><input type="text" pattern="[0-9]+"><button formnovalidate>Save draft</button></form>');

        $this->assertHasViolation($violations, 'error_handling.no_error_strategy', element: 'form#f');
    }

    public function test_hidden_formnovalidate_controls_do_not_count_as_bypass(): void
    {
        $this->assertSame([], $this->analyze('<form><input type="email" name="e" aria-label="E-Mail"><button formnovalidate hidden>Draft</button>'
            .'<div style="display:none"><button formnovalidate>Skip</button></div></form>'));
    }

    public function test_forms_without_validated_fields_are_not_reported(): void
    {
        $this->assertSame([], $this->analyze('<form novalidate><input type="text" name="q"><input type="hidden" name="t" required></form>'));
    }

    public function test_aria_attributes_on_fields_are_a_strategy(): void
    {
        foreach (['aria-invalid="false"', 'aria-errormessage="err"', 'aria-describedby="hint"'] as $attribute) {
            $this->assertSame([], $this->analyze('<form novalidate><input required '.$attribute.'></form>'), $attribute);
        }
    }

    public function test_live_messages_inside_or_next_to_the_form_are_a_strategy(): void
    {
        $this->assertSame([], $this->analyze('<form novalidate><div role="ALERT"></div><input required></form>'));
        $this->assertSame([], $this->analyze('<div><div role="status"></div><form novalidate><input required></form></div>'));
        $this->assertSame([], $this->analyze('<form novalidate><output name="result"></output><input required></form>'));
    }

    public function test_error_class_tokens_are_a_strategy_but_substrings_are_not(): void
    {
        $this->assertSame([], $this->analyze('<form novalidate><input required class="form-control is-invalid"></form>'));
        $this->assertHasViolation($this->analyze('<form novalidate><input required class="terrorism-report"></form>'), 'error_handling.no_error_strategy');
    }

    public function test_name_param_prefers_id_then_name_then_action(): void
    {
        $both = $this->analyze('<form id="by-id" name="by-name" action="/x" novalidate><input required></form>');
        $this->assertSame(['name' => 'by-id'], $this->assertHasViolation($both, 'error_handling.no_error_strategy')->params);

        $byName = $this->analyze('<form name="contact" action="/x" novalidate><input required></form>');
        $this->assertSame(['name' => 'contact'], $this->assertHasViolation($byName, 'error_handling.no_error_strategy')->params);

        $byAction = $this->analyze('<form action="/subscribe" novalidate><input required></form>');
        $this->assertSame(['name' => '/subscribe'], $this->assertHasViolation($byAction, 'error_handling.no_error_strategy')->params);

        $unnamed = $this->analyze('<form novalidate><input required></form>');
        $this->assertSame(['name' => 'unnamed'], $this->assertHasViolation($unnamed, 'error_handling.no_error_strategy')->params);
    }

    public function test_reports_each_form_separately(): void
    {
        $violations = $this->analyze('<html><body><form id="a" novalidate><input required></form><form id="b" novalidate><input required></form></body></html>');

        $this->assertViolationCount($violations, 'error_handling.no_error_strategy', 2);
    }
}
