<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Analyzers\InputPurposeAnalyzer;
use ItsJustVita\LaravelBfsg\Contracts\Analyzer;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\Support\AnalyzerTestCase;

class InputPurposeAnalyzerTest extends AnalyzerTestCase
{
    protected function analyzer(): Analyzer
    {
        return new InputPurposeAnalyzer;
    }

    public function test_detects_email_input_without_autocomplete(): void
    {
        $violations = $this->analyze('<form><input type="email" name="email"></form>');

        $violation = $this->assertHasViolation($violations, 'input_purpose.missing_autocomplete', element: 'input', severity: Severity::Warning);
        $this->assertSame('1.3.5', $violation->rule);
        $this->assertSame(['name' => 'email'], $violation->params);
        $this->assertCount(1, $violations);
    }

    public function test_accepts_input_with_valid_autocomplete(): void
    {
        $this->assertSame([], $this->analyze('<form><input type="email" name="email" autocomplete="email"></form>'));
    }

    public function test_detects_invalid_autocomplete_value(): void
    {
        $violations = $this->analyze('<form><input type="text" name="email" autocomplete="invalid-value"></form>');

        $violation = $this->assertHasViolation($violations, 'input_purpose.invalid_autocomplete', element: 'input', severity: Severity::Error);
        $this->assertSame('1.3.5', $violation->rule);
        $this->assertSame(['name' => 'email', 'value' => 'invalid-value'], $violation->params);
        $this->assertCount(1, $violations);
    }

    public function test_ignores_non_personal_field_without_autocomplete(): void
    {
        $this->assertSame([], $this->analyze('<form><input type="text" name="search" id="search-box"></form>'));
    }

    public function test_detects_phone_input_by_name_attribute(): void
    {
        $violations = $this->analyze('<form><input type="text" name="phone_number"></form>');

        $violation = $this->assertHasViolation($violations, 'input_purpose.missing_autocomplete', element: 'input', severity: Severity::Warning);
        $this->assertSame(['name' => 'phone_number'], $violation->params);
        $this->assertCount(1, $violations);
    }

    public function test_falls_back_to_id_and_unnamed_for_the_name_param(): void
    {
        $violations = $this->analyze('<form><input type="email" id="contact-email"><input type="tel"></form>');

        $byId = $this->assertHasViolation($violations, 'input_purpose.missing_autocomplete', element: 'input#contact-email');
        $this->assertSame(['name' => 'contact-email'], $byId->params);

        $this->assertViolationCount($violations, 'input_purpose.missing_autocomplete', 2);
        $unnamed = array_values(array_filter($violations, fn ($v) => $v->params === ['name' => 'unnamed']));
        $this->assertCount(1, $unnamed);
    }
}
