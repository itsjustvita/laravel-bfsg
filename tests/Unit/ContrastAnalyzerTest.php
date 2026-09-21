<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Analyzers\ContrastAnalyzer;
use ItsJustVita\LaravelBfsg\Contracts\Analyzer;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\Support\AnalyzerTestCase;

class ContrastAnalyzerTest extends AnalyzerTestCase
{
    protected function analyzer(): Analyzer
    {
        return new ContrastAnalyzer;
    }

    public function test_detects_low_contrast_text(): void
    {
        $html = '
            <p style="color: #999; background-color: #fff;">Low contrast text</p>
            <p style="color: #aaa; background-color: #fff;">Very low contrast</p>
        ';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'contrast.insufficient', element: 'p', severity: Severity::Error);
        $this->assertSame('1.4.3', $violation->rule);
        $this->assertViolationCount($violations, 'contrast.insufficient', 2);
    }

    public function test_detects_light_gray_text(): void
    {
        $html = '<p style="color: #ccc;">Light gray text</p>';

        $violations = $this->analyze($html);

        $this->assertViolationCount($violations, 'contrast.light_gray_inline', 1);
        $this->assertHasViolation($violations, 'contrast.light_gray_inline', element: 'p', severity: Severity::Warning);
    }

    public function test_bare_placeholder_and_disabled_elements_are_not_flagged(): void
    {
        // Regression guard for v2.2.2: the placeholder/disabled heuristics were
        // removed because they flagged every form without checking any colour.
        $html = '<form><input type="text" placeholder="Enter text here"><button disabled>Send</button></form>';

        $this->assertSame([], $this->analyze($html));
    }

    public function test_high_contrast_passes(): void
    {
        $html = '
            <p style="color: #000; background-color: #fff;">High contrast black on white</p>
            <p style="color: #fff; background-color: #000;">High contrast white on black</p>
        ';

        $this->assertSame([], $this->analyze($html));
    }

    public function test_calculates_contrast_ratio_correctly(): void
    {
        $html = '<p style="color: #767676; background-color: #ffffff;">4.54:1 contrast ratio</p>';

        // 4.54:1 passes WCAG AA for normal text.
        $this->assertNoViolation($this->analyze($html), 'contrast.insufficient');
    }

    public function test_ratio_and_required_params_are_reported(): void
    {
        $html = '<p style="color: #ccc; background-color: #ffffff;">Light gray on white</p>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'contrast.insufficient', element: 'p', severity: Severity::Error);
        $this->assertSame('1.61', $violation->params['ratio']);
        $this->assertSame(4.5, $violation->params['required']);
        $this->assertSame('Light gray on white', $violation->params['content']);
        $this->assertSame(1.61, $violation->meta['ratio']);
    }

    public function test_large_text_uses_the_lower_requirement(): void
    {
        $html = '<h1 style="color: #949494; background-color: #ffffff;">Heading</h1>';

        $violations = $this->analyze($html);

        // #949494 on white is ~3.1:1 — enough for large text, not for body text.
        $this->assertNoViolation($violations, 'contrast.insufficient');
    }

    public function test_detects_low_contrast_from_css_classes(): void
    {
        $html = '<html><head><style>.muted { color: #999999; background-color: #aaaaaa; }</style></head>'
            .'<body><p class="muted" id="target">Hard to read text</p></body></html>';

        $this->assertHasViolation($this->analyze($html), 'contrast.insufficient', element: 'p#target.muted');
    }

    public function test_css_with_good_contrast_no_violation(): void
    {
        $html = '<html><head><style>p { color: #000000; background-color: #ffffff; }</style></head>'
            .'<body><p>Perfectly readable text</p></body></html>';

        $this->assertNoViolation($this->analyze($html), 'contrast.insufficient');
    }

    public function test_inherited_color_marked_approximate(): void
    {
        $html = '<html><head><style>.container { color: #cccccc; background-color: #dddddd; }</style></head>'
            .'<body><div class="container"><p id="target">Inherited poor contrast</p></div></body></html>';

        $violations = $this->analyze($html);

        $this->assertTrue($this->assertHasViolation($violations, 'contrast.insufficient')->meta['approximate']);
    }

    public function test_light_gray_text_xpath_only_matches_elements_with_style(): void
    {
        // v2.2.0 Fix 7: Previous XPath missed parens and matched arbitrarily because of
        // precedence of `or` over `and`. With the fix, only elements whose @style actually
        // contains #999/#aaa/#bbb/#ccc should be flagged — one per matched element.
        $html = '<html><body>
            <p style="color: #999;">gray text</p>
            <p>plain paragraph with no style</p>
            <div>another plain div</div>
        </body></html>';

        $this->assertViolationCount($this->analyze($html), 'contrast.light_gray_inline', 1);
    }

    public function test_inline_overrides_css_for_contrast(): void
    {
        // CSS sets good contrast, but inline style overrides with bad contrast
        $html = '<html><head><style>p { color: #000000; background-color: #ffffff; }</style></head>'
            .'<body><p style="color: #cccccc;">Overridden to low contrast</p></body></html>';

        // The inline color (#cccccc on white) has a ratio of about 1.6:1 — should be flagged.
        $this->assertHasViolation($this->analyze($html), 'contrast.insufficient', severity: Severity::Error);
    }
}
