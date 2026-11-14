<?php

namespace ItsJustVita\LaravelBfsg\Tests\Feature;

use DOMElement;
use ItsJustVita\LaravelBfsg\Analyzers\ContrastAnalyzer;
use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Css\CssParser;
use ItsJustVita\LaravelBfsg\Dom\HtmlDocument;
use ItsJustVita\LaravelBfsg\Tests\Support\BenchmarkPage;
use ItsJustVita\LaravelBfsg\Tests\Support\PerformanceBudget;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use ItsJustVita\LaravelBfsg\Violation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** Spec C7 and the §2 performance anti-goal on the AN4 benchmark page. */
class ContrastPerformanceTest extends TestCase
{
    /** 3.0 on the AN4 benchmark, from the spec (§2): contrast and all analyzers, in seconds (ledger ruling R39). */
    private const BASELINE_CONTRAST_SECONDS = 1.6;

    private const BASELINE_TOTAL_SECONDS = 7.7;

    #[Group('performance')]
    public function test_benchmark_page_within_budget(): void
    {
        $html = BenchmarkPage::cards();
        $document = HtmlDocument::fromHtml($html);

        $start = microtime(true);
        $violations = (new ContrastAnalyzer(level: 'AA'))->analyze($document);
        $contrast = microtime(true) - $start;

        $start = microtime(true);
        (new Bfsg)->analyze($html);
        $total = microtime(true) - $start;

        // The whole page is measured: every card's date in one finding, and the footer note after them.
        $keys = array_map(fn (Violation $violation) => $violation->key.' '.$violation->element, $violations);
        $this->assertSame(['contrast.insufficient p.date', 'contrast.insufficient p.note'], $keys);
        $this->assertSame(3000, $violations[0]->meta['occurrences']);

        $this->assertLessThan(PerformanceBudget::seconds(1.2 * self::BASELINE_CONTRAST_SECONDS), $contrast, sprintf('contrast took %.2f s', $contrast));
        $this->assertLessThan(PerformanceBudget::seconds(1.2 * self::BASELINE_TOTAL_SECONDS), $total, sprintf('all analyzers took %.2f s', $total));
    }

    /** @return array<string, array{0: string}> the item of a 40 000-item flat list before a positioned image (review I1) */
    public static function flatListItems(): array
    {
        return ['exempt text' => ['<p hidden>x</p>'], 'measured text' => ['<p>x</p>']];
    }

    // The covering-sibling position is counted once per parent (not per item), and exempt text is bounded by the budget.
    #[Group('performance')]
    #[DataProvider('flatListItems')]
    public function test_flat_list_before_a_covering_sibling_within_budget(string $item): void
    {
        $html = '<!DOCTYPE html><html lang="en"><head><title>List</title></head><body><div>'.str_repeat($item, 40000)
            .'<img style="position:absolute" src="/a.jpg" alt=""></div></body></html>';
        $document = HtmlDocument::fromHtml($html);

        $start = microtime(true);
        $violations = (new ContrastAnalyzer(level: 'AA'))->analyze($document);
        $elapsed = microtime(true) - $start;

        $this->assertSame([], $violations, 'the whole list is measured and passes');
        $this->assertLessThan(PerformanceBudget::seconds(1.0), $elapsed, sprintf('took %.2f s', $elapsed));
    }

    /** @return array<string, array{0: string, 1: bool}> the class of the outermost div and whether all text below it is clipped */
    public static function deepDocumentRoots(): array
    {
        return ['clipped at the top' => ['sr-only', true], 'clipped nowhere' => ['', false]];
    }

