<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use DOMElement;
use ItsJustVita\LaravelBfsg\Analyzers\ContrastAnalyzer;
use ItsJustVita\LaravelBfsg\Contracts\Analyzer;
use ItsJustVita\LaravelBfsg\Css\CssParser;
use ItsJustVita\LaravelBfsg\Dom\HtmlDocument;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\Support\AnalyzerTestCase;
use ItsJustVita\LaravelBfsg\Violation;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

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
        $this->assertSame(['approximate' => false, 'ratio' => 1.61, 'large' => false, 'occurrences' => 1, 'selectors' => []], $violation->meta);
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

        // One colour signature: one finding on the first element, the others in meta.selectors (spec D3).
        $violation = $this->assertHasViolation($violations, 'contrast.insufficient', element: 'div#d.muted');
        $this->assertViolationCount($violations, 'contrast.insufficient', 1);
        $this->assertSame(4, $violation->meta['occurrences']);
        $this->assertSame(['/html[1]/body[1]/section[1]', '/html[1]/body[1]/section[1]/em[1]', '/html[1]/body[1]/small[1]'], $violation->meta['selectors']);
    }

    public function test_non_rendered_text_is_skipped(): void
    {
        $html = $this->page('.muted { color: #bbb }', '<p class="muted" hidden>a</p><noscript><p class="muted">c</p></noscript><template><p class="muted">d</p></template>');

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

    // #8a8a8a on white is about 3.45:1: enough for large text only.
    public function test_large_text_via_custom_property(): void
    {
        $this->assertNoViolation($this->analyze($this->page(':root{--text-3xl:1.875rem} .t{font-size:var(--text-3xl);color:#8a8a8a}', '<p class="t">Large</p>')), 'contrast.insufficient');
    }

    public function test_bold_via_custom_property(): void
    {
        $this->assertNoViolation($this->analyze($this->page(':root{--font-weight-bold:700} .t{font-size:20px;font-weight:var(--font-weight-bold);color:#8a8a8a}', '<p class="t">Bold</p>')), 'contrast.insufficient');
    }

    public function test_clamp_lower_bound_counts_as_large(): void
    {
        $this->assertNoViolation($this->analyze($this->page('.t{font-size:clamp(2rem, 5vw, 3rem);color:#8a8a8a}', '<p class="t">Fluid</p>')), 'contrast.insufficient');
    }

    public function test_font_shorthand(): void
    {
        $this->assertNoViolation($this->analyze($this->page('.t{font:700 20px/1.2 sans-serif;color:#8a8a8a}', '<p class="t">Shorthand</p>')), 'contrast.insufficient');
    }

    public function test_inherit_font_weight_walks_up(): void
    {
        $violations = $this->analyze($this->page('h3{font-weight:inherit;font-size:20px;color:#8a8a8a}', '<div><h3>T</h3></div>'));

        $violation = $this->assertHasViolation($violations, 'contrast.insufficient', element: 'h3', severity: Severity::Error);
        $this->assertSame(4.5, $violation->params['required']);
    }

    public function test_strong_is_bolder_of_a_light_parent(): void
    {
        $violations = $this->analyze($this->page('body{font-weight:300} strong{font-size:20px;color:#8a8a8a}', '<strong>T</strong>'));

        $violation = $this->assertHasViolation($violations, 'contrast.insufficient', element: 'strong', severity: Severity::Error);
        $this->assertSame(4.5, $violation->params['required']);
    }

    public function test_form_controls_do_not_inherit_a_large_size(): void
    {
        $violation = $this->assertHasViolation($this->analyze($this->page('body{font-size:24px} button{color:#8a8a8a}', '<button>Go</button>')), 'contrast.insufficient', element: 'button', severity: Severity::Error);
        $this->assertSame(4.5, $violation->params['required']);
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

    public function test_definite_failures_are_errors_and_approximate_ones_warnings(): void
    {
        $definite = $this->assertHasViolation($this->analyze($this->page('.muted { color: #cccccc }', '<p class="muted">Definite</p>')), 'contrast.insufficient');
        $this->assertSame(Severity::Error, $definite->severity);

        $approximate = $this->assertHasViolation($this->analyze($this->page('.box { color: #cccccc; background-color: #dddddd } .box p { color: var(--muted) }', '<div class="box"><p>Approximate</p></div>')), 'contrast.insufficient');
        $this->assertTrue($approximate->meta['approximate']);
        $this->assertSame(Severity::Warning, $approximate->severity);
        $this->assertSame('1.4.3', $approximate->rule);
        $this->assertSame(['ratio' => '1.18', 'required' => 4.5, 'foreground' => '#cccccc', 'background' => '#dddddd'], $approximate->params);
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
        $html = '<div>'.implode('', array_map(fn (int $i) => '<p class="p'.$i.'">x</p>', range(1, ContrastAnalyzer::MAX_MEASURED + 5))).'</div>';

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

    public function test_tailwind_v3_text_opacity_variable(): void
    {
        $violations = $this->analyze($this->page('.text-gray-300{--tw-text-opacity:1;color:rgb(209 213 219 / var(--tw-text-opacity))}', '<p class="text-gray-300">Hi</p>'));

        // #d1d5db on white is 1.4735:1 (the audit rounded it to 1.48), the same as a plain color: #d1d5db.
        $violation = $this->assertHasViolation($violations, 'contrast.insufficient', severity: Severity::Error);
        $this->assertSame('1.47', $violation->params['ratio']);
        $this->assertSame('#d1d5db', $violation->params['foreground']);
    }

    public function test_tailwind_v3_responsive_text_colour(): void
    {
        $css = '@media (min-width:768px){.md\\:text-gray-300{--tw-text-opacity:1;color:rgb(209 213 219 / var(--tw-text-opacity))}}';

        $violation = $this->assertHasViolation($this->analyze($this->page($css, '<p class="md:text-gray-300">Hi</p>')), 'contrast.insufficient', severity: Severity::Error);
        $this->assertSame('1.47', $violation->params['ratio'], 'the same result as a plain color: #d1d5db in that block');
    }

    /** @return array<string, array{0: string}> Tailwind v3 variant rules that no element on the page uses */
    public static function unusedTailwindVariants(): array
    {
        return [
            'disabled:' => ['.disabled\\:text-gray-400:disabled{--tw-text-opacity:1;color:rgb(156 163 175 / var(--tw-text-opacity))}'],
            'even:' => ['.bg-white{--tw-bg-opacity:1;background-color:rgb(255 255 255 / var(--tw-bg-opacity))} .even\\:bg-gray-50:nth-child(even){--tw-bg-opacity:1;background-color:rgb(249 250 251 / var(--tw-bg-opacity))}'],
            'peer-checked:' => ['.peer:checked ~ .peer-checked\\:text-blue-600{--tw-text-opacity:1;color:rgb(37 99 235 / var(--tw-text-opacity))}'],
            'dark: (class strategy)' => [':is(.dark .dark\\:text-white){--tw-text-opacity:1;color:rgb(255 255 255 / var(--tw-text-opacity))}'],
            'dark: (nested where)' => [':is(:where(.dark) .dark\\:text-white){--tw-text-opacity:1;color:rgb(255 255 255 / var(--tw-text-opacity))}'],
            'peer-hover:' => ['.peer:hover ~ .peer-hover\\:text-blue-600{--tw-text-opacity:1;color:rgb(37 99 235 / var(--tw-text-opacity))}'],
        ];
    }

    #[DataProvider('unusedTailwindVariants')]
    public function test_unused_tailwind_variants_keep_errors_definite(string $variant): void
    {
        $css = '.text-gray-300{--tw-text-opacity:1;color:rgb(209 213 219 / var(--tw-text-opacity))}'.$variant;

        $violation = $this->assertHasViolation($this->analyze($this->page($css, '<div class="bg-white"><p class="text-gray-300">Hi</p></div>')), 'contrast.insufficient', severity: Severity::Error);
        $this->assertSame('1.47', $violation->params['ratio']);
    }

    public function test_bootstrap_button_variables(): void
    {
        $violations = $this->analyze($this->page('.btn{color:var(--bs-btn-color);background-color:var(--bs-btn-bg)} .btn-warning{--bs-btn-color:#fff;--bs-btn-bg:#ffc107}', '<button class="btn btn-warning">Go</button>'));

        $violation = $this->assertHasViolation($violations, 'contrast.insufficient', severity: Severity::Error);
        $this->assertSame('1.63', $violation->params['ratio']);
        $this->assertSame(['#ffffff', '#ffc107'], [$violation->params['foreground'], $violation->params['background']]);
    }

    public function test_filament_inline_custom_properties(): void
    {
        $violations = $this->analyze($this->page(
            '.text-custom-400{color:rgba(var(--c-400),1)} .bg-custom-50{background-color:rgba(var(--c-50),1)}',
            '<span class="text-custom-400 bg-custom-50" style="--c-50:255,251,235;--c-400:251,191,36">Badge</span>',
        ));

        $violation = $this->assertHasViolation($violations, 'contrast.insufficient', severity: Severity::Error);
        $this->assertLessThan(2.0, (float) $violation->params['ratio']);
        $this->assertSame(['#fbbf24', '#fffbeb'], [$violation->params['foreground'], $violation->params['background']]);
    }

    public function test_dark_mode_rules_are_not_the_base_theme(): void
    {
        $css = 'body{background:#fff;color:#111} .muted{color:#666} @media (prefers-color-scheme: dark){body{background:#111;color:#eee}}';

        $this->assertNoViolation($this->analyze($this->page($css, '<p class="muted">x</p>')), 'contrast.insufficient');
    }

    public function test_style_element_with_preference_media_is_ignored(): void
    {
        $html = '<html><head><style media="(prefers-color-scheme: dark)">p{color:#eee}</style></head><body><p>x</p></body></html>';

        $this->assertNoViolation($this->analyze($html), 'contrast.insufficient');
    }

    public function test_viewport_conditional_display_none_does_not_hide(): void
    {
        $css = '@media (min-width:768px){.md\\:hidden{display:none}} .low{color:#ccc}';

        $this->assertHasViolation($this->analyze($this->page($css, '<p class="md:hidden low">x</p>')), 'contrast.insufficient', severity: Severity::Error);
    }

    public function test_inherited_link_and_button_colours_are_definite(): void
    {
        $css = '.text-gray-300{--tw-text-opacity:1;color:rgb(209 213 219 / var(--tw-text-opacity))} a,button{color:inherit}';
        $violations = array_filter(
            $this->analyze($this->page($css, '<p class="text-gray-300">Text <a href="#">link</a> <button>button</button></p>')),
            fn ($violation) => $violation->key === 'contrast.insufficient',
        );

        // One definite colour signature for all three (spec D3): the link and the button are further occurrences.
        $this->assertCount(1, $violations);
        $violation = $this->assertHasViolation($violations, 'contrast.insufficient', element: 'p.text-gray-300', severity: Severity::Error);
        $this->assertFalse($violation->meta['approximate']);
        $this->assertSame('#d1d5db', $violation->params['foreground']);
        $this->assertSame(3, $violation->meta['occurrences']);
        $this->assertSame(['/html[1]/body[1]/p[1]/a[1]', '/html[1]/body[1]/p[1]/button[1]'], $violation->meta['selectors']);
    }

    public function test_light_theme_media_is_the_base_theme(): void
    {
        $css = 'body{color:#111} @media (prefers-color-scheme: light){.muted{color:#ccc}} @media (prefers-color-scheme: dark){.muted{color:#111}}';

        $violation = $this->assertHasViolation($this->analyze($this->page($css, '<p class="muted">x</p>')), 'contrast.insufficient', severity: Severity::Error);
        $this->assertSame('#cccccc', $violation->params['foreground']);
    }

    public function test_custom_properties_inherit_from_ancestors(): void
    {
        $violations = $this->analyze($this->page('.card{--muted:#bbb} .meta{color:var(--muted)}', '<div class="card"><section><p class="meta">Inherited</p></section></div>'));

        $violation = $this->assertHasViolation($violations, 'contrast.insufficient', element: 'p.meta', severity: Severity::Error);
        $this->assertSame('#bbbbbb', $violation->params['foreground']);
        $this->assertFalse($violation->meta['approximate']);
    }

    /** @return array<string, array{0: string, 1: string}> AN1: text over a hero image (CSS with the text colour as %s, body) */
    public static function heroImages(): array
    {
        return [
            'background-image' => ['.hero{background-image:url(/hero.jpg)} .t{color:%s}', '<div class="hero"><h1 class="t">Welcome</h1></div>'],
            'background shorthand' => ['.hero{background:url(/hero.jpg) center/cover} .t{color:%s}', '<div class="hero"><h1 class="t">Welcome</h1></div>'],
            'covering img' => [
                '.relative{position:relative} .absolute{position:absolute;inset:0} .t{color:%s}',
                '<div class="relative"><img class="absolute" src="/hero.jpg" alt=""><div class="relative"><p class="t">Welcome</p></div></div>',
            ],
        ];
    }

    #[DataProvider('heroImages')]
    public function test_white_text_on_hero_image_is_warning(string $css, string $body): void
    {
        $violations = $this->analyze($this->page(sprintf($css, '#fff'), $body));

        $violation = $this->assertHasViolation($violations, 'contrast.insufficient', severity: Severity::Warning);
        $this->assertContains('approximate', $violation->tags);
        $this->assertTrue($violation->meta['approximate']);
        $this->assertSame([], array_filter($violations, fn ($violation) => $violation->severity === Severity::Error));
    }

    #[DataProvider('heroImages')]
    public function test_black_text_on_hero_image_is_unverifiable(string $css, string $body): void
    {
        $violations = $this->analyze($this->page(sprintf($css, '#000'), $body));

        $violation = $this->assertHasViolation($violations, 'contrast.unverifiable', severity: Severity::Notice);
        $this->assertSame('1.4.3', $violation->rule);
        $this->assertSame(['approximate'], $violation->tags);
        $this->assertSame(['foreground' => '#000000', 'background' => '#ffffff'], $violation->params);
        $this->assertSame(['approximate', 'ratio', 'large', 'occurrences', 'selectors'], array_keys($violation->meta));
        $this->assertTrue($violation->meta['approximate']);
        $this->assertSame(21.0, $violation->meta['ratio']);
        $this->assertNoViolation($violations, 'contrast.insufficient');
    }

    /** @return array<string, array{0: string}> AN2: forms of a Tailwind v4 rule (sprintf: selector, declaration) */
    public static function tailwindV4Forms(): array
    {
        return [
            'plain' => ['%1$s{%2$s}'],
            'top-level @supports' => ['@supports (color: color-mix(in lab, red, red)){%1$s{%2$s}}'],
            'nested @supports' => ['%1$s{@supports (color: color-mix(in lab, red, red)){%2$s}}'],
        ];
    }

    #[DataProvider('tailwindV4Forms')]
    public function test_tailwind_v4_opacity_badge_passes(string $form): void
    {
        $css = sprintf($form, '.bg-gray-900\/5', 'background-color:color-mix(in oklab, #101828 5%, transparent)').' .text-gray-900{color:#101828}';

        $this->assertNoViolation($this->analyze($this->page($css, '<span class="bg-gray-900/5 text-gray-900">B</span>')), 'contrast.insufficient');
    }

    #[DataProvider('tailwindV4Forms')]
    public function test_tailwind_v4_translucent_black_is_flagged(string $form): void
    {
        $css = sprintf($form, '.bg-black\/50', 'background-color:color-mix(in oklab, #000 50%, transparent)').' .text-white{color:#fff}';

        $violation = $this->assertHasViolation($this->analyze($this->page($css, '<span class="bg-black/50 text-white">B</span>')), 'contrast.insufficient', severity: Severity::Error);
        $this->assertGreaterThanOrEqual(3.9, (float) $violation->params['ratio']);
        $this->assertLessThanOrEqual(4.0, (float) $violation->params['ratio']);
    }

    // D2: an unknown size matters only between the thresholds (#aaa is 2.32:1, #8a8a8a 3.45:1 on white).
    public function test_unknown_size_below_large_threshold_is_error(): void
    {
        $violation = $this->assertHasViolation($this->analyze($this->page('.t{font-size:5vw;color:#aaa}', '<p class="t">x</p>')), 'contrast.insufficient', severity: Severity::Error);

        $this->assertSame([], $violation->tags);
        $this->assertFalse($violation->meta['approximate']);
        $this->assertNull($violation->meta['large']);
    }

    public function test_unknown_size_between_thresholds_is_warning(): void
    {
        $violation = $this->assertHasViolation($this->analyze($this->page('.t{font-size:5vw;color:#8a8a8a}', '<p class="t">x</p>')), 'contrast.insufficient', severity: Severity::Warning);

        $this->assertSame(['approximate'], $violation->tags);
        $this->assertSame(4.5, $violation->params['required']);
        $this->assertTrue($violation->meta['approximate']);
        $this->assertNull($violation->meta['large']);
    }

    // R25: 20px is large only when bold, and revert-layer leaves the weight unknown.
    public function test_unknown_weight_at_a_bold_large_size_is_warning(): void
    {
        $violation = $this->assertHasViolation($this->analyze($this->page('.t{font-size:20px;font-weight:revert-layer;color:#8a8a8a}', '<p class="t">x</p>')), 'contrast.insufficient', severity: Severity::Warning);

        $this->assertSame(['approximate'], $violation->tags);
    }

    public function test_inactive_components_are_exempt(): void
    {
        $css = '.btn{color:#bbb;background:#fff}';

        foreach ([
            '<button disabled class="btn">x</button>',
            '<fieldset disabled><label for="a" class="btn">L</label><input id="a"></fieldset>',
            '<div aria-disabled="true"><span class="btn">x</span></div>',
            '<label for="b" class="btn">L</label><input id="b" disabled>',
            '<label class="btn">L <input disabled></label>',
            '<label for="e" class="btn">L</label><input id="e" aria-disabled="true">',
        ] as $body) {
            $this->assertNoViolation($this->analyze($this->page($css, $body)), 'contrast.insufficient');
        }

        foreach (['<button class="btn">x</button>', '<label for="c" class="btn">L</label><input id="c">'] as $body) {
            $this->assertHasViolation($this->analyze($this->page($css, $body)), 'contrast.insufficient', severity: Severity::Error);
        }
    }

    public function test_visible_aria_hidden_text_is_measured(): void
    {
        $this->assertHasViolation($this->analyze('<p aria-hidden="true" style="color:#eee">x</p>'), 'contrast.insufficient', severity: Severity::Error);
    }

    public function test_zero_opacity_text_is_skipped(): void
    {
        $this->assertSame([], $this->analyze('<p style="opacity:0;color:#eee">x</p>'));
        $this->assertSame([], $this->analyze('<div style="opacity:0"><p style="color:#eee">x</p></div>'));
    }

    public function test_truncated_index_passing_estimate_is_silent(): void
    {
        $css = '';

        for ($i = 0; $i <= CssParser::MAX_INDEXED_RULES; $i++) {
            $css .= ".u{$i} { color: #111 } .n{$i} { margin: 0 } ";
        }

        $this->assertSame([], $this->analyze($this->page($css, '<p class="u1">x</p>')));

        // A failing estimate stays a warning.
        $violation = $this->assertHasViolation($this->analyze($this->page($css, '<p class="u1" style="color:#ccc">x</p>')), 'contrast.insufficient', severity: Severity::Warning);
        $this->assertSame(['approximate'], $violation->tags);
    }

    // R36: an unknown colour that passes AA is only unverifiable, at AAA too (#767676 is 4.54:1, an AAA failure).
    public function test_aaa_level_reports_only_unverifiable_for_an_unknown_colour(): void
    {
        $document = HtmlDocument::fromHtml($this->page('.hero{background-image:url(/hero.jpg)}', '<div class="hero"><p style="color:#767676">x</p></div>'));

        $violations = (new ContrastAnalyzer(level: 'AAA'))->analyze($document);

        $this->assertHasViolation($violations, 'contrast.unverifiable', severity: Severity::Notice);
        $this->assertNoViolation($violations, 'contrast.insufficient');
    }

    // R37: a zero from a breakpoint rule, directly or through a custom property, does not hide (#eee is 1.16:1).
    public function test_viewport_conditional_zero_opacity_text_is_measured(): void
    {
        foreach ([
            ['@media (min-width:768px){.md\\:opacity-0{opacity:0}}', '<p class="md:opacity-0" style="color:#eee">x</p>'],
            [':root{--o:1} @media (min-width:768px){:root{--o:0}} .x{opacity:var(--o)}', '<p class="x" style="color:#eee">x</p>'],
        ] as [$css, $body]) {
            $violation = $this->assertHasViolation($this->analyze($this->page($css, $body)), 'contrast.insufficient', severity: Severity::Error);
            $this->assertSame('1.16', $violation->params['ratio'], $css);
        }
    }

    /** Tailwind UI's sr-only (v4) with the gray of its icon buttons (contrast report C2, repro A). */
    private const TAILWIND_SR_ONLY = '.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;
  clip-path:inset(50%);white-space:nowrap;border-width:0}
.text-gray-400{color:#99a1af}';

    private const ICON_BUTTON = '<button type="button" class="text-gray-400"><span class="sr-only">Close menu</span>
  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 18 18 6"/></svg></button>';

    // C2: text clipped to nothing is not visible to anyone (1.4.3 Incidental); the icon falls under 1.4.11.
    public function test_tailwind_sr_only_text_is_not_measured(): void
    {
        $this->assertSame([], $this->analyze($this->page(self::TAILWIND_SR_ONLY, self::ICON_BUTTON)));
    }

    // C2, repro B: the spinner from the Bootstrap 5.3 docs; every declaration is !important.
    public function test_bootstrap_visually_hidden_text_is_not_measured(): void
    {
        $css = '.visually-hidden,.visually-hidden-focusable:not(:focus):not(:focus-within){width:1px!important;
  height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;
  clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}
.visually-hidden:not(caption),.visually-hidden-focusable:not(:focus):not(:focus-within):not(caption){position:absolute!important}
.text-warning{color:#ffc107!important}';
        $body = '<div class="spinner-border text-warning" role="status"><span class="visually-hidden">Loading...</span></div>';

        $this->assertSame([], $this->analyze($this->page($css, $body)));
    }

    // C2: Tailwind v3 clips with the legacy clip property.
    public function test_tailwind_v3_sr_only_with_clip_rect_is_not_measured(): void
    {
        $css = '.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border-width:0}'
            .' .text-gray-400{color:#99a1af}';

        $this->assertSame([], $this->analyze($this->page($css, self::ICON_BUTTON)));
    }

    // C2: like opacity 0 (R37), a clip from a breakpoint rule holds only at some viewports, so the text is measured.
    public function test_sr_only_inside_a_media_query_only_is_still_measured(): void
    {
        $css = '@media (min-width:640px){.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip-path:inset(50%);white-space:nowrap;border-width:0}}'
            .' .text-gray-400{color:#99a1af}';

        $violation = $this->assertHasViolation($this->analyze($this->page($css, self::ICON_BUTTON)), 'contrast.insufficient', element: 'span.sr-only', severity: Severity::Error);
        $this->assertSame('2.60', $violation->params['ratio']);
    }

    // C2: a focus variant does not apply at rest, a not-sr-only class on the element itself does.
    public function test_not_sr_only_override_is_measured(): void
    {
        $css = self::TAILWIND_SR_ONLY.' .focus\:not-sr-only:focus{position:static;width:auto;height:auto;clip:auto;overflow:visible}'
            .' .not-sr-only{position:static;width:auto;height:auto;clip:auto;overflow:visible}';
        $button = fn (string $classes): string => '<button type="button" class="text-gray-400"><span class="'.$classes.'">Close menu</span></button>';

        $this->assertSame([], $this->analyze($this->page($css, $button('sr-only focus:not-sr-only'))));

        $violation = $this->assertHasViolation($this->analyze($this->page($css, $button('sr-only not-sr-only'))), 'contrast.insufficient', severity: Severity::Error);
        $this->assertSame('2.60', $violation->params['ratio']);
    }

    // C2, R7: a skip link is clipped only until it takes focus, so it is measured as in 3.1.0.
    public function test_skip_link_is_still_measured(): void
    {
        $css = self::TAILWIND_SR_ONLY.' .focus\\:not-sr-only:focus{position:static;width:auto;height:auto;clip:auto;overflow:visible}';
        $body = '<a href="#main" class="sr-only focus:not-sr-only text-gray-400">Skip to content</a><main id="main">'.self::ICON_BUTTON.'</main>';

        $violations = $this->analyze($this->page($css, $body));
        $violation = $this->assertHasViolation($violations, 'contrast.insufficient', element: 'a.sr-only.focus:not-sr-only', severity: Severity::Error);
        $this->assertSame('2.60', $violation->params['ratio']);
        $this->assertSame(1, $violation->meta['occurrences'], 'the label in the button stays exempt');
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> a visually hidden element without and with focus, and the element label of the focusable one */
    public static function focusableTwins(): array
    {
        return [
            'link without and with href' => ['<a class="sr-only text-gray-400">Skip to content</a>', '<a href="#main" class="sr-only text-gray-400">Skip to content</a>', 'a.sr-only.text-gray-400'],
            'span without and with tabindex' => ['<span class="sr-only text-gray-400">Skip</span>', '<span tabindex="0" class="sr-only text-gray-400">Skip</span>', 'span.sr-only.text-gray-400'],
        ];
    }

    // C2, R7: the exemption memo of a style chain does not carry over to a focusable twin that no selector tells apart.
    #[DataProvider('focusableTwins')]
    public function test_focusable_twin_of_exempt_text_is_measured(string $exempt, string $focusable, string $element): void
    {
        $body = '<ul><li></li><li>'.$exempt.'</li><li>'.$focusable.'</li><li></li></ul>';

        $violation = $this->assertHasViolation($this->analyze($this->page(self::TAILWIND_SR_ONLY, $body)), 'contrast.insufficient', element: $element, severity: Severity::Error);
        $this->assertSame('2.60', $violation->params['ratio']);
        $this->assertSame(1, $violation->meta['occurrences'], 'the twin without focus stays exempt');
        $this->assertSame('/html[1]/body[1]/ul[1]/li[3]/'.explode('.', $element)[0].'[1]', $violation->selector);
    }

    // C2: without absolute or fixed positioning a 1px box is not taken as clipped to nothing.
    public function test_one_px_box_without_absolute_position_is_measured(): void
    {
        $css = '.tiny{width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);clip-path:inset(50%)} .text-gray-400{color:#99a1af}';

        $violation = $this->assertHasViolation($this->analyze($this->page($css, '<p class="tiny text-gray-400">x</p>')), 'contrast.insufficient', severity: Severity::Error);
        $this->assertSame('2.60', $violation->params['ratio']);
    }

    // C4, repro A: pure Tailwind v4 layering; utilities come after components, so .text-white wins whatever the specificity (17.75:1).
    public function test_utilities_layer_beats_components_layer_regardless_of_specificity(): void
    {
        $css = '@layer theme,base,components,utilities;
@layer components{.card .card-title{color:#101828}}
@layer utilities{.bg-gray-900{background-color:#101828}.text-white{color:#fff}}';

        $this->assertSame([], $this->analyze($this->page($css, '<div class="card bg-gray-900"><h3 class="card-title text-white">Title</h3></div>')));
    }

    // C4: unlayered rules beat every layer for normal declarations (17.74:1).
    public function test_unlayered_rule_beats_layered_rule(): void
    {
        $this->assertSame([], $this->analyze($this->page('@layer utilities{.text-gray-300{color:#d1d5db}} p{color:#111827}', '<p class="text-gray-300">Text</p>')));
    }

    // C4, the silent pass: the unlayered h2 rule wins, #d1d5db on white fails even for large text.
    public function test_unlayered_rule_beats_layered_rule_with_a_real_error(): void
    {
        $violations = $this->analyze($this->page('@layer utilities{.text-gray-900{color:#111827}} h2{color:#d1d5db}', '<h2 class="text-gray-900">Heading</h2>'));

        $violation = $this->assertHasViolation($violations, 'contrast.insufficient', element: 'h2.text-gray-900', severity: Severity::Error);
        $this->assertSame('1.47', $violation->params['ratio']);
        $this->assertSame('#d1d5db', $violation->params['foreground']);
    }

    // C4: the ordering statement puts base before utilities, although the utilities block comes first.
    public function test_layer_order_statement_decides_order(): void
    {
        $css = '@layer base, utilities; @layer utilities{.muted{color:#111}} @layer base{p.lead.intro{color:#ddd}}';

        $this->assertSame([], $this->analyze($this->page($css, '<p class="muted lead intro">Text</p>')));
    }

    // C4: layer order is compared before specificity.
    public function test_later_layer_beats_id_specificity_in_earlier_layer(): void
    {
        $this->assertSame([], $this->analyze($this->page('@layer a{p#t{color:#ddd}} @layer b{p{color:#111}}', '<p id="t">Text</p>')));
    }

    // C4: for !important the layer order reverses, the earlier layer wins.
    public function test_important_reverses_layer_order(): void
    {
        $this->assertSame([], $this->analyze($this->page('@layer a{p{color:#111!important}} @layer b{p{color:#ddd!important}}', '<p>Text</p>')));
    }

    // C4: a layered !important beats an unlayered !important.
    public function test_layered_important_beats_unlayered_important(): void
    {
        $this->assertSame([], $this->analyze($this->page('@layer a{p{color:#111!important}} p{color:#ddd!important}', '<p>Text</p>')));
    }

    /** @return array<string, array{0: string, 1: ?string}> CSS for `<p id="t">` and the ratio of the expected Error (null: it passes) */
    public static function nestedAndAnonymousLayers(): array
    {
        return [
            'a nested block is a sub-layer, the parent\'s own rules beat it' => ['@layer a{p{color:#111} @layer b{p#t{color:#ddd}}}', null],
            'a dotted name is the same sub-layer as the nested block' => ['@layer a{@layer b{p{color:#ddd}}} @layer c{p{color:#111}} @layer a.b{p{color:#ddd}}', null],
            'a dotted name ranks inside its parent, before a later top-level layer' => ['@layer a, c; @layer c{p{color:#ddd}} @layer a.b{p#t{color:#111}}', '1.36'],
            'the parent declared after its sub-layer still ranks above it' => ['@layer a.b{p#t{color:#ddd}} @layer a{p{color:#111}}', null],
            'each anonymous layer is a new layer' => ['@layer{p#t{color:#ddd}} @layer{p{color:#111}}', null],
            'an anonymous layer ranks below unlayered rules' => ['p{color:#111} @layer{p#t{color:#ddd}}', null],
            'an anonymous sub-layer ranks below its parent\'s own rules' => ['@layer a{@layer{p#t{color:#ddd}} p{color:#111}}', null],
        ];
    }

    // C4: nested names, dotted names and anonymous layers are ranked by first appearance, sub-layers inside their parent.
    #[DataProvider('nestedAndAnonymousLayers')]
    public function test_nested_and_anonymous_layers_are_ranked(string $css, ?string $ratio): void
    {
        $violations = $this->analyze($this->page($css, '<p id="t">Text</p>'));

        if ($ratio === null) {
            $this->assertSame([], $violations);
        } else {
            $this->assertSame($ratio, $this->assertHasViolation($violations, 'contrast.insufficient', severity: Severity::Error)->params['ratio']);
        }
    }

    /** @return array<string, array{0: string, 1: ?array{0: Severity, 1: ?string}}> the head's style elements and the expected finding's severity and ratio (null: none; a Notice is `contrast.unverifiable`) */
    public static function conditionalLayerDeclarations(): array
    {
        $dark = '@media (prefers-color-scheme: dark){@layer %s{p{color:#111}}}';
        $wide = '@media (min-width:640px){@layer %s{p{color:#111}}}';

        return [
            'a layer first declared in dark mode only' => ['<style>'.sprintf($dark, 'b').' @layer a{p{color:#111}} @layer b{p{color:#ddd}}</style>', [Severity::Error, '1.36']],
            'the mirror: dark mode does not put a first' => ['<style>'.sprintf($dark, 'a').' @layer b{p{color:#ddd}} @layer a{p{color:#111}}</style>', null],
            'a dark-mode sheet' => ['<style media="(prefers-color-scheme: dark)">@layer b{p{color:#111}}</style><style>@layer a{p{color:#111}} @layer b{p{color:#ddd}}</style>', [Severity::Error, '1.36']],
            'an order that depends on the viewport' => ['<style>'.sprintf($wide, 'b').' @layer a{p{color:#111}} @layer b{p{color:#ddd}}</style>', [Severity::Warning, '1.36']],
            'a viewport declaration in the base order' => ['<style>@layer a, b; '.sprintf($wide, 'b').' @layer a{p{color:#111}} @layer b{p{color:#ddd}}</style>', [Severity::Error, '1.36']],
            '@container is always accommodated' => ['<style>@container (min-width:1px){@layer b{p{color:#111}}} @layer a{p{color:#111}} @layer b{p{color:#ddd}}</style>', null],
            // C5: a false @supports declares no layer; an undecidable one may (`limit`).
            'a layer first declared under a false @supports' => ['<style>@supports not (display:grid){@layer b{p{color:#111}}} @layer a{p{color:#111}} @layer b{p{color:#ddd}}</style>', [Severity::Error, '1.36']],
            'an order an undecidable @supports could change' => ['<style>@supports (-webkit-touch-callout:none){@layer b{.z{color:red}}} @layer a{p{color:#111}} @layer b{p{color:#ddd}}</style>', [Severity::Warning, '1.36']],
            // R9 N1: a browser reads `x\.y` as one name and orders b, x.y, a: `a` wins and the text passes.
            'an escaped layer name in the order statement' => ['<style>@layer b, x\\.y, a; @layer a{p{color:#111}} @layer b{p{color:#ddd}}</style>', [Severity::Warning, '1.36']],
            // R9 N2: below 500px `b` is first declared after `a`, so it wins and the text passes.
            'viewport appearances that straddle a sibling' => ['<style>@media (min-width:1000px){@layer b{.z{color:red}}} @layer a{p{color:#ddd}} @media (max-width:500px){@layer b{p{color:#111}}}</style>', [Severity::Warning, '1.36']],
            // Fix round 1: the same condition around a sibling is determinate (below 640px `b` is never declared and its rule never applies).
            'the same viewport condition around a sibling' => ['<style>@media (min-width:640px){@layer b{.z{color:red}}} @layer a{p{color:#ddd}} @media (min-width:640px){@layer b{p{color:#111}}}</style>', [Severity::Error, '1.36']],
            // R15: an ambiguous layer order leaves the winning colour unknown, so a passing estimate is a Notice, not silence.
            'R9 N2 passing estimate' => ['<style>@media (min-width:1000px){@layer b{.z{color:red}}} @layer a{p{color:#111}} @media (max-width:500px){@layer b{p{color:#ddd}}}</style>', [Severity::Notice, null]],
            'a viewport order with a passing estimate' => ['<style>@media(min-width:1000px){@layer b{.z{color:red}}} @layer a{p{color:#ddd}} @layer b{p{color:#111}}</style>', [Severity::Notice, null]],
            'an escaped layer name with a passing estimate' => ['<style>@layer b, x\\.y, a; @layer a{p{color:#ddd}} @layer b{p{color:#111}}</style>', [Severity::Notice, null]],
        ];
    }

    // C4 review: layers declared only under a condition that is false for the default preferences do not take a place in
    // the base layer order; an order that a viewport condition could change is approximate (`limit`).
    #[DataProvider('conditionalLayerDeclarations')]
    public function test_layers_declared_under_a_condition(string $styles, ?array $expected): void
    {
        $violations = $this->analyze('<html><head>'.$styles.'</head><body><p>Text</p></body></html>');

        if ($expected === null) {
            $this->assertSame([], $violations);
        } elseif ($expected[0] === Severity::Notice) {
            $this->assertHasViolation($violations, 'contrast.unverifiable', element: 'p', severity: Severity::Notice);
            $this->assertNoViolation($violations, 'contrast.insufficient');
        } else {
            $violation = $this->assertHasViolation($violations, 'contrast.insufficient', element: 'p', severity: $expected[0]);
            $this->assertSame($expected[1], $violation->params['ratio']);
        }
    }

    /** @return array<string, array{0: string, 1: bool}> CSS around a layered Tailwind sr-only and whether `<span class="sr-only x">` is exempt */
    public static function layeredSrOnly(): array
    {
        $srOnly = '@layer utilities{'.self::TAILWIND_SR_ONLY.'}';

        return [
            'an unlayered position before it' => ['.x{position:static}'.$srOnly, false],
            'an unlayered position after it' => [$srOnly.'.x{position:static}', false],
            'an unlayered universal clip reset' => ['*{clip-path:none;overflow:visible}'.$srOnly, false],
            'a position in an earlier layer' => ['@layer base{.x.x{position:static}}'.$srOnly, true],
            'an important position in an earlier layer' => ['@layer base{.x{position:static!important}}'.$srOnly, false],
        ];
    }

    // C4 with C2: the clipping cascade honours layer order too; unlayered rules beat the utilities layer.
    #[DataProvider('layeredSrOnly')]
    public function test_layer_order_decides_whether_sr_only_text_is_exempt(string $css, bool $exempt): void
    {
        $violations = $this->analyze($this->page($css, '<button type="button" class="text-gray-400"><span class="sr-only x">Close menu</span></button>'));

        if ($exempt) {
            $this->assertSame([], $violations);
        } else {
            $violation = $this->assertHasViolation($violations, 'contrast.insufficient', element: 'span.sr-only.x', severity: Severity::Error);
            $this->assertSame('2.60', $violation->params['ratio']);
        }
    }

    // R38: an icon sprite sheet paints nothing, so it is no backdrop.
    public function test_svg_sprite_sheet_is_not_a_backdrop(): void
    {
        $sprite = '<svg aria-hidden="true" style="position:absolute;width:0;height:0;overflow:hidden"><symbol id="i"></symbol></svg>';

        $violation = $this->assertHasViolation($this->analyze($this->page('', $sprite.'<main><p style="color:#aaa">x</p></main>')), 'contrast.insufficient', severity: Severity::Error);
        $this->assertSame([], $violation->tags);
    }

    // AN4 (spec C7, D3): identical failures give one finding, the different footer note its own.
    public function test_identical_failures_are_grouped(): void
    {
        $violations = $this->analyze($this->page('.date{color:#aaa}', str_repeat('<p class="date">1.1.</p>', 300).'<p class="note" style="color:#bbb">x</p>'));

        $this->assertViolationCount($violations, 'contrast.insufficient', 2);
        [$dates, $note] = array_values(array_filter($violations, fn ($violation) => $violation->key === 'contrast.insufficient'));
        $this->assertSame(['p.date', '/html[1]/body[1]/p[1]'], [$dates->element, $dates->selector]);
        $this->assertSame(300, $dates->meta['occurrences']);
        $this->assertCount(ContrastAnalyzer::MAX_GROUPED_SELECTORS, $dates->meta['selectors']);
        $this->assertSame(20, ContrastAnalyzer::MAX_GROUPED_SELECTORS);
        $this->assertSame(['/html[1]/body[1]/p[2]', '/html[1]/body[1]/p[21]'], [$dates->meta['selectors'][0], $dates->meta['selectors'][19]]);
        $this->assertSame(['p.note', 1, []], [$note->element, $note->meta['occurrences'], $note->meta['selectors']]);
        $this->assertSame('#bbbbbb', $note->params['foreground']);
    }

    public function test_different_thresholds_are_not_grouped(): void
    {
        $violations = $this->analyze($this->page('.t{color:#aaa}', '<p class="t" style="font-size:30px">Large</p><p class="t" style="font-size:16px">Normal</p>'));

        $this->assertViolationCount($violations, 'contrast.insufficient', 2);
        $this->assertSame([3.0, 4.5], array_map(fn ($violation) => $violation->params['required'], $violations));
    }

    public function test_unverifiable_findings_are_grouped(): void
    {
        $violations = $this->analyze($this->page('.hero{background-image:url(/hero.jpg)}', str_repeat('<div class="hero"><p style="color:#000">Welcome</p></div>', 5)));

        $this->assertViolationCount($violations, 'contrast.unverifiable', 1);
        $violation = $this->assertHasViolation($violations, 'contrast.unverifiable', severity: Severity::Notice);
        $this->assertSame(5, $violation->meta['occurrences']);
        $this->assertCount(4, $violation->meta['selectors']);
    }

    // C7: MAX_MEASURED counts distinct style chains, so repeated markup does not stop the analysis before the footer.
    public function test_the_cap_counts_distinct_style_chains(): void
    {
        $html = $this->page('.date{color:#aaa} .note{color:#bbb}', '<main>'.str_repeat('<div class="card"><h3>T</h3><p class="date">1.1.</p></div>', ContrastAnalyzer::MAX_MEASURED + 100).'</main><footer><p class="note">x</p></footer>');

        $violations = $this->analyze($html);

        $this->assertNoViolation($violations, 'contrast.analysis_truncated');
        $this->assertSame(ContrastAnalyzer::MAX_MEASURED + 100, $this->assertHasViolation($violations, 'contrast.insufficient', element: 'p.date')->meta['occurrences']);
        $this->assertHasViolation($violations, 'contrast.insufficient', element: 'p.note');
    }

    // A memoised measurement is reused only by the same style chain: a twin whose previous sibling covers its backdrop, or
    // whose label names a disabled control, is not the earlier twin (black text: the exact twins pass).
    public function test_memoised_twins_keep_their_own_backdrop_and_exemption(): void
    {
        $twins = fn (string $twin, string $other) => str_repeat('<div class="w">'.$twin.'</div>', 2).'<div class="w">'.$other.'</div><div class="w">'.$twin.'</div>';

        $covered = $this->analyze($this->page('.abs{position:absolute;inset:0}', $twins('<span class="abs"></span><p>Welcome</p>', '<img class="abs" src="/hero.jpg" alt=""><p>Welcome</p>')));
        $this->assertSame('/html[1]/body[1]/div[3]/p[1]', $this->assertHasViolation($covered, 'contrast.unverifiable')->selector);

        $labels = $this->analyze($this->page('label{color:#bbb}', $twins('<label>Name <input></label>', '<label>Name <input disabled></label>')));
        $violation = $this->assertHasViolation($labels, 'contrast.insufficient', severity: Severity::Error);
        $this->assertSame(3, $violation->meta['occurrences']);
        $this->assertNotContains('/html[1]/body[1]/div[3]/label[1]', [$violation->selector, ...$violation->meta['selectors']]);

        // `hidden` is read by the analysis, not by a selector: it stays in the chain (R40).
        $hidden = $this->assertHasViolation($this->analyze($this->page('p{color:#bbb}', $twins('<p>x</p>', '<p hidden>x</p>'))), 'contrast.insufficient');
        $this->assertSame(3, $hidden->meta['occurrences']);
        $this->assertNotContains('/html[1]/body[1]/div[3]/p[1]', [$hidden->selector, ...$hidden->meta['selectors']]);
    }

    /** @return string a listing of cards with a unique href and wire:key each (MAX_MEASURED + 100 by default), and a footer note */
    private function listing(string $css, int $cards = ContrastAnalyzer::MAX_MEASURED + 100): string
    {
        $cards = implode('', array_map(fn (int $n) => '<a href="/p/'.$n.'" wire:key="'.$n.'"><p class="date">1.1.</p></a>', range(1, $cards)));

        return $this->page($css, '<main>'.$cards.'</main><footer><p class="note" style="color:#bbb">x</p></footer>');
    }

    /** @return array<string, array{0: string}> stylesheets that see the cards' href at most by its presence */
    public static function hrefUnseenOrPresence(): array
    {
        return [
            'no selector' => ['.date{color:#aaa}'],
            '[href]' => ['a[href] .date{color:#aaa}'],
            ':link' => ['a:link .date{color:#aaa}'],
        ];
    }

    // R40: attributes no selector can see (or only by presence) do not split the style chain: the listing is measured whole.
    #[DataProvider('hrefUnseenOrPresence')]
    public function test_attribute_values_no_selector_sees_do_not_split_the_chain(string $css): void
    {
        $violations = $this->analyze($this->listing($css));

        $this->assertNoViolation($violations, 'contrast.analysis_truncated');
        $this->assertViolationCount($violations, 'contrast.insufficient', 2);
        $this->assertSame(ContrastAnalyzer::MAX_MEASURED + 100, $this->assertHasViolation($violations, 'contrast.insufficient', element: 'p.date')->meta['occurrences']);
        $this->assertHasViolation($violations, 'contrast.insufficient', element: 'p.note');
    }

    // R40: a selector that tests an attribute value makes it part of the chain (#999 is 2.85:1; of 200 cards, hrefs /p/1,
    // /p/10–19 and /p/100–199 match). Every href then counts, so the listing stays below MAX_MEASURED here.
    public function test_attribute_values_a_selector_tests_split_the_chain(): void
    {
        $violations = $this->analyze($this->listing('.date{color:#aaa} a[href^="/p/1"] .date{color:#999}', 200));

        $occurrences = [];

        foreach ($violations as $violation) {
            $occurrences[$violation->params['foreground'] ?? $violation->key] = $violation->meta['occurrences'] ?? null;
        }

        $this->assertSame(['#999999' => 111, '#aaaaaa' => 89, '#bbbbbb' => 1], $occurrences);
    }

    /** Tailwind v4 theme tokens of contrast report C1 (oklch, as a v4 build declares them). */
    private const TAILWIND_V4_THEME = '@layer theme, base, components, utilities;
@layer theme{:root,:host{--color-white:#fff;--color-gray-300:oklch(87.2% .01 258.338);
  --color-gray-400:oklch(70.7% .022 261.325);--color-gray-500:oklch(55.1% .027 264.364);
  --color-gray-600:oklch(44.6% .03 256.802);--color-gray-900:oklch(21% .034 264.665);--color-gray-950:oklch(13% .028 261.692)}}';

    /** Contrast report C1, repro A: Tailwind v4 class-based dark mode (`@custom-variant dark (&:where(.dark, .dark *))`). */
    private function tailwindDarkPage(string $text, string $darkText, bool $dark = true, bool $variants = true): string
    {
        return '<html lang="en"'.($dark ? ' class="dark"' : '').'><head><style>'.self::TAILWIND_V4_THEME.'
@layer utilities{
  .bg-white{background-color:var(--color-white)}
  .text-'.$text.'{color:var(--color-'.$text.')}'.($variants ? '
  .dark\:bg-gray-950{&:where(.dark,.dark *){background-color:var(--color-gray-950)}}
  .dark\:text-'.$darkText.'{&:where(.dark,.dark *){color:var(--color-'.$darkText.')}}' : '').'
}</style></head>
<body class="bg-white dark:bg-gray-950"><p class="text-'.$text.' dark:text-'.$darkText.'">Muted copy</p></body></html>';
    }

    /**
     * Rule D2 for a rule the parser cannot evaluate: never a definite Error; where the browser fails ($browserFails), a
     * Warning tagged `approximate` or the Notice `contrast.unverifiable` instead of nothing.
     *
     * @param  list<Violation>  $violations
     */
    private function assertUncertainVerdict(array $violations, bool $browserFails): void
    {
        $contrast = array_values(array_filter($violations, fn ($violation) => str_starts_with($violation->key, 'contrast.')));
        $found = implode('; ', array_map(fn ($violation) => $violation->key.' '.$violation->severity->value.' '.implode(',', $violation->tags).' '.($violation->params['ratio'] ?? ''), $contrast)) ?: '(none)';

        foreach ($contrast as $violation) {
            $this->assertNotSame(Severity::Error, $violation->severity, 'no definite Error: '.$found);
        }

        if ($browserFails) {
            $uncertain = array_filter($contrast, fn ($violation) => ($violation->key === 'contrast.insufficient' && $violation->severity === Severity::Warning && in_array('approximate', $violation->tags, true))
                || ($violation->key === 'contrast.unverifiable' && $violation->severity === Severity::Notice));
            $this->assertNotSame([], $uncertain, 'a Warning or Notice where the browser fails: '.$found);
        }
    }

    // C1, repro A: the dark variant (0,1,0 in a browser, later than text-gray-400) renders #d1d5dc on #030712, 13.67:1.
    public function test_tailwind_v4_class_dark_mode_is_not_a_definite_error(): void
    {
        $violations = $this->analyze($this->tailwindDarkPage('gray-400', 'gray-300'));

        $this->assertUncertainVerdict($violations, false);
        $violation = $this->assertHasViolation($violations, 'contrast.insufficient', severity: Severity::Warning);
        $this->assertSame(['approximate'], $violation->tags);
        $this->assertSame('2.60', $violation->params['ratio'], 'the estimate is the light theme');
    }

    // C1: `text-gray-600 dark:text-gray-500` renders #6a7282 on #030712, 4.16:1; the light estimate passes at 7.56:1.
    public function test_tailwind_v4_class_dark_mode_silent_pass_becomes_a_notice(): void
    {
        $violations = $this->analyze($this->tailwindDarkPage('gray-600', 'gray-500'));

        $this->assertUncertainVerdict($violations, true);
        $this->assertHasViolation($violations, 'contrast.unverifiable', severity: Severity::Notice);
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> contrast report C1 rows (daisyUI 5 left out): CSS, body, whether the browser fails */
    public static function unsupportedSelectorRows(): array
    {
        $theme = self::TAILWIND_V4_THEME;

        return [
            'Tailwind v4 odd:' => [$theme.'@layer utilities{.text-gray-500{color:var(--color-gray-500)} .odd\:bg-gray-900{&:nth-child(odd){background-color:var(--color-gray-900)}}}',
                '<table><tbody><tr class="odd:bg-gray-900"><td class="text-gray-500">Row 1</td></tr><tr class="odd:bg-gray-900"><td class="text-gray-500">Row 2</td></tr></tbody></table>', true],
            'Tailwind v4 *:' => [$theme.'@layer utilities{.\*\:text-gray-400{:is(&>*){color:var(--color-gray-400)}}}',
                '<ul class="*:text-gray-400"><li>Item</li></ul>', true],
            'typography :where()' => ['.prose :where(blockquote):not(:where([class~="not-prose"],[class~="not-prose"] *)){color:#9ca3af}',
                '<div class="prose"><blockquote>Quote</blockquote></div>', true],
            ':is(.card,.panel) p' => [':is(.card,.panel) p{color:#fff} .card{background:#111}', '<div class="card"><p>Text</p></div>', false],
            ':where(.card) p' => [':where(.card) p{color:#fff} .card{background:#111}', '<div class="card"><p>Text</p></div>', false],
            'tr:nth-child(even)' => ['td{color:#767676} tr:nth-child(even){background:#e5e5e5}', '<table><tr><td>One</td></tr><tr><td>Two</td></tr></table>', true],
            'h2 + p' => ['h2 + p{background:#111} p{color:#222}', '<h2>Title</h2><p>Text</p>', true],
            'a:not(.btn,.link) fails' => ['a:not(.btn,.link){color:#aaa}', '<p><a href="/x">Link</a></p>', true],
            'a:not(.btn,.link) passes' => ['a{color:#aaa} a:not(.btn,.link){color:#111}', '<p><a href="/x">Link</a></p>', false],
            'p:first-of-type fails' => ['p:first-of-type{color:#aaa}', '<p>First</p>', true],
            'p:first-of-type passes' => ['p{color:#aaa} p:first-of-type{color:#111}', '<p>First</p>', false],
            'a:any-link fails' => ['a:any-link{color:#aaa}', '<a href="/x">Link</a>', true],
            'a:any-link passes' => ['a{color:#aaa} a:any-link{color:#111}', '<a href="/x">Link</a>', false],
            '.card:has(img) fails (inherited)' => ['.card:has(img){color:#eee}', '<div class="card"><img src="/a.png" alt=""><p>Text</p></div>', true],
            '.card:has(img) passes' => ['.card{color:#eee} .card:has(img){background:#111}', '<div class="card"><img src="/a.png" alt=""><p>Text</p></div>', false],
            'opacity behind :has()' => ['p{color:#555} .card:has(img) p{opacity:.3}', '<div class="card"><img src="/a.png" alt=""><p>Text</p></div>', true],
            'font-size behind :has()' => ['.big{font-size:24px;color:#888} .card:has(img) .big{font-size:12px}', '<div class="card"><img src="/a.png" alt=""><p class="big">Text</p></div>', true],
            'font-weight behind :has()' => ['.big{font-size:19px;font-weight:700;color:#888} .card:has(img) .big{font-weight:400}', '<div class="card"><img src="/a.png" alt=""><p class="big">Text</p></div>', true],
        ];
    }

    // C1: a rule whose selector cannot be evaluated and that sets a deciding property makes the verdict uncertain.
    #[DataProvider('unsupportedSelectorRows')]
    public function test_unsupported_selector_rules_make_the_verdict_uncertain(string $css, string $body, bool $browserFails): void
    {
        $this->assertUncertainVerdict($this->analyze($this->page($css, $body)), $browserFails);
    }

    // C1, Tailwind 3.4.1+ `darkMode: 'selector'`: 12.04:1 in a browser; the light estimate (2.54:1) is only a Warning.
    public function test_tailwind_v3_selector_dark_mode_is_not_a_definite_error(): void
    {
        $css = '.bg-white{--tw-bg-opacity:1;background-color:rgb(255 255 255 / var(--tw-bg-opacity))}'
            .'.text-gray-400{--tw-text-opacity:1;color:rgb(156 163 175 / var(--tw-text-opacity))}'
            .'.dark\:bg-gray-900:where(.dark, .dark *){--tw-bg-opacity:1;background-color:rgb(17 24 39 / var(--tw-bg-opacity))}'
            .'.dark\:text-gray-300:where(.dark, .dark *){--tw-text-opacity:1;color:rgb(209 213 219 / var(--tw-text-opacity))}';
        $html = '<html lang="en" class="dark"><head><style>'.$css.'</style></head><body class="bg-white dark:bg-gray-900"><p class="text-gray-400 dark:text-gray-300">Muted</p></body></html>';

        $this->assertUncertainVerdict($this->analyze($html), false);
    }

    // C1: a maybe rule that cannot beat the definite winner leaves the verdict definite.
    public function test_unsupported_selector_rule_that_cannot_win_keeps_the_error(): void
    {
        $css = 'p#t{color:#aaa} p:first-of-type{color:#111} @layer a{.x:has(b) p{background:#111}} p{background:#fff}';

        $this->assertHasViolation($this->analyze($this->page($css, '<div class="x"><p id="t">Text</p></div>')), 'contrast.insufficient', severity: Severity::Error);
    }

    // C1: a rule behind an unsupported selector that sets no deciding property changes nothing.
    public function test_unsupported_selector_rule_without_contrast_properties_changes_nothing(): void
    {
        $css = '.low{color:#aaa} .ok{color:#111}';
        $body = '<div class="card"><img src="/a.png" alt=""><p class="low">Low</p><p class="ok">Fine</p><p>Plain</p></div>';
        $without = $this->analyze($this->page($css, $body));

        $this->assertHasViolation($without, 'contrast.insufficient', severity: Severity::Error);
        $this->assertEquals($without, $this->analyze($this->page($css.' .card:has(img){margin:0} .card:has(img) p{line-height:2;--unused:1}', $body)));
    }

    // C1, C2 review: a revealing rule behind an unsupported selector blocks the visually-hidden exemption.
    public function test_revealing_rule_behind_an_unsupported_selector_measures_sr_only_text(): void
    {
        $css = self::TAILWIND_SR_ONLY.' .wrap:has(b) .sr-only{position:static}';
        $body = '<div class="wrap"><b>Bold</b><button type="button" class="text-gray-400"><span class="sr-only">Close menu</span></button></div>';

        $violation = $this->assertHasViolation($this->analyze($this->page($css, $body)), 'contrast.insufficient', element: 'span.sr-only');
        $this->assertSame([Severity::Warning, ['approximate']], [$violation->severity, $violation->tags], 'R10: the text may still be hidden');
    }

    // R10: a light page carries no `.dark`, so every dark: variant provably cannot match and is dropped as in 3.1.0.
    public function test_tailwind_v4_class_dark_mode_on_a_light_page_is_unchanged(): void
    {
        foreach ([['gray-600', 'gray-500'], ['gray-400', 'gray-300']] as [$text, $darkText]) {
            $this->assertEquals(
                $this->analyze($this->tailwindDarkPage($text, $darkText, dark: false, variants: false)),
                $this->analyze($this->tailwindDarkPage($text, $darkText, dark: false)),
                "text-$text dark:text-$darkText",
            );
        }

        $this->assertSame([], $this->analyze($this->tailwindDarkPage('gray-600', 'gray-500', dark: false)), 'no Notice');
        $this->assertHasViolation($this->analyze($this->tailwindDarkPage('gray-400', 'gray-300', dark: false)), 'contrast.insufficient', severity: Severity::Error);
    }

    private const BOOTSTRAP_BUTTON = '.btn{color:var(--bs-btn-color);background-color:var(--bs-btn-bg)}
.btn-primary{--bs-btn-color:#fff;--bs-btn-bg:#0d6efd;--bs-btn-active-color:#fff;--bs-btn-active-bg:#0a58ca}
.btn-check:checked+.btn,.btn.active{color:var(--bs-btn-active-color);background-color:var(--bs-btn-active-bg)}';

    // R10: `.btn-check:checked+.btn` matches nothing without a checked .btn-check, so a Bootstrap button stays exact.
    public function test_bootstrap_checked_button_rule_needs_a_checked_btn_check(): void
    {
        $button = '<button class="btn btn-primary">Save</button>';

        $this->assertSame([], $this->analyze($this->page(self::BOOTSTRAP_BUTTON, $button)), 'no .btn-check');
        $this->assertSame([], $this->analyze($this->page(self::BOOTSTRAP_BUTTON, '<input type="checkbox" class="btn-check" id="c">'.$button)), 'nothing is checked');
        $this->assertHasViolation($this->analyze($this->page(self::BOOTSTRAP_BUTTON, '<input type="checkbox" class="btn-check" id="c" checked>'.$button)), 'contrast.unverifiable', severity: Severity::Notice);
    }

    // R10: a negated class does not have to be present: the supported rule applies, the unsupported one stays a maybe rule.
    public function test_negated_absent_class_keeps_the_rule(): void
    {
        $this->assertHasViolation($this->analyze($this->page(':not(.absent) p{color:#ddd}', '<div><p>Text</p></div>')), 'contrast.insufficient', severity: Severity::Error);
        $this->assertHasViolation($this->analyze($this->page(':not(.absent) p:first-of-type{color:#ddd}', '<div><p>Text</p></div>')), 'contrast.unverifiable', severity: Severity::Notice);
    }

    // R10, D2: a maybe rule that may clip the text to nothing leaves the failure approximate.
    public function test_maybe_rule_that_may_clip_the_text_makes_a_failure_approximate(): void
    {
        $css = '.label{color:#aaa} .wrap:has(b) .label{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}';
        $violation = $this->assertHasViolation($this->analyze($this->page($css, '<div class="wrap"><b>b</b><span class="label">Label</span></div>')), 'contrast.insufficient', severity: Severity::Warning);
        $this->assertSame(['approximate'], $violation->tags);
        $this->assertSame([], $this->analyze($this->page('.label{color:#111}'.substr($css, 18), '<div class="wrap"><b>b</b><span class="label">Label</span></div>')), 'a pass stays silent');
    }

    // R12 re-review: the token cap counts distinct names, so a long light Tailwind list keeps the prefilter.
    public function test_many_duplicate_class_tokens_keep_the_prefilter(): void
    {
        $row = '<li class="flex items-center gap-2 px-4 py-2 text-sm text-gray-600 dark:text-gray-500 border-b">Row</li>';
        $html = $this->tailwindDarkPage('gray-600', 'gray-500', dark: false);
        $html = str_replace('<p class="text-gray-600 dark:text-gray-500">Muted copy</p>', '<ul>'.str_repeat($row, 10000).'</ul>', $html);
        $this->assertGreaterThan(1000000, strlen($html));

        $this->assertSame([], $this->analyze($html));
    }

    /** Bootstrap 5.3: the stock button, nav link and dropdown item with their :disabled rules (heavier than the base rules). */
    private const BOOTSTRAP_DISABLED = '.btn{color:var(--bs-btn-color);background-color:var(--bs-btn-bg)}
.btn-primary{--bs-btn-color:#fff;--bs-btn-bg:#0d6efd;--bs-btn-disabled-color:#fff;--bs-btn-disabled-bg:#0d6efd}
.btn:disabled,.btn.disabled{color:var(--bs-btn-disabled-color);background-color:var(--bs-btn-disabled-bg);opacity:.65}
.nav-link{color:#0a58ca}.nav-link:disabled,.nav-link.disabled{color:#6c757d;opacity:.65}
.dropdown-item{color:#212529;background-color:transparent}.dropdown-item:disabled,.dropdown-item.disabled{color:#adb5bd;background-color:transparent}';

    // R12: :disabled needs a disabled attribute somewhere in the document, so stock Bootstrap components stay exact.
    public function test_bootstrap_disabled_rules_need_a_disabled_element(): void
    {
        $body = '<button class="btn btn-primary">Save</button><a class="nav-link" href="/a">Link</a><a class="dropdown-item" href="/b">Item</a>';

        $this->assertSame([], $this->analyze($this->page(self::BOOTSTRAP_DISABLED, $body)), 'nothing is disabled');
        $this->assertHasViolation($this->analyze($this->page(self::BOOTSTRAP_DISABLED, $body.'<button class="btn btn-primary" disabled>Off</button>')), 'contrast.unverifiable', severity: Severity::Notice);
    }

    /** Bootstrap 5.3: the base rules of a btn-check group and a form check, and the `[disabled]` twins of their :disabled rules. */
    private const BOOTSTRAP_CHECKS = '.btn{color:#212529;background-color:#fff}.form-check-label{color:#212529}'
        .'.btn-check{position:absolute;clip:rect(0,0,0,0);pointer-events:none}';

    private const BOOTSTRAP_DISABLED_TWINS = '.btn-check:disabled+.btn,.btn-check[disabled]+.btn{pointer-events:none;filter:none;opacity:.65}'
        .'.form-check-input[disabled]~.form-check-label,.form-check-input:disabled~.form-check-label{cursor:default;opacity:.5}';

    private const BOOTSTRAP_CHECKS_BODY = '<div class="btn-group" role="group" aria-label="Choice">'
        .'<input type="radio" class="btn-check" name="r" id="r1" autocomplete="off" checked><label class="btn" for="r1">One</label>'
        .'<input type="radio" class="btn-check" name="r" id="r2" autocomplete="off"><label class="btn" for="r2">Two</label></div>'
        .'<div class="form-check"><input class="form-check-input" type="checkbox" id="c1"><label class="form-check-label" for="c1">Agree</label></div>';

    // Final review I2: an attribute selector needs an element that carries the attribute, so the `[disabled]` twins of
    // Bootstrap's :disabled rules add no Notice to a page where nothing is disabled.
    public function test_bootstrap_disabled_attribute_rules_need_a_disabled_element(): void
    {
        $plain = $this->analyze($this->page(self::BOOTSTRAP_CHECKS, self::BOOTSTRAP_CHECKS_BODY));
        $this->assertSame([], $plain);
        $this->assertSame($plain, $this->analyze($this->page(self::BOOTSTRAP_CHECKS.self::BOOTSTRAP_DISABLED_TWINS, self::BOOTSTRAP_CHECKS_BODY)), 'nothing is disabled');

        $disabled = str_replace('id="c1"', 'id="c1" DISABLED', self::BOOTSTRAP_CHECKS_BODY);
        $twinsOnly = '.btn-check[disabled]+.btn{opacity:.65}.form-check-input[disabled]~.form-check-label{opacity:.5}';
        $this->assertHasViolation($this->analyze($this->page(self::BOOTSTRAP_CHECKS.$twinsOnly, $disabled)), 'contrast.unverifiable', element: 'label.btn', severity: Severity::Notice);
        // Attribute names are ASCII case-insensitive; the label of the disabled checkbox itself is exempt (inactive control).
        $this->assertHasViolation($this->analyze($this->page(self::BOOTSTRAP_CHECKS.'.btn-check[DISABLED=""]+.btn{opacity:.65}', $disabled)), 'contrast.unverifiable', element: 'label.btn', severity: Severity::Notice);
    }

    /** @return array<string, array{0: string}> I2: maybe rules over passing text whose attribute selector is not required, or not decided */
    public static function attributeRulesThatMayMatch(): array
    {
        return [
            ':not([disabled]) before the compound' => [':not([disabled])+p{opacity:.5}'],
            ':not([disabled]) in the compound' => ['div:not([disabled])+p{opacity:.5}'],
            'one :is() argument without the attribute' => [':is([disabled],div)+p{opacity:.5}'],
            'an attribute of the document' => ['[class=x]+p{opacity:.5}'],
            'a namespace prefix' => ['[*|disabled]+p{opacity:.5}'],
            'an empty namespace' => ['[|disabled]+p{opacity:.5}'],
            'an unclosed bracket' => ['[disabled+p{opacity:.5}'],
        ];
    }

    #[DataProvider('attributeRulesThatMayMatch')]
    public function test_attribute_selectors_that_are_not_required_are_kept(string $rule): void
    {
        $violations = $this->analyze($this->page('p{color:#111} '.$rule, '<div class="x">x</div><p>Text</p>'));

        $this->assertHasViolation($violations, 'contrast.unverifiable', element: 'p', severity: Severity::Notice);
    }

    // I2: `:not([disabled]) p` is in the supported subset and applies (#111 at half opacity is 3.6:1 here).
    public function test_a_negated_attribute_selector_is_never_dropped(): void
    {
        $violation = $this->assertHasViolation($this->analyze($this->page('p{color:#111} :not([disabled]) p{opacity:.5}', '<div><p>Text</p></div>')), 'contrast.insufficient', element: 'p', severity: Severity::Error);
        $this->assertSame([], $violation->tags);
    }

    /** @return array<string, array{0: string}> I2: maybe rules whose required compound names an attribute no element carries */
    public static function attributeRulesThatCannotMatch(): array
    {
        return [
            'presence' => ['[disabled]+p{opacity:.5}'],
            'a value' => ['[data-state="open"]+p{opacity:.5}'],
            'an operator' => ['[aria-expanded~=true]+p{opacity:.5}'],
            'dash match' => ['[hreflang|=en]+p{opacity:.5}'],
            'escaped and spaced' => ['[ data\\-state = "open" i ]+p{opacity:.5}'],
            'inside :has()' => ['div:has([hidden])+p{opacity:.5}'],
            'every :is() argument' => [':is([open],[inert])+p{opacity:.5}'],
        ];
    }

    #[DataProvider('attributeRulesThatCannotMatch')]
    public function test_attribute_selectors_need_the_attribute_in_the_document(string $rule): void
    {
        $this->assertSame([], $this->analyze($this->page('p{color:#111} '.$rule, '<div class="x">x</div><p>Text</p>')));
    }

    /** @return array<string, array{0: string, 1: string, 2: Severity}> R12 review: a maybe clipping rule over failing text (#aaa on .t), the body and the verdict */
    public static function maybeClippingRules(): array
    {
        return [
            'overflow:hidden on an ancestor' => ['.card:has(img){overflow:hidden}', '<div class="card"><img src="/a.png" alt=""><p class="t">Text</p></div>', Severity::Error],
            'a zero padding' => [':where(ul.list) > li{padding:0}', '<ul class="list"><li class="t">Text</li></ul>', Severity::Error],
            'typography logical padding' => [':where(tbody td:first-child){padding-inline-start:0}', '<table><tbody><tr><td class="t">Text</td></tr></tbody></table>', Severity::Error],
            'min-width:auto on every child' => [':is(.flex) > *{min-width:auto}', '<div class="flex"><p class="t">Text</p></div>', Severity::Error],
            'completing a 1px overflow clip' => ['.t{position:absolute;width:1px;height:1px} .wrap:has(b) .t{overflow:hidden}', '<div class="wrap"><b>b</b><span class="t">Text</span></div>', Severity::Warning],
            'completing a clip on an ancestor' => ['.box{position:absolute} .wrap:has(b) .box{clip:rect(0,0,0,0)}', '<div class="wrap"><b>b</b><div class="box"><span class="t">Text</span></div></div>', Severity::Warning],
        ];
    }

    // R12 review: only a maybe rule that can complete a clip to nothing makes the failure approximate.
    #[DataProvider('maybeClippingRules')]
    public function test_only_a_maybe_rule_that_can_complete_a_clip_makes_the_failure_approximate(string $rule, string $body, Severity $severity): void
    {
        $violation = $this->assertHasViolation($this->analyze($this->page('.t{color:#aaa} '.$rule, $body)), 'contrast.insufficient');

        $this->assertSame($severity, $violation->severity);
    }

    // R12 review: content-visibility rules of the supported subset count against no budget, so an exempt sr-only stays exempt.
    public function test_supported_content_visibility_rules_change_nothing(): void
    {
        $css = implode('', array_map(fn (int $i): string => ".cv$i{content-visibility:auto}", range(1, 2100))).self::TAILWIND_SR_ONLY;

        $this->assertSame([], $this->analyze($this->page($css, self::ICON_BUTTON)));
    }

    /** @return array<string, array{0: string, 1: bool}> R10: unsupported rules that may hide the text, and whether they make the failure approximate */
    public static function maybeHidingRules(): array
    {
        return [
            'display:none on the element' => ['.x:has(b) p{display:none}', true],
            'visibility:hidden on an ancestor' => ['.x:has(b){visibility:hidden}', true],
            'content-visibility:hidden on an ancestor' => ['.x:has(b){content-visibility:hidden}', true],
            'outweighed' => ['p#t{display:block} .x:has(b) p{display:none}', false],
            'only at some viewports' => ['@media (min-width:640px){.x:has(b) p{display:none}}', false],
            'revealing' => ['.x:has(b) p{display:block;visibility:visible}', false],
        ];
    }

    #[DataProvider('maybeHidingRules')]
    public function test_maybe_rule_that_may_hide_the_text_makes_a_failure_approximate(string $rule, bool $approximate): void
    {
        $body = '<div class="x"><b>b</b><p id="t">Text</p></div>';
        $violation = $this->assertHasViolation($this->analyze($this->page('p{color:#aaa} '.$rule, $body)), 'contrast.insufficient');

        $this->assertSame($approximate ? [Severity::Warning, ['approximate']] : [Severity::Error, []], [$violation->severity, $violation->tags]);
        $this->assertSame([], $this->analyze($this->page('p{color:#111} '.$rule, $body)), 'a pass stays silent');
    }

    // C5, repro A: the `@supports not (color:oklch(…))` fallbacks are false in every current browser, so the dark theme's
    // oklch() values apply: #ffbe00 on #1d232a is 9.52:1, #00a96e on it 5.23:1.
    public function test_daisyui4_dark_theme_is_not_measured_against_light_fallbacks(): void
    {
        $html = '<html lang="en" data-theme="dark"><head><style>
:root{color-scheme:light;--wa:84.71% 0.199 83.87;--su:64.8% 0.150 160;--b1:100% 0 0;--bc:27.8078% 0.029596 256.847952}
[data-theme=dark]{color-scheme:dark;--b1:25.3267% 0.015896 252.417568;--bc:74.6477% 0.0216 264.435964}
:root,[data-theme]{background-color:var(--fallback-b1,oklch(var(--b1)/1));color:var(--fallback-bc,oklch(var(--bc)/1))}
@supports not (color:oklch(0% 0 0)){:root{color-scheme:light;--fallback-wa:#ffc22d;--fallback-su:#00a96e;
  --fallback-b1:#ffffff;--fallback-bc:#1f2937}}
.bg-base-100{--tw-bg-opacity:1;background-color:var(--fallback-b1,oklch(var(--b1)/var(--tw-bg-opacity)))}
.text-warning{--tw-text-opacity:1;color:var(--fallback-wa,oklch(var(--wa)/var(--tw-text-opacity)))}
.text-success{--tw-text-opacity:1;color:var(--fallback-su,oklch(var(--su)/var(--tw-text-opacity)))}
</style></head><body><div class="bg-base-100">
<span class="text-warning">Warning text</span> <span class="text-success">Success</span></div></body></html>';

        $this->assertSame([], $this->analyze($html));
    }

    // C5, repro B: `@supports not (display:grid)` is false in every current browser; a browser shows #111.
    public function test_supports_not_grid_block_does_not_apply(): void
    {
        $this->assertSame([], $this->analyze($this->page('p{color:#111} @supports not (display:grid){p{color:#eee}}', '<p>Text</p>')));
        $this->assertSame([], $this->analyze($this->page('p{color:#111} p{@supports not (display:grid){color:#eee}}', '<p>Text</p>')), 'nested');
    }

    /** @return array<string, array{0: string}> C5: @supports conditions the parser cannot decide (iOS only, selector(), an unknown value) */
    public static function undecidableSupports(): array
    {
        return [
            'vendor test' => ['(-webkit-touch-callout:none)'],
            'not selector()' => ['not selector(:has(a))'],
            'unknown value' => ['(display:nonsense-value)'],
        ];
    }

    // C5, rule D2: a rule behind an undecidable @supports is a maybe rule: never a definite Error, never silent where a browser may fail.
    #[DataProvider('undecidableSupports')]
    public function test_vendor_supports_block_is_uncertain_not_definite(string $condition): void
    {
        $alone = $this->analyze($this->page('@supports '.$condition.'{p{color:#eee}}', '<p>Text</p>'));
        $this->assertUncertainVerdict($alone, true);
        $this->assertHasViolation($alone, 'contrast.unverifiable', severity: Severity::Notice);

        $failing = $this->analyze($this->page('p{color:#eee} @supports '.$condition.'{p{color:#111}}', '<p>Text</p>'));
        $violation = $this->assertHasViolation($failing, 'contrast.insufficient', severity: Severity::Warning);
        $this->assertSame(['approximate'], $violation->tags);
        $this->assertSame('1.16', $violation->params['ratio']);

        $this->assertUncertainVerdict($this->analyze($this->page('p{color:#111} @supports '.$condition.'{p{color:#eee}}', '<p>Text</p>')), true);
        $this->assertUncertainVerdict($this->analyze($this->page(':root{--fg:#111} @supports '.$condition.'{:root{--fg:#eee}} p{color:var(--fg)}', '<p>Text</p>')), true);
        $this->assertUncertainVerdict($this->analyze($this->page(':root{--fg:#111} @supports '.$condition.'{:host{--fg:#eee}} p{color:var(--fg)}', '<p>Text</p>')), true);
        $this->assertHasViolation($this->analyze($this->page('@supports '.$condition.'{:root{--fg:#111}} :root{--fg:#eee} p{color:var(--fg)}', '<p>Text</p>')), 'contrast.insufficient', severity: Severity::Error);
        $this->assertUncertainVerdict($this->analyze($this->page('p{color:#111} p{@supports '.$condition.'{color:#eee}}', '<p>Text</p>')), true);
    }

    // C5: an undecidable rule that cannot win (lighter than the definite winner) or cannot match changes nothing.
    public function test_undecidable_supports_rule_that_cannot_win_keeps_the_verdict(): void
    {
        $this->assertHasViolation($this->analyze($this->page('p#t{color:#eee} @supports (-webkit-touch-callout:none){p{color:#111}}', '<p id="t">Text</p>')), 'contrast.insufficient', severity: Severity::Error);
        $this->assertSame([], $this->analyze($this->page('p{color:#111} @supports (-webkit-touch-callout:none){.absent{color:#eee}}', '<p>Text</p>')));
    }

    // C5: @-moz-document (Firefox only) and @document blocks are dropped.
    public function test_moz_document_block_is_dropped(): void
    {
        $this->assertSame([], $this->analyze($this->page('p{color:#111} @-moz-document url-prefix(){p{color:#eee}}', '<p>Text</p>')));
        $this->assertSame([], $this->analyze($this->page('p{color:#111} @document url-prefix(){p{color:#eee}}', '<p>Text</p>')));
        $this->assertSame([], $this->analyze($this->page('p{color:#111} p{@-moz-document url-prefix(){color:#eee}}', '<p>Text</p>')), 'nested');
    }

    // C5: Tailwind v4's `@supports (color: color-mix(in lab, red, red))` pairs are true, so the modern colour applies as in 3.1.0.
    public function test_tailwind_v4_color_mix_supports_pair_is_unchanged(): void
    {
        $fallback = '.text-gray-300\/90{color:#d1d5dccc}';
        $modern = '.text-gray-300\/90{color:color-mix(in oklab, #d1d5dc 90%, transparent)}';
        $body = '<p class="text-gray-300/90">Muted</p>';

        $wrapped = $this->analyze($this->page($fallback.'@supports (color: color-mix(in lab, red, red)){'.$modern.'}', $body));
        $violation = $this->assertHasViolation($wrapped, 'contrast.insufficient', severity: Severity::Error);
        $this->assertSame([], $violation->tags);
        $this->assertEquals($this->analyze($this->page($fallback.$modern, $body)), $wrapped);
    }

    // C5: Tailwind v4's @property fallback block sits behind an undecidable @supports; it declares only `--tw-*` initial
    // values that colours do not read, so it adds no finding and keeps every verdict definite.
    public function test_tailwind_v4_property_fallback_block_adds_no_noise(): void
    {
        $utilities = '@layer properties; @layer theme, base, components, utilities;'.self::TAILWIND_V4_THEME.'@layer utilities{.bg-white{background-color:var(--color-white)}'
            .'.text-gray-400{color:var(--color-gray-400)} .text-gray-900{color:var(--color-gray-900)}'
            .'.shadow-sm{--tw-shadow:0 1px 3px 0 var(--tw-shadow-color,#0000001a);box-shadow:var(--tw-inset-shadow),var(--tw-inset-ring-shadow),var(--tw-ring-offset-shadow),var(--tw-ring-shadow),var(--tw-shadow)}}';
        $fallback = '@layer properties{@supports ((-webkit-hyphens:none) and (not (margin-trim:inline))) or ((-moz-orient:inline) and (not (color:rgb(from red r g b)))){'
            .'*,:before,:after,::backdrop{--tw-shadow:0 0 #0000;--tw-shadow-color:initial;--tw-inset-shadow:0 0 #0000;--tw-ring-color:initial;--tw-ring-shadow:0 0 #0000;--tw-inset-ring-shadow:0 0 #0000;--tw-ring-offset-shadow:0 0 #0000;--tw-font-weight:initial}}}';
        $body = '<div class="bg-white"><p class="text-gray-900 shadow-sm">Readable</p><p class="text-gray-400">Muted</p></div>';

        $without = $this->analyze($this->page($utilities, $body));
        $this->assertHasViolation($without, 'contrast.insufficient', element: 'p.text-gray-400', severity: Severity::Error);
        $this->assertEquals($without, $this->analyze($this->page($utilities.$fallback, $body)));
    }

    // C1: every fixture (only selectors 3.1.0 supports carry contrast properties there) keeps its recorded 3.1.0 findings.
    // tailwind-v4-app.html carries `:where()` dark variants 3.1.0 does not support (FixtureExpectationsTest covers it).
    public function test_supported_selector_pages_are_unchanged(): void
    {
        $expected = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/contrast-expectations.json'), true, flags: JSON_THROW_ON_ERROR);
        $files = array_values(array_filter(glob(__DIR__.'/../Fixtures/*.html') ?: [], fn (string $file) => basename($file) !== 'tailwind-v4-app.html'));
        $this->assertSame(array_keys($expected), array_map('basename', $files), 'every fixture is recorded');

        foreach ($files as $file) {
            $violations = (new ContrastAnalyzer(level: 'AA'))->analyze(HtmlDocument::fromHtml((string) file_get_contents($file)));
            $actual = array_map(fn ($violation) => [
                'key' => $violation->key, 'severity' => $violation->severity->value, 'rule' => $violation->rule, 'element' => $violation->element,
                'selector' => $violation->selector, 'params' => $violation->params, 'meta' => $violation->meta, 'tags' => $violation->tags,
            ], $violations);

            $this->assertSame($expected[basename($file)], json_decode((string) json_encode($actual), true), basename($file));
        }
    }

    public function test_state_of_a_document_whose_analysis_threw_does_not_leak_into_the_next(): void
    {
        $analyzer = new class extends ContrastAnalyzer
        {
            public bool $throws = true;

            protected function report(string $key, Severity $severity, ?string $rule, ?DOMElement $element = null, array $params = [], array $meta = [], array $related = [], array $tags = [], bool $autoFixable = false): void
            {
                if ($this->throws) {
                    $this->throws = false;

                    throw new RuntimeException('midway');
                }

                parent::report($key, $severity, $rule, $element, $params, $meta, $related, $tags, $autoFixable);
            }
        };

        try {
            $analyzer->analyze(HtmlDocument::fromHtml($this->page('', '<p style="color:#999;background-color:#fff">Low contrast</p>')));
            $this->fail('The first analysis should have thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('midway', $e->getMessage());
        }

        $this->assertSame([], $analyzer->analyze(HtmlDocument::fromHtml($this->page('', '<p style="color:#000;background-color:#fff">Fine</p>'))));
    }

    // C3: with a stylesheet the parser never saw, a failing estimate is a Warning tagged approximate, a passing one is
    // silent (no unknown colour, so no contrast.unverifiable), and one document Notice says why.
    public function test_missing_stylesheets_make_failures_approximate_and_passes_silent(): void
    {
        $html = '<html><head><link rel="stylesheet" href="/app.css"><style>@import url(theme.css); .muted{color:#999} .gone{display:none}</style></head><body>'
            .'<p class="muted">Muted</p><p>Plain</p><p class="gone muted">Hidden</p><p style="color:#aaa;background:#fff">Inline</p></body></html>';
        $violations = $this->analyze($html);

        $muted = $this->assertHasViolation($violations, 'contrast.insufficient', element: 'p.muted', severity: Severity::Warning);
        $this->assertSame(['approximate'], $muted->tags);
        $this->assertTrue($muted->meta['approximate']);
        $this->assertHasViolation($violations, 'contrast.insufficient', element: 'p', severity: Severity::Warning);
        $this->assertNoViolation($violations, 'contrast.unverifiable');
        $this->assertViolationCount($violations, 'contrast.insufficient', 2);

        $notice = $this->assertHasViolation($violations, 'contrast.stylesheets_missing', severity: Severity::Notice);
        $this->assertSame(['count' => 2], $notice->params);
        $this->assertNull($notice->element);
        $this->assertCount(3, $violations);
    }

    private const BOOTSTRAP_WARNING_TEXT = ':root{--bs-warning-rgb:255,193,7;--bs-body-bg:#fff;--bs-body-color:#212529}'
        .'body{margin:0;color:var(--bs-body-color);background-color:var(--bs-body-bg)}'
        .'.text-warning{--bs-text-opacity:1;color:rgba(var(--bs-warning-rgb),var(--bs-text-opacity))!important}';

    // Final review I1: a stylesheet of a web-font service (bfsg.fetch.font_stylesheet_hosts) holds @font-face rules
    // only; it does not make the cascade unknown, so a failing colour stays a definite Error.
    public function test_font_service_stylesheets_do_not_make_results_approximate(): void
    {
        $links = '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap" rel="stylesheet">'
            .'<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />'
            .'<link rel="stylesheet" href="//FONTS.BUNNY.NET/css?family=figtree:400">'
            .'<link rel="stylesheet" href="https://use.typekit.net/abc1def.css"><link rel="stylesheet" href="https://fonts.cdnfonts.com/css/inter">';
        $style = "@import url('https://fonts.googleapis.com/css2?family=Inter'); @import \"https://fonts.bunny.net/css?family=x\";".self::BOOTSTRAP_WARNING_TEXT;
        $violations = $this->analyze('<html><head>'.$links.'<style>'.$style.'</style></head><body><p class="text-warning">Careful</p></body></html>');

        $error = $this->assertHasViolation($violations, 'contrast.insufficient', element: 'p.text-warning', severity: Severity::Error);
        $this->assertSame('1.63', $error->params['ratio']);
        $this->assertSame([], $error->tags);
        $this->assertNoViolation($violations, 'contrast.stylesheets_missing');
        $this->assertCount(1, $violations);
    }

    // I1: only the exact hosts of the list; any other origin (a CDN, a look-alike host) is still a missing stylesheet.
    public function test_other_hosts_and_an_empty_font_host_list_keep_stylesheets_missing(): void
    {
        $page = fn (string $head): string => '<html><head>'.$head.'<style>'.self::BOOTSTRAP_WARNING_TEXT.'</style></head><body><p class="text-warning">Careful</p></body></html>';

        foreach ([
            '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">',
            '<link rel="stylesheet" href="https://fonts.googleapis.com.example.net/css2?family=Inter">',
            '<link rel="stylesheet" href="https://myfonts.bunny.net/css?family=x">',
            '<link rel="stylesheet" href="/fonts.googleapis.com/css2">',
            '<link rel="stylesheet" href="https://cdn.example.net\@fonts.bunny.net/css">',
            '<link rel="stylesheet" href="https://x@fonts.bunny.net/css">',
            '<link rel="stylesheet" href="ftp://fonts.bunny.net/css">',
            '<style>@import url(https://cdn.example.net/theme.css);</style>',
        ] as $head) {
            $violations = $this->analyze($page($head));

            $this->assertHasViolation($violations, 'contrast.insufficient', element: 'p.text-warning', severity: Severity::Warning);
            $this->assertHasViolation($violations, 'contrast.stylesheets_missing', severity: Severity::Notice);
        }

        config()->set('bfsg.fetch.font_stylesheet_hosts', []);
        $violations = $this->analyze($page('<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter">'));
        $this->assertHasViolation($violations, 'contrast.insufficient', element: 'p.text-warning', severity: Severity::Warning);
        $this->assertHasViolation($violations, 'contrast.stylesheets_missing', severity: Severity::Notice);
    }
}
