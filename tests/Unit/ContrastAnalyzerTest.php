<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use DOMElement;
use ItsJustVita\LaravelBfsg\Analyzers\ContrastAnalyzer;
use ItsJustVita\LaravelBfsg\Contracts\Analyzer;
use ItsJustVita\LaravelBfsg\Css\CssParser;
use ItsJustVita\LaravelBfsg\Dom\HtmlDocument;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\Support\AnalyzerTestCase;
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
}
