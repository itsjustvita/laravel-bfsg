<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Analyzers\ContrastAnalyzer;
use ItsJustVita\LaravelBfsg\Contracts\Analyzer;
use ItsJustVita\LaravelBfsg\Dom\HtmlDocument;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\Support\AnalyzerTestCase;

class ContrastAnalyzerTest extends AnalyzerTestCase
{
    protected function analyzer(): Analyzer
    {
        return new ContrastAnalyzer;
    }

    private function page(string $css, string $body): string
    {
        return '<html><head><style>'.$css.'</style></head><body>'.$body.'</body></html>';
    }

    public function test_detects_low_contrast_text_per_element(): void
    {
        $violations = $this->analyze('<p style="color: #999; background-color: #fff;">Low contrast text</p><p style="color: #aaa; background-color: #fff;">Very low contrast</p>');

        $violation = $this->assertHasViolation($violations, 'contrast.insufficient', element: 'p', severity: Severity::Error);
        $this->assertSame('1.4.3', $violation->rule);
        $this->assertViolationCount($violations, 'contrast.insufficient', 2);
    }

    public function test_params_and_meta(): void
    {
        $violations = $this->analyze('<p style="color: #ccc; background-color: #ffffff;">Light gray on white</p>');

        $violation = $this->assertHasViolation($violations, 'contrast.insufficient', element: 'p');
        $this->assertSame(['ratio' => '1.61', 'required' => 4.5, 'foreground' => '#cccccc', 'background' => '#ffffff'], $violation->params);
        $this->assertSame(['approximate' => false, 'ratio' => 1.61, 'large' => false], $violation->meta);
        $this->assertSame([], $violation->tags);
    }

    public function test_threshold_is_compared_unrounded(): void
    {
        // #777 on white is 4.478:1 — rounds to 4.48 but still fails 4.5.
        $this->assertHasViolation($this->analyze('<p style="color: #777">Almost</p>'), 'contrast.insufficient');
        $this->assertSame([], $this->analyze('<p style="color: #767676">Passes at 4.54:1</p>'));
    }

    public function test_high_contrast_and_unstyled_text_pass(): void
    {
        $this->assertSame([], $this->analyze('<p style="color: #000; background-color: #fff;">Black</p><p style="color: #fff; background-color: #000;">White</p><p>Default</p>'));
    }

    public function test_only_own_text_is_measured(): void
    {
        $violations = $this->analyze($this->page('.muted { color: #aaa }', '<div class="muted"><p style="color:#000">Readable</p></div>'));

        $this->assertSame([], $violations);
    }

    public function test_every_element_with_own_text_is_measured(): void
    {
        $violations = $this->analyze($this->page('.muted { color: #aaa }', '<div class="muted" id="d">Div text</div><section class="muted">x <em>y</em></section><small class="muted">z</small>'));

        $this->assertHasViolation($violations, 'contrast.insufficient', element: 'div#d.muted');
        $this->assertHasViolation($violations, 'contrast.insufficient', element: 'section.muted');
        $this->assertHasViolation($violations, 'contrast.insufficient', element: 'em');
        $this->assertHasViolation($violations, 'contrast.insufficient', element: 'small.muted');
    }

    public function test_hidden_and_non_rendered_text_is_skipped(): void
    {
        $html = $this->page('.muted { color: #bbb }', '<p class="muted" hidden>a</p><div aria-hidden="true"><p class="muted">b</p></div><noscript><p class="muted">c</p></noscript><template><p class="muted">d</p></template>');

        $this->assertSame([], $this->analyze($html));
    }

