<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Analyzers\FocusAnalyzer;
use ItsJustVita\LaravelBfsg\Contracts\Analyzer;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\Support\AnalyzerTestCase;

class FocusAnalyzerTest extends AnalyzerTestCase
{
    protected function analyzer(): Analyzer
    {
        return new FocusAnalyzer;
    }

    private function styled(string $css, string $body = '<a href="/">Link</a>', string $media = ''): string
    {
        $mediaAttribute = $media === '' ? '' : ' media="'.$media.'"';

        return '<html><head><style'.$mediaAttribute.'>'.$css.'</style></head><body>'.$body.'</body></html>';
    }

    public function test_inline_outline_removal_on_focusable_elements(): void
    {
        $html = '<button id="b" style="outline: none">Save</button>'
            .'<a href="/" style="OUTLINE-WIDTH: 0">Link</a>'
            .'<div tabindex="0" style="outline-style:none">Widget</div>'
            .'<input style="outline: 0; box-shadow: 0 0 0 3px #005fcc" aria-label="Search">'
            .'<p style="outline: none">Not focusable</p>'
            .'<button disabled style="outline: none">Off</button>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'focus.outline_removed_inline', element: 'button#b', severity: Severity::Error);
        $this->assertSame('2.4.7', $violation->rule);
        $this->assertSame(['tag' => 'button'], $violation->params);
        $this->assertViolationCount($violations, 'focus.outline_removed_inline', 3);
    }

    public function test_global_reset_without_replacement(): void
    {
        $violations = $this->analyze($this->styled('*:focus { outline: none; } a { color: #00f }'));

        $violation = $this->assertHasViolation($violations, 'focus.outline_removed_global', element: 'style', severity: Severity::Error);
        $this->assertSame(['selector' => '*:focus'], $violation->params);
        $this->assertCount(1, $violations);
    }

    public function test_global_reset_with_global_focus_visible_restore_passes(): void
    {
        $this->assertSame([], $this->analyze($this->styled(':focus { outline: 0 } :focus-visible { outline: 2px solid #005fcc }')));
        $this->assertSame([], $this->analyze($this->styled(':focus:not(:focus-visible) { outline: none }')));
    }

    public function test_global_and_specific_resets_are_both_reported(): void
    {
        $violations = $this->analyze($this->styled(
            ':focus { outline: none } .btn:focus { outline: 0 } .nav a:focus-visible { outline-color: transparent }',
            '<button class="btn">Save</button><nav class="nav"><a href="/">Home</a></nav>',
        ));

        $this->assertHasViolation($violations, 'focus.outline_removed_global');
        $specific = $this->assertHasViolation($violations, 'focus.outline_removed', element: 'style');
        $this->assertSame(['selector' => '.btn:focus'], $specific->params);
        $this->assertCount(2, $violations);
    }

    public function test_specific_reset_with_companion_or_same_block_alternative_passes(): void
    {
        $css = '.btn:focus { outline: none } .btn:focus-visible { box-shadow: 0 0 0 3px #005fcc } '
            .'.card:focus { outline: 0; border-color: #005fcc } '
            .'.menu a:focus { outline: none } .menu a:focus-within { background: #ffd }';

        $body = '<button class="btn">Save</button><div class="card" tabindex="0">Card</div><nav class="menu"><a href="/">Home</a></nav>';

        $this->assertSame([], $this->analyze($this->styled($css, $body)));
    }

    public function test_non_painting_alternatives_do_not_count(): void
    {
        $css = '.a:focus { outline: none; border: 0 } .b:focus { outline: none; box-shadow: none } .c:focus { outline: none; border-radius: 4px; background: transparent }';

        $violation = $this->assertHasViolation($this->analyze($this->styled($css, '<a class="a" href="/">A</a><a class="b" href="/">B</a><a class="c" href="/">C</a>')), 'focus.outline_removed');

        $this->assertSame(['selector' => '.a:focus'], $violation->params);
    }

    public function test_utility_classes_on_the_same_element_count_as_companions(): void
    {
        $css = '.focus\\:outline-none:focus { outline: 2px solid transparent; outline-offset: 2px } '
            .'.focus\\:ring-2:focus { box-shadow: var(--tw-ring-inset) 0 0 0 2px var(--tw-ring-color) }';

        $this->assertSame([], $this->analyze($this->styled($css, '<button class="focus:outline-none focus:ring-2">Buy</button>')));

        $violation = $this->assertHasViolation($this->analyze($this->styled($css, '<button class="focus:outline-none">Buy</button>')), 'focus.outline_removed');
        $this->assertSame(['selector' => '.focus\\:outline-none:focus'], $violation->params);
    }

    public function test_resets_for_selectors_that_match_nothing_are_ignored(): void
    {
        $this->assertSame([], $this->analyze($this->styled('.unused:focus { outline: none }')));
    }

    public function test_comments_print_media_and_non_screen_stylesheets_are_ignored(): void
    {
        $this->assertSame([], $this->analyze($this->styled('/* a:focus { outline: none } */ @media print { a:focus { outline: none } }')));
        $this->assertSame([], $this->analyze($this->styled('a:focus { outline: none; }', media: 'print')));
    }

