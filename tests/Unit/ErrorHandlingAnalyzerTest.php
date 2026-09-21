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

    public function test_detects_form_with_required_field_but_no_error_handling(): void
    {
        $violations = $this->analyze('<form><input type="text" name="name" required><button type="submit">Submit</button></form>');

        $violation = $this->assertHasViolation($violations, 'error_handling.no_error_strategy', element: 'form', severity: Severity::Warning);
        $this->assertSame('3.3.1', $violation->rule);
        $this->assertSame(['3.3.3'], $violation->related);
        $this->assertSame(['name' => 'unnamed'], $violation->params);
        $this->assertFalse($violation->autoFixable);
        $this->assertCount(1, $violations);
    }

    public function test_accepts_form_with_aria_invalid_on_inputs(): void
    {
        $this->assertSame([], $this->analyze('<form><input type="text" name="name" required aria-invalid="false"><button type="submit">Submit</button></form>'));
    }

    public function test_accepts_form_with_role_alert(): void
    {
        $this->assertSame([], $this->analyze('<form><input type="text" name="name" required><div role="alert"></div><button type="submit">Submit</button></form>'));
    }

    public function test_no_issues_without_forms(): void
    {
        $this->assertSame([], $this->analyze('<html><body><p>No forms here</p></body></html>'));
    }

    public function test_ignores_forms_without_required_fields(): void
    {
        $this->assertNoViolation(
            $this->analyze('<form><input type="text" name="search"><button type="submit">Search</button></form>'),
            'error_handling.no_error_strategy',
        );
    }

    public function test_detects_css_only_error_indicators(): void
    {
        $violations = $this->analyze('<form id="signup"><input type="text" name="email" required><span class="error">Required</span><button type="submit">Submit</button></form>');

        $violation = $this->assertHasViolation($violations, 'error_handling.css_only_error_indicators', element: 'form#signup', severity: Severity::Notice);
        $this->assertSame('3.3.1', $violation->rule);
        $this->assertSame(['3.3.3'], $violation->related);
        $this->assertSame(['name' => 'signup'], $violation->params);
        $this->assertNoViolation($violations, 'error_handling.no_error_strategy');
        $this->assertCount(1, $violations);
    }

    public function test_falls_back_to_name_action_and_unnamed_for_the_name_param(): void
    {
        $byName = $this->analyze('<form name="contact"><input type="text" required></form>');
        $this->assertSame(['name' => 'contact'], $this->assertHasViolation($byName, 'error_handling.no_error_strategy')->params);

        $byAction = $this->analyze('<form action="/subscribe"><input type="text" required></form>');
        $this->assertSame(['name' => '/subscribe'], $this->assertHasViolation($byAction, 'error_handling.no_error_strategy')->params);

        $unnamed = $this->analyze('<form><input type="text" required></form>');
        $this->assertSame(['name' => 'unnamed'], $this->assertHasViolation($unnamed, 'error_handling.no_error_strategy')->params);
    }

    public function test_reports_each_form_separately(): void
    {
        $violations = $this->analyze(
            '<html><body><form id="a"><input type="text" required></form><form id="b"><input type="text" required></form></body></html>'
        );

        $this->assertViolationCount($violations, 'error_handling.no_error_strategy', 2);
        $this->assertHasViolation($violations, 'error_handling.no_error_strategy', element: 'form#a');
        $this->assertHasViolation($violations, 'error_handling.no_error_strategy', element: 'form#b');
    }
}
