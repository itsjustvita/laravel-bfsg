<?php

namespace ItsJustVita\LaravelBfsg\Tests\Feature;

use DOMElement;
use ItsJustVita\LaravelBfsg\Analyzers\ContrastAnalyzer;
use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Css\CssParser;
use ItsJustVita\LaravelBfsg\Dom\HtmlDocument;
use ItsJustVita\LaravelBfsg\Severity;
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

    /** @return array<string, array{0: string, 1: int}> one of 3 900 universal rules (%1$d: its number) and the labels left visually hidden */
    public static function universalRules(): array
    {
        return [
            'custom-property rules' => ['*{--a%1$d:1}', 480],
            'maybe rules' => [':has(.q%1$d){--a%1$d:1}', 480],
            'custom-property rules with padding' => ['*{--a%1$d:1;padding:0}', 480],
            'maybe rules with padding' => [':has(.q%1$d){--a%1$d:1;padding:0}', 0], // may reveal anything: nothing is hidden
        ];
    }

    // C2 review: universal custom-property and maybe rules are folded once per index build, not once per element; with
    // `*{position:relative}` every element is positioned, so each one's own clipping is looked at.
    #[Group('performance')]
    #[DataProvider('universalRules')]
    public function test_visually_hidden_check_with_many_universal_rules_stays_in_budget(string $rule, int $hiddenLabels): void
    {
        $css = '*{position:relative}'.implode('', array_map(fn (int $i): string => sprintf($rule, $i), range(1, 3900)))
            .'.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip-path:inset(50%);white-space:nowrap;border-width:0}'
            .' .c{color:#aaa}';
        $sections = implode('', array_map(fn (int $i): string => "<section class=\"s$i\">".str_repeat('<div>', 30)
            .'<p class="c">x<span class="sr-only">label</span></p>'.str_repeat('</div>', 30).'</section>', range(1, 480)));
        $carrier = '<i class="'.implode(' ', array_map(fn (int $i): string => "q$i", range(1, 3900))).'"></i>'; // the maybe rules can match (R10)
        $document = HtmlDocument::fromHtml('<!DOCTYPE html><html lang="en"><head><title>Rules</title><style>'.$css.'</style></head><body>'.$sections.$carrier.'</body></html>');
        $texts = $document->query('//body//*[text()[normalize-space()]]');
        $parser = (new CssParser)->parse($document)->buildIndex();

        $start = microtime(true);
        $hidden = array_map(fn (DOMElement $element): bool => $parser->isVisuallyHidden($element), $texts);
        $elapsed = microtime(true) - $start;

        $this->assertSame($hiddenLabels, count(array_filter($hidden)), 'the labels are exempt, the paragraphs are not');
        $this->assertCount(960, $hidden);
        $this->assertLessThan(PerformanceBudget::seconds(0.5), $elapsed, sprintf('isVisuallyHidden() took %.2f s', $elapsed));

        $start = microtime(true);
        $violations = (new ContrastAnalyzer(level: 'AA'))->analyze($document);
        $elapsed = microtime(true) - $start;

        $this->assertSame('contrast.insufficient p.c', $violations[0]->key.' '.$violations[0]->element);
        $this->assertLessThan(PerformanceBudget::seconds(1.5), $elapsed, sprintf('contrast took %.2f s', $elapsed));

        if ($hiddenLabels > 0) {
            // Exact and (beyond the 64 positioned siblings looked at, a `backdrop` estimate) approximate findings.
            $this->assertSame(['contrast.insufficient p.c'], array_values(array_unique(array_map(fn (Violation $violation) => $violation->key.' '.$violation->element, $violations))));
            $this->assertSame(480, array_sum(array_map(fn (Violation $violation) => $violation->meta['occurrences'], $violations)), 'every paragraph is measured');
        }
    }

    /** @return array<string, array{0: string}> 20 000 layer names in one shape, then the rules that decide the colour of p.c */
    public static function layerNames(): array
    {
        $names = fn (string $prefix, string $glue): string => implode($glue, array_map(fn (int $i): string => $prefix.$i, range(1, 20000)));

        return [
            'ordering statement' => ['@layer '.$names('l', ', ').'; @layer l20000{.c{color:#aaa}} @layer l1{.c{color:#111}}'],
            'dotted name' => ['@layer s0.'.$names('s', '.').'{.c{color:#111}} @layer s0{.c{color:#aaa}}'],
            'anonymous layers' => [str_repeat('@layer{}', 20000).' @layer{.c{color:#111}} .c{color:#aaa}'],
            'nested blocks' => [str_repeat('@layer n{', 20000).'.c{color:#111}'.str_repeat('}', 20000).' .c{color:#aaa}'],
        ];
    }

    // C4: layer names are interned in one bounded map; beyond its cap a new name joins its parent's last sub-layer (at
    // the top level: the last layer) and the result is approximate (`limit`), never an Error. Layer blocks nested deeper
    // than the group-rule bound are dropped (truncated). In every shape the browser paints #aaa (2.32:1).
    #[Group('performance')]
    #[DataProvider('layerNames')]
    public function test_twenty_thousand_layer_names_stay_in_budget(string $css): void
    {
        $html = '<!DOCTYPE html><html lang="en"><head><title>Layers</title><style>'.$css.'</style></head><body><p class="c">x</p></body></html>';
        $this->assertLessThan(2 * 1024 * 1024, strlen($html));

        $start = microtime(true);
        $violations = (new ContrastAnalyzer(level: 'AA'))->analyze(HtmlDocument::fromHtml($html));
        $elapsed = microtime(true) - $start;

        $this->assertSame(['contrast.insufficient p.c'], array_map(fn (Violation $violation) => $violation->key.' '.$violation->element, $violations));
        $this->assertSame('2.32', $violations[0]->params['ratio']);
        $this->assertSame([Severity::Warning, ['approximate']], [$violations[0]->severity, $violations[0]->tags]);
        $this->assertLessThan(PerformanceBudget::seconds(1.0), $elapsed, sprintf('contrast took %.2f s', $elapsed));
    }

    // C2 review: universal clipping-only rules get no per-element index entries, so they leave the index deadline to the
    // colour rules (the same page with `margin` instead of `padding` takes about 0.2 s).
    #[Group('performance')]
    public function test_universal_clipping_only_rules_stay_in_budget(): void
    {
        $css = implode('', array_map(fn (int $i): string => "*{padding:{$i}px}", range(1, 1900))).' .c{color:#aaa}';
        $body = str_repeat('<div><p class="c">x</p><span>y</span></div>', 2500); // 7 500 elements
        $document = HtmlDocument::fromHtml('<!DOCTYPE html><html lang="en"><head><title>Padding</title><style>'.$css.'</style></head><body>'.$body.'</body></html>');

        $start = microtime(true);
        $violations = (new ContrastAnalyzer(level: 'AA'))->analyze($document);
        $elapsed = microtime(true) - $start;

        $this->assertSame(['contrast.insufficient p.c'], array_map(fn (Violation $violation) => $violation->key.' '.$violation->element, $violations));
        $this->assertSame(2500, $violations[0]->meta['occurrences']);
        $this->assertSame([], $violations[0]->tags, 'the index is not truncated');
        $this->assertLessThan(PerformanceBudget::seconds(1.0), $elapsed, sprintf('contrast took %.2f s', $elapsed));
    }

    /** @return array<string, array{0: int, 1: bool, 2?: bool}> universal unsupported colour rules, whether they exceed MAX_INDEXED_RULES and whether their classes are present */
    public static function unsupportedColourRules(): array
    {
        return [
            '20 000 rules (beyond the rule budget)' => [20000, true],
            '1 990 rules (all indexed)' => [1990, false],
            '20 000 rules naming absent classes' => [20000, true, false],
            '1 990 rules naming absent classes' => [1990, false, false],
        ];
    }

    // C1: universal maybe rules (`:has(.qN)` reduces to nothing) are folded once per index build, so 3 000 elements with
    // distinct chains cost one look-up per property each, not one per rule. Beyond MAX_INDEXED_RULES the result is
    // `limit`; within it the maybe rules make the colour uncertain, so #aaa on white (2.32:1) is a Warning. Rules naming
    // classes absent from the document are dropped after one scan each (R10): within the budget the Error is definite.
    #[Group('performance')]
    #[DataProvider('unsupportedColourRules')]
    public function test_twenty_thousand_unsupported_colour_rules_stay_in_budget(int $rules, bool $truncated, bool $present = true): void
    {
        $html = self::unsupportedColourPage($rules, $present);
        $this->assertLessThan(2 * 1024 * 1024, strlen($html));
        $document = HtmlDocument::fromHtml($html);
        $texts = $document->query('//p');
        $parser = (new CssParser)->parse($document)->buildIndex();

        $start = microtime(true);
        $uncertainty = array_unique(array_map(fn (DOMElement $element): string => implode(',', $parser->resolveColors($element)['uncertainty']), $texts));
        $elapsed = microtime(true) - $start;

        $this->assertCount(3000, $texts);
        // Rules naming absent classes cannot match (R10): they are dropped, the colour is exact.
        $this->assertSame([implode(',', array_merge($present ? ['colour'] : [], $truncated ? ['limit'] : []))], array_values($uncertainty));
        $this->assertLessThan(PerformanceBudget::seconds(1.0), $elapsed, sprintf('resolveColors() took %.2f s', $elapsed));

        $start = microtime(true);
        $violations = (new ContrastAnalyzer(level: 'AA'))->analyze($document);
        $elapsed = microtime(true) - $start;

        $findings = array_values(array_filter($violations, fn (Violation $violation) => $violation->key === 'contrast.insufficient'));
        $this->assertSame([$present || $truncated ? Severity::Warning : Severity::Error], array_values(array_unique(array_map(fn (Violation $violation) => $violation->severity, $findings), SORT_REGULAR)));
        $this->assertSame('2.32', $findings[0]->params['ratio']);
        $this->assertLessThan(PerformanceBudget::seconds(1.5), $elapsed, sprintf('contrast took %.2f s', $elapsed));
    }

    /**
     * `.c{color:#aaa}`, then $rules universal unsupported colour rules (`:has(.qN){color:…}`), and 3 000 paragraphs in
     * sections with distinct classes; with $present an element carries every class qN, so the rules can match (R10).
     */
    public static function unsupportedColourPage(int $rules, bool $present = true): string
    {
        $css = '.c{color:#aaa} '.implode('', array_map(fn (int $i): string => sprintf(':has(.q%d){color:#%06x}', $i, $i), range(1, $rules)));
        $body = implode('', array_map(fn (int $i): string => "<section class=\"s$i\"><p class=\"c\">x</p></section>", range(1, 3000)))
            .($present ? '<i class="'.implode(' ', array_map(fn (int $i): string => "q$i", range(1, $rules))).'"></i>' : '');

        return '<!DOCTYPE html><html lang="en"><head><title>Rules</title><style>'.$css.'</style></head><body>'.$body.'</body></html>';
    }

    /** @return array<string, array{0: string, 1: Severity}> R10: one hostile unsupported selector (about 100–200 KB) and the verdict on p.c */
    public static function hostilePrefilterSelectors(): array
    {
        return [
            '20 000 nested :is() (beyond the nesting cap: may match)' => [str_repeat(':is(', 20000).'.absent'.str_repeat(')', 20000), Severity::Warning],
            '20 000 :not() before an absent class' => [str_repeat(':not(.n)', 20000).'.absent', Severity::Error],
            ':where() with 20 000 absent arguments' => [':where('.implode(',', array_map(fn (int $i): string => ".a$i", range(1, 20000))).')', Severity::Error],
            '20 000 unclosed :has(' => [str_repeat(':has(', 20000).'.absent', Severity::Warning],
        ];
    }

    /** @return array<string, array{0: string, 1: Severity}> C5: one hostile @supports prelude (about 1 MB) around `p.c{color:#111}` and the verdict on p.c (#aaa) */
    public static function hostileSupportsConditions(): array
    {
        return [
            '1 MB condition (an and-chain ending in a vendor test)' => [str_repeat('(display: grid) and ', 52000).'(-webkit-x: y)', Severity::Warning],
            '1 MB declaration value' => ['(color: '.str_repeat('a', 1024 * 1024).')', Severity::Warning],
            '10 000 nested parentheses' => [str_repeat('(', 10000).'display: grid'.str_repeat(')', 10000), Severity::Warning],
            '10 000 nested nots' => [str_repeat('not (', 10000).'display: grid'.str_repeat(')', 10000), Severity::Warning],
            '1 MB false condition' => [str_repeat('(display: grid) and ', 52000).'(not (display: flex))', Severity::Error],
        ];
    }

    // C5: the @supports prelude is evaluated in one pass with nesting capped at 32 levels; beyond it the block is undecidable.
    #[Group('performance')]
    #[DataProvider('hostileSupportsConditions')]
    public function test_hostile_supports_conditions_stay_in_budget(string $condition, Severity $severity): void
    {
        $html = '<!DOCTYPE html><html lang="en"><head><title>Supports</title><style>.c{color:#aaa} @supports '.$condition.'{p.c{color:#111}}</style></head><body>'
            .str_repeat('<div><p class="c">x</p></div>', 3000).'</body></html>';
        $this->assertLessThan(2 * 1024 * 1024, strlen($html));

        $start = microtime(true);
        $violations = (new ContrastAnalyzer(level: 'AA'))->analyze(HtmlDocument::fromHtml($html));
        $elapsed = microtime(true) - $start;

        $this->assertSame([['contrast.insufficient p.c', $severity]], array_map(fn (Violation $violation) => [$violation->key.' '.$violation->element, $violation->severity], $violations));
        $this->assertLessThan(PerformanceBudget::seconds(1.0), $elapsed, sprintf('contrast took %.2f s', $elapsed));
    }

    /** @return array<string, array{0: int, 1: bool, 2: bool, 3: list<string>}> I2: rules, inside an undecidable @supports, an element with data-x1, and the findings */
    public static function attributeRules(): array
    {
        return [
            '20 000 rules naming absent attributes' => [20000, false, false, []],
            '20 000 such rules behind an undecidable @supports' => [20000, true, false, []],
            '20 000 such rules, one attribute present' => [20000, true, true, ['contrast.unverifiable']],
            '1 990 rules naming absent attributes' => [1990, false, false, []],
        ];
    }

    // I2: the attribute names of the document are collected once (under MAX_DOCUMENT_TOKENS), each selector scanned once.
    #[Group('performance')]
    #[DataProvider('attributeRules')]
    public function test_twenty_thousand_attribute_rules_stay_in_budget(int $rules, bool $undecided, bool $present, array $findings): void
    {
        $css = implode('', array_map(fn (int $i): string => '[data-x'.base_convert((string) $i, 10, 36).']+p{opacity:.5}', range(1, $rules)));
        $css = '.c{color:#111} '.($undecided ? '@supports (-webkit-x:y){'.$css.'}' : $css);
        $html = '<!DOCTYPE html><html lang="en"><head><title>Attributes</title><style>'.$css.'</style></head><body>'
            .($present ? '<i data-x1></i>' : '').str_repeat('<p class="c" data-y>x</p>', 3000).'</body></html>';
        $this->assertLessThan(600 * 1024, strlen($html));

        $start = microtime(true);
        $violations = (new ContrastAnalyzer(level: 'AA'))->analyze(HtmlDocument::fromHtml($html));
        $elapsed = microtime(true) - $start;

        $this->assertSame($findings, array_values(array_unique(array_map(fn (Violation $violation) => $violation->key, $violations))));
        $this->assertLessThan(PerformanceBudget::seconds(1.5), $elapsed, sprintf('contrast took %.2f s', $elapsed));
    }

    // R10: the existence prefilter scans each selector once per nesting level (at most MAX_MATCH_NESTING), linear.
    #[Group('performance')]
    #[DataProvider('hostilePrefilterSelectors')]
    public function test_existence_prefilter_is_linear(string $selector, Severity $severity): void
    {
        $html = '<!DOCTYPE html><html lang="en"><head><title>Prefilter</title><style>.c{color:#aaa} '.$selector.'{color:#111}</style></head><body>'
            .str_repeat('<div><p class="c">x</p></div>', 3000).'</body></html>';
        $this->assertLessThan(2 * 1024 * 1024, strlen($html));

        $start = microtime(true);
        $violations = (new ContrastAnalyzer(level: 'AA'))->analyze(HtmlDocument::fromHtml($html));
        $elapsed = microtime(true) - $start;

        $this->assertSame([['contrast.insufficient p.c', $severity]], array_map(fn (Violation $violation) => [$violation->key.' '.$violation->element, $violation->severity], $violations));
        $this->assertLessThan(PerformanceBudget::seconds(1.0), $elapsed, sprintf('contrast took %.2f s', $elapsed));
    }

    // C9: the attribute-value scan is one pass; unclosed brackets, nested quotes and long values do not backtrack.
    #[Group('performance')]
    public function test_specificity_of_hostile_attribute_selectors_stays_in_budget(): void
    {
        $parser = new CssParser;
        $long = str_repeat('a.b#c"\'[', 512);
        $selectors = [];

        for ($i = 0; $i < 20000; $i++) {
            $selectors[] = match ($i % 4) {
                0 => 'a[href="x'.$i.'.y#z',
                1 => '[a="\'[[[""\'[b="'.str_repeat('[', 40),
                2 => '[data-x="'.$long.'"].k'.$i,
                default => str_repeat('[a', 30).'.b#c',
            };
        }

        $start = microtime(true);

        foreach ($selectors as $selector) {
            $parser->calculateSpecificity($selector);
        }

        $elapsed = microtime(true) - $start;

        $this->assertLessThan(PerformanceBudget::seconds(1.0), $elapsed, sprintf('20 000 selectors took %.2f s', $elapsed));
    }
}
