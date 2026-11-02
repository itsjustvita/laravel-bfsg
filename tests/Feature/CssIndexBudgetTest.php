<?php

namespace ItsJustVita\LaravelBfsg\Tests\Feature;

use ItsJustVita\LaravelBfsg\Analyzers\ContrastAnalyzer;
use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Css\CssParser;
use ItsJustVita\LaravelBfsg\Dom\HtmlDocument;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\Support\PerformanceBudget;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

class CssIndexBudgetTest extends TestCase
{
    /** ~3000 elements and 2000 simple class rules, plus aria-hidden content and a focus reset. */
    private function largePage(): string
    {
        $css = '';

        for ($i = 0; $i < 2000; $i++) {
            $css .= sprintf('.c%d { color: #%06x } ', $i, ($i * 97) % 0x555555);
        }

        $css .= '.btn:focus { outline: none } .closed { display: none }';
        $body = '<main><div aria-hidden="true" class="closed"><button class="btn">Hidden</button></div>';

        for ($i = 0; $i < 1000; $i++) {
            $body .= sprintf('<div class="c%d"><p class="c%d">Text %d</p><span class="c%d">More</span></div>', $i, ($i + 1000) % 2000, $i, ($i * 7) % 2000);
        }

        return '<html lang="en"><head><title>Large</title><style>'.$css.'</style></head><body>'.$body.'<button class="btn">Go</button></main></body></html>';
    }

    #[Group('performance')]
    public function test_large_page_is_analyzed_within_the_time_budget(): void
    {
        $html = $this->largePage();

        $start = microtime(true);
        app(Bfsg::class)->analyze($html);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(PerformanceBudget::seconds(3.0), $elapsed, sprintf('analyze took %.2f s', $elapsed));
    }

    public function test_the_css_index_is_built_once_per_analyze(): void
    {
        $document = HtmlDocument::fromHtml($this->largePage());

        app(Bfsg::class)->analyzeDocument($document);

        $this->assertSame(1, $document->cssParser()->indexBuilds());
    }

    public function test_an_injected_parser_wins_over_the_document_parser(): void
    {
        $document = HtmlDocument::fromHtml('<html><head><style>.a { color: #ccc }</style></head><body><p class="a">x</p></body></html>');
        $injected = new CssParser;

        $violations = (new ContrastAnalyzer($injected))->analyze($document);

        $this->assertSame(['contrast.insufficient'], array_map(fn ($v) => $v->key, $violations));
        $this->assertSame(1, $injected->indexBuilds());
        $this->assertSame(0, $document->cssParser()->indexBuilds());
    }

    private function descendantPage(): HtmlDocument
    {
        $css = '';

        for ($i = 0; $i < 2000; $i++) {
            $css .= sprintf('.w%d .t%d { color: #%06x } ', $i % 400, $i, ($i * 97) % 0x555555);
        }

        $body = '';

        for ($i = 0; $i < 1000; $i++) {
            $body .= sprintf('<div class="w%d"><p class="t%d">Text %d</p><span class="t%d">More</span></div>', $i % 400, $i, $i, ($i * 7) % 2000);
        }

        return HtmlDocument::fromHtml('<html lang="en"><head><title>Large</title><style>'.$css.'</style></head><body><main>'.$body.'</main></body></html>');
    }

    public function test_descendant_selectors_are_bucketed_by_their_rightmost_compound(): void
    {
        $document = $this->descendantPage();
        $document->cssParser()->buildIndex();

        $paragraph = $document->query('//div[@class="w5"]/p[@class="t5"]')[0];
        $this->assertSame('#0001e5', $document->cssParser()->declarationsFor($paragraph)['color']['value']);
    }

    /**
     * Tailwind-shaped page: a universal --tw-* reset, 250 root tokens, 2000 colour utilities through a custom property,
     * 3000 rules that only declare custom properties, and ~3000 elements reading them.
     */
    private function customPropertyPage(): string
    {
        $css = '*, ::before, ::after {'.implode('', array_map(fn (int $i) => "--tw-p$i: initial;", range(1, 40))).'}';
        $css .= ':root {'.implode('', array_map(fn (int $i) => sprintf('--color-%d: #%06x;', $i, $i * 4111), range(1, 250))).'}';

        for ($i = 0; $i < 2000; $i++) {
            $css .= sprintf('.c%d { --tw-text-opacity: 1; color: rgb(%d %d %d / var(--tw-text-opacity)) } ', $i, $i % 200, $i % 150, $i % 100);
        }

        for ($i = 0; $i < 3000; $i++) {
            $css .= sprintf('.v%d { --v%d: var(--color-%d) } ', $i, $i, $i % 250 + 1);
        }

        $body = '';

        for ($i = 0; $i < 1000; $i++) {
            $body .= sprintf('<div class="v%d"><p class="c%d">Text %d</p><span style="color: var(--v%d)">More</span></div>', $i, ($i + 1000) % 2000, $i, $i);
        }

        return '<html lang="en"><head><title>Large</title><style>'.$css.'</style></head><body><main>'.$body.'</main></body></html>';
    }

