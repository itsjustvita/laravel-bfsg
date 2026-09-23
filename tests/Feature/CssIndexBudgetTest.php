<?php

namespace ItsJustVita\LaravelBfsg\Tests\Feature;

use ItsJustVita\LaravelBfsg\Analyzers\ContrastAnalyzer;
use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Css\CssParser;
use ItsJustVita\LaravelBfsg\Dom\HtmlDocument;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

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

    public function test_large_page_is_analyzed_within_the_time_guard(): void
    {
        $html = $this->largePage();

        $start = microtime(true);
        app(Bfsg::class)->analyze($html);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(3.0, $elapsed, sprintf('analyze took %.2f s', $elapsed));
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

    public function test_descendant_selectors_are_bucketed_by_their_rightmost_compound(): void
    {
        $css = '';

        for ($i = 0; $i < 2000; $i++) {
            $css .= sprintf('.w%d .t%d { color: #%06x } ', $i % 400, $i, ($i * 97) % 0x555555);
        }

        $body = '';

        for ($i = 0; $i < 1000; $i++) {
            $body .= sprintf('<div class="w%d"><p class="t%d">Text %d</p><span class="t%d">More</span></div>', $i % 400, $i, $i, ($i * 7) % 2000);
        }

        $document = HtmlDocument::fromHtml('<html lang="en"><head><title>Large</title><style>'.$css.'</style></head><body><main>'.$body.'</main></body></html>');

        $start = microtime(true);
        $document->cssParser()->buildIndex();
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(1.0, $elapsed, sprintf('index build took %.2f s', $elapsed));

        $paragraph = $document->query('//div[@class="w5"]/p[@class="t5"]')[0];
        $this->assertSame('#0001e5', $document->cssParser()->declarationsFor($paragraph)['color']['value']);
    }
}