    // C2: the clipping of the ancestors is memoised per element, so 5 000 text nodes below 2 000 levels (flattened to
    // HtmlDocument::MAX_DEPTH) cost one walk up the ancestors in all, not one per text element.
    #[Group('performance')]
    #[DataProvider('deepDocumentRoots')]
    public function test_visually_hidden_check_on_deep_ancestors_stays_in_budget(string $class, bool $hidden): void
    {
        $css = '.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip-path:inset(50%);white-space:nowrap;border-width:0}'
            .' .c{color:#767676}';
        $html = '<!DOCTYPE html><html lang="en"><head><title>Deep</title><style>'.$css.'</style></head><body><div class="'.$class.'">'
            .str_repeat('<div class="c">t', 2000).str_repeat('<span>t</span>', 3000).str_repeat('</div>', 2001).'</body></html>';
        $document = HtmlDocument::fromHtml($html);
        $texts = array_reverse($document->query('//body//*[text()[normalize-space()]]')); // deepest first: the longest walks
        $parser = (new CssParser)->parse($document)->buildIndex();

        $start = microtime(true);
        $results = array_unique(array_map(fn (DOMElement $element): bool => $parser->isVisuallyHidden($element), $texts));
        $elapsed = microtime(true) - $start;

        $this->assertSame([$hidden], array_values($results));
        $this->assertGreaterThan(3000, count($texts));
        $this->assertLessThan(PerformanceBudget::seconds(0.5), $elapsed, sprintf('isVisuallyHidden() took %.2f s', $elapsed));

        $start = microtime(true);
        $violations = (new ContrastAnalyzer(level: 'AA'))->analyze($document);
        $elapsed = microtime(true) - $start;

        $this->assertSame([], $violations, 'clipped text is exempt, the rest passes (#767676 is 4.54:1)');
        $this->assertLessThan(PerformanceBudget::seconds(1.5), $elapsed, sprintf('contrast took %.2f s', $elapsed));
    }

    /** @return array<string, array{0: string}> the selector of 3 900 universal rules that declare one custom property each (%d: its number) */
    public static function universalRuleSelectors(): array
    {
        return ['custom-property rules' => ['*'], 'maybe rules' => [':has(.q%d)']];
    }

    // C2 review: universal custom-property and maybe rules are looked at once per index build, not once per element.
    #[Group('performance')]
    #[DataProvider('universalRuleSelectors')]
    public function test_visually_hidden_check_with_many_universal_rules_stays_in_budget(string $selector): void
    {
        $css = implode('', array_map(fn (int $i): string => sprintf($selector, $i)."{--a$i:1}", range(1, 3900)))
            .'.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip-path:inset(50%);white-space:nowrap;border-width:0}'
            .' .c{color:#aaa}';
        $sections = implode('', array_map(fn (int $i): string => "<section class=\"s$i\">".str_repeat('<div>', 30)
            .'<p class="c">x<span class="sr-only">label</span></p>'.str_repeat('</div>', 30).'</section>', range(1, 480)));
        $document = HtmlDocument::fromHtml('<!DOCTYPE html><html lang="en"><head><title>Rules</title><style>'.$css.'</style></head><body>'.$sections.'</body></html>');
        $texts = $document->query('//body//*[text()[normalize-space()]]');
        $parser = (new CssParser)->parse($document)->buildIndex();

        $start = microtime(true);
        $hidden = array_map(fn (DOMElement $element): bool => $parser->isVisuallyHidden($element), $texts);
        $elapsed = microtime(true) - $start;

        $this->assertSame(480, count(array_filter($hidden)), 'every label is exempt, every paragraph is not');
        $this->assertCount(960, $hidden);
        $this->assertLessThan(PerformanceBudget::seconds(0.5), $elapsed, sprintf('isVisuallyHidden() took %.2f s', $elapsed));

        $start = microtime(true);
        $violations = (new ContrastAnalyzer(level: 'AA'))->analyze($document);
        $elapsed = microtime(true) - $start;

        $this->assertSame(['contrast.insufficient p.c'], array_map(fn (Violation $violation) => $violation->key.' '.$violation->element, $violations));
        $this->assertSame(480, $violations[0]->meta['occurrences']);
        $this->assertLessThan(PerformanceBudget::seconds(1.5), $elapsed, sprintf('contrast took %.2f s', $elapsed));
    }
}