    public function test_custom_property_rules_have_their_own_budget(): void
    {
        $document = HtmlDocument::fromHtml($this->customPropertyPage());
        $parser = $document->cssParser()->buildIndex();
        $span = $document->query('//div[@class="v7"]/span')[0];

        $this->assertFalse($parser->isTruncated(), 'custom-property rules do not count against MAX_INDEXED_RULES');
        $this->assertSame('#008078', $parser->resolveColors($span)['foreground']->toHex(), 'var(--v7) → var(--color-8) inherited from the card');
        $this->assertSame('#0e720e', $parser->resolveColors($document->query('//p[@class="c1014"]')[0])['foreground']->toHex());
    }

    #[Group('performance')]
    public function test_custom_property_page_is_analyzed_within_the_time_budget(): void
    {
        $html = $this->customPropertyPage();

        $start = microtime(true);
        app(Bfsg::class)->analyze($html);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(PerformanceBudget::seconds(3.0), $elapsed, sprintf('analyze took %.2f s', $elapsed));
    }

    /** @return array<string, array{0: string}> hostile shapes: many references, an inherit chain, long cached values, long node paths */
    public static function hostileCustomPropertyPages(): array
    {
        $page = fn (string $css): string => '<!doctype html><html lang="en"><head><title>x</title><style>'.$css.'</style></head><body><main>'
            .str_repeat('<p>Some text</p>', 500).'</main></body></html>';
        $declared = $references = $undefined = '';

        for ($i = 0; $i < 4000; $i++) {
            $declared .= "--u$i:a;";
            $references .= "var(--u$i)";
        }

        for ($i = 0; $i < 60000; $i++) {
            $undefined .= "var(--u$i,)";
        }

        $chain = '<!doctype html><html lang="en"><head><title>x</title><style>*{--x:#777} div,p{--x:inherit} p{color:#767676 '.str_repeat('var(--x) ', 64).'}</style></head><body>'
            .str_repeat('<div>', 200).str_repeat('<p>Some text</p>', 300).str_repeat('</div>', 200).'</body></html>';

        // Values just under 4 KB cached per element: a universal rule whose names chain to a 4085-character token, read through invalid names.
        $values = '--a:'.str_repeat('A', 4085).';';
        $invalid = '';

        for ($i = 0; $i < 64; $i++) {
            $previous = '--a';

            for ($step = 7; $step >= 1; $step--) {
                $values .= "--b{$i}_$step:x var($previous);";
                $previous = "--b{$i}_$step";
            }

            $values .= "--d$i:var($previous) var(--nope);";
            $invalid .= " var(--d$i,)";
        }

        // 12.6 KB node paths: 200 nested owners with 62-character tag names, four paragraphs reading 64 root names each.
        $tag = 'x-'.str_repeat('a', 60);
        $root = $classes = $paragraphs = '';

        for ($c = 0; $c < 4; $c++) {
            $names = '';

            for ($i = 0; $i < 64; $i++) {
                $root .= '--u'.($c * 64 + $i).':a;';
                $names .= ' var(--u'.($c * 64 + $i).')';
            }

            $classes .= ".c$c{color:#767676$names}";
            $paragraphs .= "<p class=\"c$c\">Some text</p>";
        }

        $paths = '<!doctype html><html lang="en"><head><title>x</title><style>:root{'.$root."} $tag{--y:1} $classes</style></head><body>"
            .str_repeat("<$tag>", 200).$paragraphs.str_repeat("</$tag>", 200).'</body></html>';

        return [
            'declared on the root' => [$page(":root{{$declared}} p{color:#767676$references}")],
            'undefined with empty fallbacks' => [$page("p{color:#767676$undefined}")],
            'inherit through 200 nested elements' => [$chain],
            'long values cached per element' => [str_replace(str_repeat('<p>Some text</p>', 500), str_repeat('<p>Some text</p>', 200), $page("*{{$values}} p{color:#767676$invalid}"))],
            'long node paths' => [$paths],
        ];
    }

