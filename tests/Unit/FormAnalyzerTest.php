<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use DOMDocument;
use ItsJustVita\LaravelBfsg\Analyzers\FormAnalyzer;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class FormAnalyzerTest extends TestCase
{
    public function test_detects_inputs_without_labels(): void
    {
        $html = '<form><h2>Contact Form</h2><input type="text" name="email"></form>';
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $analyzer = new FormAnalyzer();
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertNotEmpty($violations);
        $this->assertCount(1, $violations);
        $this->assertEquals('Form input without associated label', $violations[0]['message']);
        $this->assertEquals('WCAG 1.3.1, 3.3.2', $violations[0]['rule']);
    }

    public function test_accepts_inputs_with_labels(): void
    {
        $html = '<form><h2>Form</h2><label for="email">Email</label><input type="text" id="email" name="email"></form>';
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $analyzer = new FormAnalyzer();
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertEmpty($violations);
    }

    public function test_accepts_inputs_with_aria_label(): void
    {
        $html = '<form aria-label="Contact Form"><input type="text" name="email" aria-label="Email address"></form>';
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $analyzer = new FormAnalyzer();
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertEmpty($violations);
    }

    public function test_warns_about_required_fields_without_aria_required(): void
    {
        $html = '<form><legend>Form</legend><input type="text" name="email" required aria-label="Email"></form>';
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $analyzer = new FormAnalyzer();
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        // Should have 1 violation: missing aria-required
        $this->assertCount(1, $violations);
        $this->assertEquals('Required field without aria-required attribute', $violations[0]['message']);
        $this->assertEquals('warning', $violations[0]['type']);
    }

    public function test_detects_textareas_without_labels(): void
    {
        $html = '<form><legend>Contact</legend><textarea name="message"></textarea></form>';
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $analyzer = new FormAnalyzer();
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertCount(1, $violations);
        $this->assertEquals('Textarea without associated label', $violations[0]['message']);
    }
}