    public function test_media_screen_and_layer_rules_are_checked(): void
    {
        $violations = $this->analyze($this->styled('@layer base { @media screen and (min-width: 40em) { a:focus { outline: none } } }'));

        $this->assertHasViolation($violations, 'focus.outline_removed');
    }

    public function test_nested_focus_rule_is_seen(): void
    {
        $violations = $this->analyze($this->styled('.x{&:focus{outline:none}}', '<a class="x" href="#">l</a>'));

        $this->assertHasViolation($violations, 'focus.outline_removed');
    }

    public function test_no_issues_without_focus_problems(): void
    {
        $this->assertSame([], $this->analyze($this->styled('a:focus { outline: 2px solid #005fcc } a { color: #00f }')));
    }

    public function test_outline_removed_on_a_non_focusable_element_is_not_reported(): void
    {
        $this->assertNoViolation($this->analyze($this->styled('.panel:focus{outline:none}', '<div class="panel"><p>Text</p></div>')), 'focus.outline_removed');
    }

    public function test_outline_removed_on_a_tabindex_minus_one_target_is_a_notice(): void
    {
        $result = $this->analyze($this->styled('#main:focus{outline:none}', '<a href="#main">Skip</a><main id="main" tabindex="-1"><h1>Welcome</h1></main>'));

        $this->assertViolationCount($result, 'focus.outline_removed', 1);
        $this->assertHasViolation($result, 'focus.outline_removed', severity: Severity::Notice);
    }

    public function test_outline_removed_on_disabled_hidden_or_template_matches_is_not_reported(): void
    {
        foreach ([
            '<button class="x" disabled>Go</button>',
            '<input class="x" type="hidden" name="a">',
            '<div hidden><a class="x" href="/">Go</a></div>',
            '<template><a class="x" href="/">Go</a></template>',
        ] as $body) {
            $this->assertNoViolation($this->analyze($this->styled('.x:focus{outline:none}', $body)), 'focus.outline_removed');
        }
    }

    public function test_outline_removed_on_a_link_is_still_an_error(): void
    {
        $result = $this->analyze($this->styled('.cta:focus{outline:none}', '<a class="cta" href="/x">Go</a>'));

        $this->assertViolationCount($result, 'focus.outline_removed', 1);
        $this->assertHasViolation($result, 'focus.outline_removed', severity: Severity::Error);
    }

    public function test_a_selector_matching_focusable_and_script_only_elements_stays_an_error(): void
    {
        $result = $this->analyze($this->styled('.x:focus{outline:none}', '<a class="x" href="/">Go</a><div class="x" tabindex="-1">y</div>'));

        $this->assertHasViolation($result, 'focus.outline_removed', severity: Severity::Error);
    }

    public function test_tailwind_v3_focus_visible_outline_utilities_count_as_indicator(): void
    {
        $css = '.focus\\:outline-none:focus{outline:2px solid transparent;outline-offset:2px}.focus-visible\\:outline:focus-visible{outline-style:solid}.focus-visible\\:outline-2:focus-visible{outline-width:2px}.focus-visible\\:outline-indigo-600:focus-visible{outline-color:#4f46e5}';
        $body = '<button type="button" class="focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">Save</button>';

        $this->assertNoViolation($this->analyze($this->styled($css, $body)), 'focus.outline_removed');
    }

    public function test_outline_longhands_in_focus_visible_count_as_indicator(): void
    {
        $css = 'a:focus{outline:none}a:focus-visible{outline-width:3px;outline-style:solid;outline-color:#005fcc}';

        $this->assertNoViolation($this->analyze($this->styled($css, '<a href="/x">Pricing page</a>')), 'focus.outline_removed');
    }

    public function test_tailwind_v4_outline_style_var_counts_as_indicator(): void
    {
        $css = '.focus\\:outline-none:focus{--tw-outline-style:none;outline-style:none}.focus-visible\\:outline-2:focus-visible{outline-style:var(--tw-outline-style);outline-width:2px}.focus-visible\\:outline-blue-600:focus-visible{outline-color:#2563eb}';
        $body = '<button class="focus:outline-none focus-visible:outline-2 focus-visible:outline-blue-600">Save</button>';

        $this->assertNoViolation($this->analyze($this->styled($css, $body)), 'focus.outline_removed');
    }

    public function test_transparent_outline_color_alone_is_not_an_indicator(): void
    {
        $css = 'a:focus{outline-style:none}a:focus-visible{outline-color:#005fcc}';
        $result = $this->analyze($this->styled($css, '<a href="/x">Pricing page</a>'));

        $this->assertViolationCount($result, 'focus.outline_removed', 1);
    }

    public function test_transparent_outline_color_combined_is_not_an_indicator(): void
    {
        $css = 'a:focus{outline:none}a:focus-visible{outline-style:solid;outline-width:2px;outline-color:transparent}';

        $this->assertViolationCount($this->analyze($this->styled($css, '<a href="/x">P</a>')), 'focus.outline_removed', 1);
    }
}
