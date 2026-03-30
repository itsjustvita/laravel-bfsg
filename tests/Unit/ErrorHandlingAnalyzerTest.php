<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use DOMDocument;
use ItsJustVita\LaravelBfsg\Analyzers\ErrorHandlingAnalyzer;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class ErrorHandlingAnalyzerTest extends TestCase
{
    public function test_detects_form_with_required_field_but_no_error_handling(): void
    {
        $html = '<form><input type="text" name="name" required><button type="submit">Submit</button></form>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $analyzer = new ErrorHandlingAnalyzer;
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertCount(1, $violations);
        $this->assertEquals('warning', $violations[0]['type']);
        $this->assertEquals('WCAG 3.3.1, 3.3.3', $violations[0]['rule']);
        $this->assertStringContainsString('no detectable error handling', $violations[0]['message']);
    }

    public function test_accepts_form_with_aria_invalid_on_inputs(): void
    {
        $html = '<form><input type="text" name="name" required aria-invalid="false"><button type="submit">Submit</button></form>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $analyzer = new ErrorHandlingAnalyzer;
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertEmpty($violations);
    }

    public function test_accepts_form_with_role_alert(): void
    {
        $html = '<form><input type="text" name="name" required><div role="alert"></div><button type="submit">Submit</button></form>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $analyzer = new ErrorHandlingAnalyzer;
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertEmpty($violations);
    }

    public function test_no_issues_without_forms(): void
    {
        $html = '<html><body><p>No forms here</p></body></html>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $analyzer = new ErrorHandlingAnalyzer;
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertEmpty($violations);
    }
}
