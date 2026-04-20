<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use DOMDocument;
use ItsJustVita\LaravelBfsg\Analyzers\ContrastAnalyzer;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class ContrastAnalyzerTest extends TestCase
{
    protected ContrastAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new ContrastAnalyzer;
    }

    public function test_detects_low_contrast_text()
    {
        $html = '
            <p style="color: #999; background-color: #fff;">Low contrast text</p>
            <p style="color: #aaa; background-color: #fff;">Very low contrast</p>
        ';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        $this->assertNotEmpty($results['issues']);

        $contrastIssues = collect($results['issues'])->filter(
            fn ($i) => str_contains($i['message'], 'Insufficient contrast ratio')
        );
        $this->assertNotEmpty($contrastIssues, 'Should detect low contrast from inline styles');
    }

    public function test_detects_light_gray_text()
    {
        $html = '<p style="color: #ccc;">Light gray text</p>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        $this->assertNotEmpty($results['issues']);
        $this->assertStringContainsString('Light gray text', $results['issues'][0]['message']);
    }

    public function test_detects_placeholder_contrast_issues()
    {
        $html = '<input type="text" placeholder="Enter text here">';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        $this->assertNotEmpty($results['issues']);
        $this->assertStringContainsString('Placeholder text', $results['issues'][0]['message']);
    }

    public function test_high_contrast_passes()
    {
        $html = '
            <p style="color: #000; background-color: #fff;">High contrast black on white</p>
            <p style="color: #fff; background-color: #000;">High contrast white on black</p>
        ';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        // Should not flag high contrast text
        $issueCount = count($results['issues']);
        $this->assertEquals(0, $issueCount);
    }

    public function test_calculates_contrast_ratio_correctly()
    {
        $html = '<p style="color: #767676; background-color: #ffffff;">4.54:1 contrast ratio</p>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        // This should pass WCAG AA (4.5:1 minimum) - filter to only contrast ratio issues
        $contrastIssues = collect($results['issues'])->filter(
            fn ($i) => str_contains($i['rule'] ?? '', '1.4.3') && str_contains($i['message'] ?? '', 'Insufficient')
        );
        $this->assertEmpty($contrastIssues);
    }

    public function test_detects_low_contrast_from_css_classes(): void
    {
        $html = '<html><head><style>.muted { color: #999999; background-color: #aaaaaa; }</style></head>'
            .'<body><p class="muted" id="target">Hard to read text</p></body></html>';

        $result = $this->analyzeHtml($html);

        $this->assertTrue(
            collect($result['issues'])->contains(fn ($i) => str_contains($i['message'], 'contrast') || str_contains($i['message'], 'Insufficient')
            ),
            'Should detect low contrast from CSS classes'
        );
    }

    public function test_css_with_good_contrast_no_violation(): void
    {
        $html = '<html><head><style>p { color: #000000; background-color: #ffffff; }</style></head>'
            .'<body><p>Perfectly readable text</p></body></html>';

        $result = $this->analyzeHtml($html);

        $contrastIssues = collect($result['issues'])->filter(
            fn ($i) => str_contains($i['rule'] ?? '', '1.4.3') && str_contains($i['message'] ?? '', 'Insufficient')
        );
        $this->assertEmpty($contrastIssues, 'Good contrast should not produce WCAG 1.4.3 violations');
    }

    public function test_inherited_color_marked_approximate(): void
    {
        $html = '<html><head><style>.container { color: #cccccc; background-color: #dddddd; }</style></head>'
            .'<body><div class="container"><p id="target">Inherited poor contrast</p></div></body></html>';

        $result = $this->analyzeHtml($html);

        $approxIssues = collect($result['issues'])->filter(
            fn ($i) => isset($i['approximate']) && $i['approximate'] === true
        );
        // Should have approximate flag since colors are inherited
        $contrastIssues = collect($result['issues'])->filter(
            fn ($i) => str_contains($i['message'] ?? '', 'approximate') || (isset($i['approximate']) && $i['approximate'])
        );
        $this->assertNotEmpty($contrastIssues, 'Inherited colors should be marked as approximate');
    }

    public function test_light_gray_text_xpath_only_matches_elements_with_style(): void
    {
        // v2.2.0 Fix 7: Previous XPath missed parens and matched arbitrarily because of
        // precedence of `or` over `and`. With the fix, only elements whose @style actually
        // contains #999/#aaa/#bbb/#ccc should be counted.
        $html = '<html><body>
            <p style="color: #999;">gray text</p>
            <p>plain paragraph with no style</p>
            <div>another plain div</div>
        </body></html>';
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $results = $this->analyzer->analyze($dom);

        $lightGray = collect($results['issues'])->first(
            fn ($i) => str_contains($i['message'], 'Light gray text')
        );

        $this->assertNotNull($lightGray);
        // The pattern matches 1 styled element — NOT the entire document tree.
        $this->assertSame(1, $lightGray['count']);
    }

    public function test_inline_overrides_css_for_contrast(): void
    {
        // CSS sets good contrast, but inline style overrides with bad contrast
        $html = '<html><head><style>p { color: #000000; background-color: #ffffff; }</style></head>'
            .'<body><p style="color: #cccccc;">Overridden to low contrast</p></body></html>';

        $result = $this->analyzeHtml($html);

        // The inline color (#cccccc on white) has a ratio of about 1.6:1 - should be flagged
        $contrastIssues = collect($result['issues'])->filter(
            fn ($i) => str_contains($i['rule'] ?? '', '1.4.3') && str_contains($i['message'] ?? '', 'Insufficient')
        );
        $this->assertNotEmpty($contrastIssues, 'Inline override should be detected for contrast');
    }

    protected function analyzeHtml(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        return $this->analyzer->analyze($dom);
    }
}