    #[DataProvider('hostileCustomPropertyPages')]
    public function test_hostile_custom_properties_stay_bounded(string $html): void
    {
        $document = HtmlDocument::fromHtml($html);
        memory_reset_peak_usage();
        $before = memory_get_usage(true);

        $violations = (new ContrastAnalyzer)->analyze($document);

        $this->assertLessThan(64 * 1048576, memory_get_peak_usage(true) - $before, sprintf('peak grew by %.0f MB', (memory_get_peak_usage(true) - $before) / 1048576));
        $errors = array_filter($violations, fn ($violation) => $violation->severity === Severity::Error);
        $this->assertSame([], $errors, 'colour values with too many var() references are unresolvable, never a definite Error');
    }

    #[Group('performance')]
    #[DataProvider('hostileCustomPropertyPages')]
    public function test_hostile_custom_properties_are_analyzed_within_the_time_budget(string $html): void
    {
        $document = HtmlDocument::fromHtml($html);

        $start = microtime(true);
        (new ContrastAnalyzer)->analyze($document);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(PerformanceBudget::seconds(3.0), $elapsed, sprintf('contrast took %.2f s', $elapsed));
    }

    /** 4000 custom-property rules on the type selector `p` and 3000 paragraphs reading 64 of the names: 12M possible index entries. */
    private function typeFanOutPage(string $extra = ''): string
    {
        $css = '';
        $references = '';

        for ($i = 0; $i < 4000; $i++) {
            $css .= "p{--z$i:1}";
        }

        for ($i = 0; $i < 64; $i++) {
            $references .= " var(--z$i)";
        }

        return '<!doctype html><html lang="en"><head><title>x</title><style>'.$css.":root{--q:1} p{color:#767676$references;background:#fff$references}</style></head><body><main>"
            .str_repeat('<p>Some text</p>', 3000).'</main>'.$extra.'</body></html>';
    }

    public function test_custom_property_index_entries_are_capped(): void
    {
        $document = HtmlDocument::fromHtml($this->typeFanOutPage());

        $violations = (new ContrastAnalyzer)->analyze($document);

        $truncated = array_values(array_filter($violations, fn ($violation) => $violation->key === 'contrast.analysis_truncated'));
        $this->assertGreaterThan(0, $truncated[0]->params['limit'] ?? ContrastAnalyzer::MAX_MEASURED, 'the index build leaves time to measure');
        $this->assertTrue($document->cssParser()->isTruncated(), 'rules beyond the entry cap are skipped');
    }

    public function test_an_index_build_without_a_deadline_stays_bounded(): void
    {
        $html = $this->typeFanOutPage('<div aria-hidden="true"><a href="#">x</a></div>');
        memory_reset_peak_usage();
        $before = memory_get_usage(true);

        $result = app(Bfsg::class)->except(['contrast'])->analyze($html);

        $this->assertLessThan(64 * 1048576, memory_get_peak_usage(true) - $before, sprintf('peak grew by %.0f MB', (memory_get_peak_usage(true) - $before) / 1048576));
        $this->assertContains('aria.hidden_focusable', array_map(fn ($violation) => $violation->key, $result->forAnalyzer('aria')), 'the aria-hidden link is still reported');
    }

    #[Group('performance')]
    public function test_an_index_build_without_a_deadline_finishes_within_the_time_budget(): void
    {
        $html = $this->typeFanOutPage('<div aria-hidden="true"><a href="#">x</a></div>');

        $start = microtime(true);
        app(Bfsg::class)->except(['contrast'])->analyze($html);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(PerformanceBudget::seconds(6.0), $elapsed, sprintf('analyze took %.2f s', $elapsed));
    }

    #[Group('performance')]
    public function test_wide_custom_property_fan_out_is_analyzed_within_the_time_budget(): void
    {
        $css = '';
        $references = '';

        for ($i = 0; $i < 4000; $i++) {
            $css .= "*{--z$i:1}";
        }

        for ($i = 0; $i < 64; $i++) {
            $references .= " var(--z$i)";
        }

        $document = HtmlDocument::fromHtml('<!doctype html><html lang="en"><head><title>x</title><style>'.$css.":root{--q:1} p{color:#767676$references;background:#fff$references}</style></head><body><main>"
            .str_repeat('<p>Some text</p>', 3000).'</main></body></html>');

        $start = microtime(true);
        (new ContrastAnalyzer)->analyze($document);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(PerformanceBudget::seconds(2.0), $elapsed, sprintf('contrast took %.2f s', $elapsed));
    }

    #[Group('performance')]
    public function test_descendant_selector_index_is_built_within_the_time_budget(): void
    {
        $document = $this->descendantPage();

        $start = microtime(true);
        $document->cssParser()->buildIndex();
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(PerformanceBudget::seconds(1.0), $elapsed, sprintf('index build took %.2f s', $elapsed));
    }
}
