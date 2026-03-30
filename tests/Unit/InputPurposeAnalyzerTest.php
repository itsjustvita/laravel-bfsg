<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use DOMDocument;
use ItsJustVita\LaravelBfsg\Analyzers\InputPurposeAnalyzer;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class InputPurposeAnalyzerTest extends TestCase
{
    public function test_detects_email_input_without_autocomplete(): void
    {
        $html = '<form><input type="email" name="email"></form>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $analyzer = new InputPurposeAnalyzer;
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertCount(1, $violations);
        $this->assertEquals('warning', $violations[0]['type']);
        $this->assertEquals('WCAG 1.3.5', $violations[0]['rule']);
    }

    public function test_accepts_input_with_valid_autocomplete(): void
    {
        $html = '<form><input type="email" name="email" autocomplete="email"></form>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $analyzer = new InputPurposeAnalyzer;
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertEmpty($violations);
    }

    public function test_detects_invalid_autocomplete_value(): void
    {
        $html = '<form><input type="text" name="email" autocomplete="invalid-value"></form>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $analyzer = new InputPurposeAnalyzer;
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertCount(1, $violations);
        $this->assertEquals('error', $violations[0]['type']);
        $this->assertStringContainsString('Invalid autocomplete value', $violations[0]['message']);
    }

    public function test_ignores_non_personal_field_without_autocomplete(): void
    {
        $html = '<form><input type="text" name="search" id="search-box"></form>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $analyzer = new InputPurposeAnalyzer;
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertEmpty($violations);
    }

    public function test_detects_phone_input_by_name_attribute(): void
    {
        $html = '<form><input type="text" name="phone_number"></form>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $analyzer = new InputPurposeAnalyzer;
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertCount(1, $violations);
        $this->assertEquals('warning', $violations[0]['type']);
        $this->assertStringContainsString('Personal data input', $violations[0]['message']);
    }
}
