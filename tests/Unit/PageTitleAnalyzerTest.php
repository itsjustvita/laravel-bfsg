<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use DOMDocument;
use ItsJustVita\LaravelBfsg\Analyzers\PageTitleAnalyzer;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class PageTitleAnalyzerTest extends TestCase
{
    public function test_detects_missing_title(): void
    {
        $html = '<html><head></head><body><p>Hello</p></body></html>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $analyzer = new PageTitleAnalyzer;
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertCount(1, $violations);
        $this->assertEquals('error', $violations[0]['type']);
        $this->assertEquals('WCAG 2.4.2', $violations[0]['rule']);
        $this->assertStringContainsString('missing', $violations[0]['message']);
    }

    public function test_detects_empty_title(): void
    {
        $html = '<html><head><title></title></head><body><p>Hello</p></body></html>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $analyzer = new PageTitleAnalyzer;
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertCount(1, $violations);
        $this->assertEquals('error', $violations[0]['type']);
        $this->assertStringContainsString('empty', $violations[0]['message']);
    }

    public function test_detects_generic_title(): void
    {
        $html = '<html><head><title>Home</title></head><body><p>Hello</p></body></html>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $analyzer = new PageTitleAnalyzer;
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertNotEmpty($violations);
        $hasGenericWarning = false;
        foreach ($violations as $v) {
            if ($v['type'] === 'warning' && str_contains($v['message'], 'generic')) {
                $hasGenericWarning = true;
            }
        }
        $this->assertTrue($hasGenericWarning, 'Expected a warning about generic title');
    }

    public function test_detects_too_short_title(): void
    {
        $html = '<html><head><title>Ab</title></head><body><p>Hello</p></body></html>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $analyzer = new PageTitleAnalyzer;
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertNotEmpty($violations);
        $hasLengthWarning = false;
        foreach ($violations as $v) {
            if ($v['type'] === 'warning' && str_contains($v['message'], 'too short')) {
                $hasLengthWarning = true;
            }
        }
        $this->assertTrue($hasLengthWarning, 'Expected a warning about short title');
    }

    public function test_detects_too_long_title(): void
    {
        $longTitle = str_repeat('A very long page title ', 5);
        $html = '<html><head><title>'.$longTitle.'</title></head><body><p>Hello</p></body></html>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $analyzer = new PageTitleAnalyzer;
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertNotEmpty($violations);
        $hasLengthWarning = false;
        foreach ($violations as $v) {
            if ($v['type'] === 'warning' && str_contains($v['message'], 'too long')) {
                $hasLengthWarning = true;
            }
        }
        $this->assertTrue($hasLengthWarning, 'Expected a warning about long title');
    }

    public function test_detects_german_generic_title_startseite(): void
    {
        // v2.2.0 Fix 6: German "Startseite" is as generic as English "Home".
        $html = '<html><head><title>Startseite</title></head><body><p>Hello</p></body></html>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $analyzer = new PageTitleAnalyzer;
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $hasGenericWarning = false;
        foreach ($violations as $v) {
            if ($v['type'] === 'warning' && str_contains($v['message'], 'generic')) {
                $hasGenericWarning = true;
            }
        }
        $this->assertTrue($hasGenericWarning, 'Expected a warning about German generic title "Startseite"');
    }

    public function test_detects_german_generic_title_willkommen(): void
    {
        // v2.2.0 Fix 6: German "Willkommen" is a generic welcome page title.
        $html = '<html><head><title>Willkommen</title></head><body><p>Hi</p></body></html>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $analyzer = new PageTitleAnalyzer;
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $hasGenericWarning = false;
        foreach ($violations as $v) {
            if ($v['type'] === 'warning' && str_contains($v['message'], 'generic')) {
                $hasGenericWarning = true;
            }
        }
        $this->assertTrue($hasGenericWarning);
    }

    public function test_accepts_good_descriptive_title(): void
    {
        $html = '<html><head><title>About Us - Company Name</title></head><body><p>Hello</p></body></html>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $analyzer = new PageTitleAnalyzer;
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertEmpty($violations);
    }
}
