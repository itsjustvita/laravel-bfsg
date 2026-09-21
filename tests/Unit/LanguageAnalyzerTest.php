<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Analyzers\LanguageAnalyzer;
use ItsJustVita\LaravelBfsg\Contracts\Analyzer;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\Support\AnalyzerTestCase;

class LanguageAnalyzerTest extends AnalyzerTestCase
{
    protected function analyzer(): Analyzer
    {
        return new LanguageAnalyzer;
    }

    public function test_detects_missing_lang_attribute_on_html(): void
    {
        $violations = $this->analyze('<html><body><p>Hello</p></body></html>');

        $violation = $this->assertHasViolation($violations, 'language.missing_lang', element: 'html', severity: Severity::Error);
        $this->assertSame('3.1.1', $violation->rule);
        $this->assertSame([], $violation->params);
        $this->assertViolationCount($violations, 'language.missing_lang', 1);
    }

    public function test_valid_lang_en_produces_no_lang_missing_issues(): void
    {
        $violations = $this->analyze('<html lang="en"><body><p>Hello world</p></body></html>');

        $this->assertNoViolation($violations, 'language.missing_lang');
        $this->assertNoViolation($violations, 'language.no_html_element');
    }

    public function test_detects_invalid_language_code(): void
    {
        $violations = $this->analyze('<html lang="xx"><body><p>Hello</p></body></html>');

        $violation = $this->assertHasViolation($violations, 'language.invalid_lang', element: 'html', severity: Severity::Error);
        $this->assertSame('3.1.1', $violation->rule);
        $this->assertSame(['lang' => 'xx'], $violation->params);
    }

    public function test_detects_invalid_language_code_on_a_part_of_the_page(): void
    {
        $violations = $this->analyze('<html lang="en"><body><p lang="xx">Hello</p></body></html>');

        $violation = $this->assertHasViolation($violations, 'language.invalid_lang', element: 'p', severity: Severity::Error);
        $this->assertSame('3.1.2', $violation->rule);
        $this->assertSame(['lang' => 'xx'], $violation->params);
    }

    public function test_german_lang_de_accepted(): void
    {
        $violations = $this->analyze('<html lang="de"><body><p>Hallo Welt</p></body></html>');

        $this->assertNoViolation($violations, 'language.invalid_lang');
        $this->assertNoViolation($violations, 'language.missing_lang');
    }

    public function test_detects_possible_language_change_without_lang_attribute(): void
    {
        $violations = $this->analyze('<html lang="de"><body><p>Der Text mit the and for extra Inhalt.</p></body></html>');

        $violation = $this->assertHasViolation($violations, 'language.possible_language_change', element: 'p', severity: Severity::Warning);
        $this->assertSame('3.1.2', $violation->rule);
        $this->assertSame(['content' => 'Der Text mit the and for extra Inhalt.'], $violation->params);
    }

    public function test_detects_mismatched_lang_and_xml_lang(): void
    {
        $violations = $this->analyze('<html lang="en" xml:lang="de"><body><p>Hello</p></body></html>');

        $violation = $this->assertHasViolation($violations, 'language.xml_lang_mismatch', element: 'html', severity: Severity::Warning);
        $this->assertSame('3.1.1', $violation->rule);
        $this->assertSame(['lang' => 'en', 'xml_lang' => 'de'], $violation->params);
    }

    public function test_matching_lang_and_xml_lang_are_accepted(): void
    {
        $violations = $this->analyze('<html lang="en" xml:lang="en"><body><p>Hello</p></body></html>');

        $this->assertNoViolation($violations, 'language.xml_lang_mismatch');
    }

    public function test_document_without_html_element_is_reported(): void
    {
        $violations = $this->analyze('<div>fragment</div>');

        $violation = $this->assertHasViolation($violations, 'language.no_html_element', severity: Severity::Error);
        $this->assertSame('3.1.1', $violation->rule);
        $this->assertNull($violation->element);
        $this->assertNull($violation->selector);
    }
}
