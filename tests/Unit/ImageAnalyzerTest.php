<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use DOMDocument;
use ItsJustVita\LaravelBfsg\Analyzers\ImageAnalyzer;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class ImageAnalyzerTest extends TestCase
{
    public function test_detects_missing_alt_attributes(): void
    {
        $html = '<img src="test.jpg">';
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $analyzer = new ImageAnalyzer();
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertCount(1, $violations);
        $this->assertEquals('Image without alt text found', $violations[0]['message']);
        $this->assertEquals('WCAG 1.1.1', $violations[0]['rule']);
    }

    public function test_accepts_images_with_alt_text(): void
    {
        $html = '<img src="test.jpg" alt="Test image">';
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $analyzer = new ImageAnalyzer();
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertEmpty($violations);
    }

    public function test_accepts_decorative_images_with_empty_alt(): void
    {
        $html = '<img src="decoration.jpg" alt="" role="presentation">';
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $analyzer = new ImageAnalyzer();
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertEmpty($violations);
    }

    public function test_warns_about_empty_alt_without_decorative_role(): void
    {
        $html = '<img src="important.jpg" alt="">';
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $analyzer = new ImageAnalyzer();
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertCount(1, $violations);
        $this->assertEquals('warning', $violations[0]['type']);
        $this->assertEquals('Image with empty alt text may not be decorative', $violations[0]['message']);
    }
}