    public function test_large_text_uses_the_lower_requirement(): void
    {
        // #949494 on white is ~3.03:1 — enough for large text only.
        $this->assertSame([], $this->analyze('<h1 style="color: #949494;">Heading</h1>'));
        $this->assertSame([], $this->analyze($this->page('.big { font-size: 24px; color: #949494 }', '<p class="big">Big text</p>')));
        $this->assertSame([], $this->analyze($this->page('.bold { font-size: 14pt; font-weight: bold; color: #949494 }', '<p class="bold">Bold text</p>')));
        $this->assertHasViolation($this->analyze('<h4 style="color: #949494;">Small heading</h4>'), 'contrast.insufficient');
    }

    public function test_css_classes_cascade_and_inheritance(): void
    {
        $this->assertHasViolation($this->analyze($this->page('.muted { color: #999999; background-color: #aaaaaa; }', '<p class="muted" id="target">Hard to read</p>')), 'contrast.insufficient', element: 'p#target.muted');
        $this->assertSame([], $this->analyze($this->page('p { color: #000000; background-color: #ffffff; }', '<p>Readable</p>')));
        $this->assertHasViolation($this->analyze($this->page('p { color: #000; }', '<p style="color: #cccccc;">Overridden</p>')), 'contrast.insufficient', severity: Severity::Error);
    }

    public function test_inherited_resolved_colours_are_exact_and_unresolvable_ones_approximate(): void
    {
        $exact = $this->assertHasViolation($this->analyze($this->page('.container { color: #cccccc; background-color: #dddddd; }', '<div class="container"><p id="target">Inherited</p></div>')), 'contrast.insufficient');
        $this->assertFalse($exact->meta['approximate']);

        $approximate = $this->assertHasViolation($this->analyze($this->page('.container { color: #cccccc; background-color: #dddddd; } p { color: var(--muted) }', '<div class="container"><p>Var</p></div>')), 'contrast.insufficient');
        $this->assertTrue($approximate->meta['approximate']);
        $this->assertSame(['approximate'], $approximate->tags);
    }

    public function test_aaa_level_reports_notices_with_the_aaa_tag(): void
    {
        $analyzer = new ContrastAnalyzer(level: 'AAA');
        $document = HtmlDocument::fromHtml('<p style="color:#767676">AA only</p><p style="color:#aaa">Fails AA</p>');

        $violations = $analyzer->analyze($document);

        $aaa = $this->assertHasViolation($violations, 'contrast.insufficient', severity: Severity::Notice);
        $this->assertSame('1.4.6', $aaa->rule);
        $this->assertSame(['aaa'], $aaa->tags);
        $this->assertSame(7.0, $aaa->params['required']);
        $this->assertSame('1.4.3', $this->assertHasViolation($violations, 'contrast.insufficient', severity: Severity::Error)->rule);
    }

    public function test_level_is_read_from_config(): void
    {
        config()->set('bfsg.compliance_level', 'AAA');

        $this->assertHasViolation($this->analyze('<p style="color:#767676">AA only</p>'), 'contrast.insufficient', severity: Severity::Notice);
    }

    public function test_placeholder_and_disabled_elements_are_not_flagged(): void
    {
        $this->assertSame([], $this->analyze('<form><input type="text" placeholder="Enter text here"><button disabled>Send</button></form>'));
    }

    public function test_analysis_stops_at_the_element_cap_with_one_notice(): void
    {
        $html = '<div>'.str_repeat('<p>x</p>', ContrastAnalyzer::MAX_MEASURED + 5).'</div>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'contrast.analysis_truncated', severity: Severity::Notice);
        $this->assertNull($violation->element);
        $this->assertSame('1.4.3', $violation->rule);
        $this->assertSame(['limit' => ContrastAnalyzer::MAX_MEASURED], $violation->params);
        $this->assertViolationCount($violations, 'contrast.analysis_truncated', 1);
    }

    public function test_text_hidden_by_the_stylesheet_is_skipped(): void
    {
        $this->assertSame([], $this->analyze($this->page('.sr-hidden { display: none } .ghost { color: #eee }', '<div class="sr-hidden"><p class="ghost">Hidden</p></div>')));
    }
}
