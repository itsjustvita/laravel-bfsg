<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use DOMDocument;
use ItsJustVita\LaravelBfsg\Analyzers\LanguageAnalyzer;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class LanguageAnalyzerTest extends TestCase
{
    protected LanguageAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new LanguageAnalyzer;
    }

    protected function analyzeHtml(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        return $this->analyzer->analyze($dom);
    }

    public function test_detects_missing_lang_attribute_on_html()
    {
        $result = $this->analyzeHtml('<html><body><p>Hello</p></body></html>');

        $this->assertNotEmpty($result['issues']);
        $this->assertTrue(
            collect($result['issues'])->contains(fn ($i) => str_contains($i['message'], 'Missing language attribute')),
            'Should detect missing lang attribute on html element'
        );
    }

    public function test_valid_lang_en_produces_no_lang_missing_issues()
    {
        $result = $this->analyzeHtml('<html lang="en"><body><p>Hello world</p></body></html>');

        $langMissing = collect($result['issues'])->filter(
            fn ($i) => str_contains($i['message'], 'Missing language attribute')
                || str_contains($i['message'], 'No html element')
        );

        $this->assertEmpty($langMissing, 'Valid lang="en" should not produce lang-missing issues');
    }

    public function test_detects_invalid_language_code()
    {
        $result = $this->analyzeHtml('<html lang="xx"><body><p>Hello</p></body></html>');

        $this->assertNotEmpty($result['issues']);
        $this->assertTrue(
            collect($result['issues'])->contains(fn ($i) => str_contains($i['message'], 'Invalid language code')),
            'Should detect invalid language code "xx"'
        );
    }

    public function test_german_lang_de_accepted()
    {
        $result = $this->analyzeHtml('<html lang="de"><body><p>Hallo Welt</p></body></html>');

        $invalidLang = collect($result['issues'])->filter(
            fn ($i) => str_contains($i['message'], 'Invalid language code')
                || str_contains($i['message'], 'Missing language attribute')
        );

        $this->assertEmpty($invalidLang, 'German lang="de" should be accepted as valid');
    }

    public function test_detects_mismatched_lang_and_xml_lang()
    {
        // xml:lang is only preserved when using loadXML, not loadHTML
        $dom = new DOMDocument;
        @$dom->loadXML('<html lang="en" xml:lang="de"><body><p>Hello</p></body></html>');
        $result = $this->analyzer->analyze($dom);

        $this->assertTrue(
            collect($result['issues'])->contains(fn ($i) => str_contains($i['message'], 'Mismatched lang and xml:lang')),
            'Should detect mismatched lang and xml:lang attributes'
        );
    }

    public function test_empty_html_produces_lang_issues()
    {
        // Use a minimal document fragment without an html element
        $result = $this->analyzeHtml('<div>fragment</div>');

        $this->assertNotEmpty($result['issues']);
        $this->assertTrue(
            collect($result['issues'])->contains(
                fn ($i) => str_contains($i['message'], 'Missing language attribute')
                    || str_contains($i['message'], 'No html element')
            ),
            'Minimal HTML without proper structure should produce language-related issues'
        );
    }
}
