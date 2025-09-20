<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use DOMDocument;
use ItsJustVita\LaravelBfsg\Analyzers\AriaAnalyzer;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class AriaAnalyzerTest extends TestCase
{
    protected AriaAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new AriaAnalyzer();
    }

    public function test_detects_invalid_aria_roles()
    {
        $html = '<div role="invalid-role">Content</div>';
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        $this->assertNotEmpty($results['issues']);
        $this->assertStringContainsString("Invalid ARIA role: 'invalid-role'", $results['issues'][0]['message']);
    }

    public function test_detects_redundant_aria_roles()
    {
        $html = '<input type="checkbox" role="checkbox">';
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        $this->assertNotEmpty($results['issues']);
        $this->assertStringContainsString('Redundant ARIA role', $results['issues'][0]['message']);
    }

    public function test_detects_missing_required_aria_attributes()
    {
        $html = '<div role="slider">Slider</div>';
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        $this->assertNotEmpty($results['issues']);
        // Should detect missing aria-valuenow, aria-valuemin, aria-valuemax
        $this->assertCount(3, $results['issues']);
    }

    public function test_detects_invalid_aria_labelledby_references()
    {
        $html = '<div aria-labelledby="non-existent">Content</div>';
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        $this->assertNotEmpty($results['issues']);
        $this->assertStringContainsString('non-existent', $results['issues'][0]['message']);
    }

    public function test_detects_focusable_elements_with_aria_hidden()
    {
        $html = '<button aria-hidden="true">Hidden Button</button>';
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        $this->assertNotEmpty($results['issues']);
        $this->assertStringContainsString('Focusable element with aria-hidden', $results['issues'][0]['message']);
    }

    public function test_valid_aria_passes()
    {
        $html = '
            <button aria-label="Save document">Save</button>
            <div role="navigation" aria-label="Main navigation">Nav</div>
            <input type="text" aria-describedby="help-text">
            <span id="help-text">Enter your name</span>
        ';
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        $this->assertEmpty($results['issues']);
    }
}