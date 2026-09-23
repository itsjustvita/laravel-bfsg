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

    public function test_detects_missing_lang_on_html(): void
    {
        $violations = $this->analyze('<!DOCTYPE html><html><head><title>Test</title></head><body><p>Content</p></body></html>');

        $violation = $this->assertHasViolation($violations, 'language.missing_lang', element: 'html', severity: Severity::Error);
        $this->assertSame('3.1.1', $violation->rule);
        $this->assertCount(1, $violations);
    }

    public function test_document_checks_are_skipped_on_fragments(): void
    {
        $this->assertSame([], $this->analyze('<p>Only a fragment</p>'));
    }

    public function test_empty_lang_on_html(): void
    {
        $violations = $this->analyze('<html lang=" "><body><p>x</p></body></html>');

        $this->assertHasViolation($violations, 'language.empty_lang', element: 'html', severity: Severity::Error);
        $this->assertCount(1, $violations);
        $this->assertSame([], $this->analyze('<html lang="de"><body><p lang="">Unbestimmt</p></body></html>'));
    }

    public function test_invalid_lang_syntax_reports_once_per_element(): void
    {
        $violations = $this->analyze('<html lang="de_DE"><body><p lang="english">Hello</p><p lang="de-AT">Servus</p></body></html>');

        $html = $this->assertHasViolation($violations, 'language.invalid_lang', element: 'html', severity: Severity::Error);
        $this->assertSame('3.1.1', $html->rule);
        $this->assertSame(['lang' => 'de_DE'], $html->params);
        $this->assertSame('3.1.2', $this->assertHasViolation($violations, 'language.invalid_lang', element: 'p')->rule);
        $this->assertViolationCount($violations, 'language.invalid_lang', 2);
    }

    public function test_unknown_two_letter_code_is_a_warning(): void
    {
        $violations = $this->analyze('<html lang="xx"><body><p lang="EN-us">Hello</p><p lang="gsw">Grüezi</p></body></html>');

        $violation = $this->assertHasViolation($violations, 'language.unknown_lang', element: 'html', severity: Severity::Warning);
        $this->assertSame('3.1.1', $violation->rule);
        $this->assertSame(['lang' => 'xx'], $violation->params);
        $this->assertCount(1, $violations);
    }

    public function test_possible_language_change_on_direct_text(): void
    {
        $html = '<html lang="de"><body>'
            .'<p id="en">We are proud of the work that our team has done for you and your family this year.</p>'
            .'<p>Wir sind stolz auf die Arbeit, die unser Team in diesem Jahr für Sie und Ihre Familie geleistet hat.</p>'
            .'<blockquote lang="en"><p>The quick brown fox jumps over the lazy dog and runs to the forest with the others.</p></blockquote>'
            .'<div><p>Kurz.</p></div>'
            .'</body></html>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'language.possible_language_change', element: 'p#en', severity: Severity::Warning);
        $this->assertSame('3.1.2', $violation->rule);
        $this->assertSame('en', $violation->params['lang']);
        $this->assertLessThanOrEqual(50, mb_strlen($violation->params['content']));
        $this->assertViolationCount($violations, 'language.possible_language_change', 1);
    }

    public function test_german_text_in_english_page(): void
    {
        $violations = $this->analyze('<html lang="en"><body><p>Die Firma ist seit vielen Jahren für ihre Kunden da und hat sich auf die Beratung spezialisiert.</p></body></html>');

        $this->assertSame('de', $this->assertHasViolation($violations, 'language.possible_language_change')->params['lang']);
    }

    public function test_xml_lang_mismatch_is_case_insensitive(): void
    {
        $violations = $this->analyze('<html lang="en" xml:lang="de"><body><p lang="de-DE" xml:lang="DE-de">x</p></body></html>');

        $violation = $this->assertHasViolation($violations, 'language.xml_lang_mismatch', element: 'html', severity: Severity::Warning);
        $this->assertSame(['lang' => 'en', 'xml_lang' => 'de'], $violation->params);
        $this->assertViolationCount($violations, 'language.xml_lang_mismatch', 1);
    }

    public function test_valid_document_has_no_findings(): void
    {
        $this->assertSame([], $this->analyze('<!DOCTYPE html><html lang="de"><body><p>Willkommen auf unserer Seite.</p><p lang="en-GB">Welcome</p></body></html>'));
    }

    public function test_urls_and_email_addresses_are_not_prose(): void
    {
        $html = '<html lang="de"><body><p>https://example.com/a/very/long/path/that/keeps/going/and/going/until/the/end</p>'
            .'<p>Kontakt: this.is.the.address.of.the.team.and.the.help.desk@example.com</p></body></html>';

        $this->assertNoViolation($this->analyze($html), 'language.possible_language_change');
    }
}
