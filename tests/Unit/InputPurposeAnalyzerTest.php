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

    public function test_personal_fields_without_autocomplete(): void
    {
        $violations = $this->analyze('<form><input type="email" name="email"><input name="user[first_name]"><input TYPE="Tel" id="telefon"></form>');

        $violation = $this->assertHasViolation($violations, 'input_purpose.missing_autocomplete', element: 'input', severity: Severity::Warning);
        $this->assertSame('1.3.5', $violation->rule);
        $this->assertSame(['name' => 'email'], $violation->params);
        $this->assertViolationCount($violations, 'input_purpose.missing_autocomplete', 3);
    }

    public function test_personal_data_is_matched_on_whole_tokens(): void
    {
        $html = '<form><input name="billing.plz"><input name="E-Mail"><input name="cc-number"><input name="Straße">'
            .'<input name="username_or_email"><input name="nameless_search"><input name="hostname"><input name="landing_page"><input name="q"></form>';

        $violations = $this->analyze($html);

        $this->assertSame(['billing.plz', 'E-Mail', 'cc-number', 'Straße', 'username_or_email'], array_map(fn ($v) => $v->params['name'], $violations));
    }

    public function test_non_text_types_are_excluded(): void
    {
        $html = '<form><input type="checkbox" name="email_opt_in"><input type="radio" name="country"><input type="file" name="name">'
            .'<input type="range" name="zip"><input type="color" name="firma"><input type="image" name="name" alt="Go"><input type="hidden" name="email"></form>';

        $this->assertSame([], $this->analyze($html));
    }

    public function test_valid_autocomplete_grammar(): void
    {
        $analyzer = new InputPurposeAnalyzer;

        foreach (['email', 'EMAIL', 'on', 'off', 'section-blue shipping street-address', 'billing work tel', 'home email', 'username webauthn', 'section-a billing cc-number', 'tel-local-suffix'] as $value) {
            $this->assertTrue($analyzer->isValidAutocomplete($value), $value);
        }

        foreach (['e-mail', 'work name', 'section- email', 'shipping', 'email tel', 'off email', 'firstname', 'webauthn'] as $value) {
            $this->assertFalse($analyzer->isValidAutocomplete($value), $value);
        }
    }

    public function test_invalid_autocomplete_is_an_error_on_any_field(): void
    {
        $violations = $this->analyze('<form><input name="email" autocomplete="e-mail"><select name="land" autocomplete="nation"><option>DE</option></select></form>');

        $violation = $this->assertHasViolation($violations, 'input_purpose.invalid_autocomplete', element: 'input', severity: Severity::Error);
        $this->assertSame(['name' => 'email', 'value' => 'e-mail'], $violation->params);
        $this->assertViolationCount($violations, 'input_purpose.invalid_autocomplete', 2);
        $this->assertNoViolation($violations, 'input_purpose.missing_autocomplete');
    }

    public function test_autocomplete_off_on_personal_field_is_a_notice(): void
    {
        $violations = $this->analyze('<form><input name="email" autocomplete="OFF"><input name="coupon" autocomplete="off"></form>');

        $violation = $this->assertHasViolation($violations, 'input_purpose.autocomplete_off_on_personal_field', severity: Severity::Notice);
        $this->assertSame(['name' => 'email'], $violation->params);
        $this->assertCount(1, $violations);
    }

    public function test_name_param_falls_back_to_id_then_unnamed(): void
    {
        $violations = $this->analyze('<form><input id="email"><input autocomplete="bogus"></form>');

        $this->assertSame('email', $this->assertHasViolation($violations, 'input_purpose.missing_autocomplete')->params['name']);
        $this->assertSame('unnamed', $this->assertHasViolation($violations, 'input_purpose.invalid_autocomplete')->params['name']);
    }

    public function test_complete_form_passes(): void
    {
        $this->assertSame([], $this->analyze('<form><input type="email" name="email" autocomplete="email"><input name="vorname" autocomplete="given-name"><input name="search"></form>'));
    }

    public function test_names_of_things_other_than_people_are_not_personal(): void
    {
        $violations = $this->analyze('<form><input name="product_name"><input name="category[name]"><input name="file-name"><input name="projekt_name">'
            .'<input name="first_name"><input name="contact_name"><input name="name"></form>');

        $this->assertSame(['first_name', 'contact_name', 'name'], array_map(fn ($v) => $v->params['name'], $violations));
    }

    public function test_search_fields_are_never_personal(): void
    {
        $this->assertSame([], $this->analyze('<form role="search"><input name="name"></form><search><input name="email"></search><input type="search" name="city">'));
    }

    public function test_autocomplete_values_inside_search_are_still_validated(): void
    {
        $violations = $this->analyze('<form role="search"><input name="q" autocomplete="serach"></form><search><input name="email" autocomplete="emial"></search>');

        $this->assertViolationCount($violations, 'input_purpose.invalid_autocomplete', 2);
    }

    public function test_autocomplete_off_synonyms_are_not_errors(): void
    {
        $html = '<label for="d">Delivery date</label><input id="d" name="delivery_date" type="text" autocomplete="false"><label for="s">Search products</label><input id="s" name="q" type="text" autocomplete="none"><input type="hidden" name="t" autocomplete="nope">';

        $violations = $this->analyze($html);

        foreach ($violations as $violation) {
            $this->assertNotSame(Severity::Error, $violation->severity);
            $this->assertSame('input_purpose.invalid_autocomplete', $violation->key);
            $this->assertSame(Severity::Notice, $violation->severity);
            $this->assertContains('best-practice', $violation->tags);
        }
        $this->assertCount(2, $violations);
    }

    public function test_invalid_autocomplete_on_a_personal_data_field_is_still_an_error(): void
    {
        $violations = $this->analyze('<input name="email" autocomplete="emial">');

        $this->assertHasViolation($violations, 'input_purpose.invalid_autocomplete', element: 'input', severity: Severity::Error);
    }

    public function test_off_synonym_on_a_personal_data_field_behaves_like_off(): void
    {
        foreach (['false', 'none', 'nope', 'disabled', 'true'] as $value) {
            $violations = $this->analyze('<input name="email" autocomplete="'.$value.'">');

            $this->assertHasViolation($violations, 'input_purpose.autocomplete_off_on_personal_field', severity: Severity::Notice);
            $this->assertViolationCount($violations, 'input_purpose.invalid_autocomplete', 0);
        }
    }
}
