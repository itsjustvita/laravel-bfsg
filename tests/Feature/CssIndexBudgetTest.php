<?php

namespace ItsJustVita\LaravelBfsg\Tests\Feature;

use ItsJustVita\LaravelBfsg\Analyzers\ContrastAnalyzer;
use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Css\CssParser;
use ItsJustVita\LaravelBfsg\Dom\HtmlDocument;
use ItsJustVita\LaravelBfsg\Tests\Support\PerformanceBudget;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
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
