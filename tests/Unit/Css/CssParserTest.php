<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit\Css;

use DOMElement;
use ItsJustVita\LaravelBfsg\Css\CssParser;
use ItsJustVita\LaravelBfsg\Dom\HtmlDocument;
use ItsJustVita\LaravelBfsg\Tests\Support\PerformanceBudget;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

class CssParserTest extends TestCase
{
    private function selectors(string $css): array
    {
        return array_map(fn (array $rule) => $rule['selector'], (new CssParser)->parseStylesheet($css));
    }

    /** @return array{0: CssParser, 1: HtmlDocument} */
    private function parsed(string $css, string $body): array
    {
        $document = HtmlDocument::fromHtml('<html><head><style>'.$css.'</style></head><body>'.$body.'</body></html>');

        return [(new CssParser)->parse($document), $document];
    }

    private function target(HtmlDocument $document): DOMElement
    {
        return $document->query('//*[@id="t"]')[0];
    }

    /** @return array{0: string, 1: string, 2: bool} foreground hex, background hex, approximate */
    private function colors(string $css, string $body): array
    {
        [$parser, $document] = $this->parsed($css, $body);
        $colors = $parser->resolveColors($this->target($document));

        return [$colors['foreground']->toHex(), $colors['background']->toHex(), $colors['approximate']];
    }

    public function test_splits_selector_lists_and_strips_comments(): void
    {
        $this->assertSame(['h1', 'h2', 'p.lead'], $this->selectors('/* a { color: red } */ h1, h2 , p.lead { color: #000 } /* open'));
    }

    public function test_unwraps_layer_supports_and_container_blocks(): void
    {
        $css = '@layer base, utilities; @layer base { a { color: red } } '
            .'@supports (display: grid) { .grid { color: blue } } '
            .'@container card (min-width: 400px) { .card { color: green } }';

        $this->assertSame(['a', '.grid', '.card'], $this->selectors($css));
    }

    public function test_media_is_kept_for_screen_and_dropped_for_print(): void
    {
        $css = '@media print { .p { color: red } } @media screen and (min-width: 40em) { .s { color: red } } '
            .'@media (prefers-color-scheme: dark) { .d { color: red } } @media not screen { .n { color: red } }';

        $this->assertSame(['.s', '.d'], $this->selectors($css));
    }

    public function test_drops_statement_and_descriptor_at_rules(): void
    {
        $css = '@charset "utf-8"; @import url("x.css") screen; @font-face { font-family: X; src: url(x.woff) } '
            .'@keyframes spin { from { color: red } to { color: blue } } @page { margin: 1cm } '
            .'@property --x { syntax: "<color>"; inherits: false; initial-value: red } p { color: #111 }';

        $this->assertSame(['p'], $this->selectors($css));
    }

    public function test_properties_keep_important_and_do_not_split_inside_parentheses_or_quotes(): void
    {
        $properties = (new CssParser)->parseProperties('COLOR: Red !IMPORTANT; background: url("data:image/svg+xml;utf8,<svg>;</svg>") #fff; content: "a;b"; color: blue');

        $this->assertSame(['value' => 'Red', 'important' => true], $properties['color']);
        $this->assertSame('url("data:image/svg+xml;utf8,<svg>;</svg>") #fff', $properties['background']['value']);
        $this->assertSame('"a;b"', $properties['content']['value']);
    }

    public function test_specificity(): void
    {
        $parser = new CssParser;

        $this->assertSame([0, 0, 1], $parser->calculateSpecificity('p'));
        $this->assertSame([0, 1, 0], $parser->calculateSpecificity('.btn'));
        $this->assertSame([1, 0, 0], $parser->calculateSpecificity('#header'));
        $this->assertSame([0, 1, 1], $parser->calculateSpecificity('div.btn'));
        $this->assertSame([1, 1, 1], $parser->calculateSpecificity('#nav .item a'));
        $this->assertSame([0, 2, 1], $parser->calculateSpecificity('a[href]:not(.x)'));
        $this->assertSame([0, 0, 2], $parser->calculateSpecificity('p::before'));
    }

    public function test_selector_to_xpath_supports_the_documented_subset(): void
    {
        $document = HtmlDocument::fromHtml('<html><body><main><ul class="nav main"><li><a href="/de/kontakt" data-x="a b" id="k" lang="de-AT">K</a></li><li><a>L</a></li></ul></main></body></html>');
        $parser = new CssParser;
        $count = fn (string $selector) => count($document->query($parser->simpleSelectorToXpath($selector)));

        $this->assertSame(1, $count(':root'));
        $this->assertSame(1, $count('ul.nav.main > li > a#k'));
        $this->assertSame(2, $count('main a'));
        $this->assertSame(1, $count('a:link'));
        $this->assertSame(1, $count('li:first-child a'));
        $this->assertSame(1, $count('li:last-child > a'));
        $this->assertSame(1, $count('a:not([href])'));
        $this->assertSame(1, $count('[href^="/de"]'));
        $this->assertSame(1, $count('[href$=kontakt]'));
        $this->assertSame(1, $count("[href*='kont']"));
        $this->assertSame(1, $count('[data-x~="b"]'));
        $this->assertSame(1, $count('[lang|=de]'));
        $this->assertSame(1, $count('*[id="k"]'));
    }

    public function test_unsupported_selectors_are_null(): void
    {
        $parser = new CssParser;

        foreach (['a:hover', 'a:focus', 'li:nth-child(2)', 'p::before', 'h1 + p', 'h1 ~ p', 'a:not(:focus-visible)', '[href="x" i]', '', '> a'] as $selector) {
            $this->assertNull($parser->simpleSelectorToXpath($selector), $selector);
        }
    }

    public function test_escaped_class_names_are_supported(): void
    {
        [$parser, $document] = $this->parsed('.md\:text-gray-400 { color: #9ca3af }', '<p id="t" class="md:text-gray-400">x</p>');

        $this->assertSame('#9ca3af', $parser->resolveColors($this->target($document))['foreground']->toHex());
    }

    public function test_cascade_orders_by_important_then_specificity_then_source_order(): void
    {
        $this->assertSame('#0000ff', $this->colors('p { color: red } p { color: blue }', '<p id="t">x</p>')[0]);
        $this->assertSame('#ff0000', $this->colors('#t { color: red } p { color: blue }', '<p id="t">x</p>')[0]);
        $this->assertSame('#008000', $this->colors('p { color: green !important } #t { color: red }', '<p id="t">x</p>')[0]);
        $this->assertSame('#ff0000', $this->colors('p { color: blue }', '<p id="t" style="color: red">x</p>')[0]);
        $this->assertSame('#0000ff', $this->colors('p { color: blue !important }', '<p id="t" style="color: red">x</p>')[0]);
    }

    public function test_pseudo_class_and_pseudo_element_rules_do_not_apply(): void
    {
        $this->assertSame(['#000000', '#ffffff', false], $this->colors('p:hover { color: red } p::first-line { color: red } p:focus { background: black }', '<p id="t">x</p>'));
    }

    public function test_colour_syntaxes(): void
    {
        $this->assertSame('#ff6347', $this->colors('p { color: Tomato }', '<p id="t">x</p>')[0]);
        $this->assertSame('#ff0000', $this->colors('p { color: hsl(0 100% 50%) }', '<p id="t">x</p>')[0]);
        $this->assertSame('#808080', $this->colors('p { color: rgba(0, 0, 0, .5) }', '<p id="t">x</p>')[0]);
        $this->assertSame('#7f7f7f', $this->colors('p { color: #00000080 }', '<p id="t">x</p>')[0]);
    }

    public function test_background_inherits_through_transparent_and_composites_alpha(): void
    {
        $this->assertSame('#000080', $this->colors('div { background: navy } p { background-color: transparent }', '<div><p id="t">x</p></div>')[1]);
        $this->assertSame('#808080', $this->colors('div { background: #000 } p { background: rgba(255,255,255,.5) }', '<div><p id="t">x</p></div>')[1]);
    }

    public function test_unresolvable_values_walk_up_and_are_approximate(): void
    {
        $this->assertSame(['#333333', '#ffffff', true], $this->colors('body { color: #333 } p { color: var(--muted) }', '<p id="t">x</p>'));
        $this->assertSame(['#000000', '#eeeeee', true], $this->colors('body { background: #eee } p { background: var(--bg) }', '<p id="t">x</p>'));
        $this->assertSame(['#333333', '#ffffff', true], $this->colors('body { color: #333 } p { color: currentColor }', '<p id="t">x</p>'));
    }

    public function test_inherited_resolved_colours_are_exact(): void
    {
        $this->assertSame(['#dddddd', '#ffffff', false], $this->colors('.parent-light { color: #ddd }', '<div class="parent-light"><span id="t">x</span></div>'));
    }

    public function test_gradients_use_the_first_stop_and_are_approximate(): void
    {
        $this->assertSame(['#000000', '#112233', true], $this->colors('p { background: linear-gradient(to right, #123 0%, #fff 100%) }', '<p id="t">x</p>'));
    }

    public function test_url_is_stripped_before_scanning_the_shorthand(): void
    {
        $this->assertSame('#000000', $this->colors('p { background: url(red.png) no-repeat #000 }', '<p id="t">x</p>')[1]);
        $this->assertSame('#ffffff', $this->colors('p { background: url("data:image/svg+xml;utf8,<svg fill=\'red\'></svg>") }', '<p id="t">x</p>')[1]);
    }

    public function test_background_shorthand_versus_longhand_precedence(): void
    {
        $this->assertSame('#0000ff', $this->colors('p { background-color: red } p { background: blue }', '<p id="t">x</p>')[1]);
        $this->assertSame('#ff0000', $this->colors('p { background: blue } p { background-color: red }', '<p id="t">x</p>')[1]);
        $this->assertSame('#ff0000', $this->colors('#t { background-color: red } p { background: blue }', '<p id="t">x</p>')[1]);
    }

    public function test_inline_style_is_parsed_and_public(): void
    {
        $document = HtmlDocument::fromHtml('<html><body><a id="t" href="/" style="outline:none; BOX-SHADOW: 0 0 0 3px #005fcc">x</a></body></html>');

        $style = (new CssParser)->inlineStyle($this->target($document));

        $this->assertSame('none', $style['outline']['value']);
        $this->assertSame('0 0 0 3px #005fcc', $style['box-shadow']['value']);
        $this->assertSame('a{}', CssParser::stripComments('a/* x */{}'));
    }

    public function test_index_is_used_even_without_matching_rules(): void
    {
        [$parser, $document] = $this->parsed('', '<p id="t">x</p>');

        $this->assertFalse($parser->isTruncated());
        $this->assertSame(['#000000', '#ffffff', false], [
            $parser->resolveColors($this->target($document))['foreground']->toHex(),
            $parser->resolveColors($this->target($document))['background']->toHex(),
            $parser->resolveColors($this->target($document))['approximate'],
        ]);
        $this->assertTrue($parser->isIndexed());
    }

    public function test_parse_collects_rules_and_the_index_is_built_lazily_once(): void
    {
        [$parser, $document] = $this->parsed('.a { color: #123456 }', '<p id="t" class="a">x</p>');

        $this->assertCount(1, $parser->rules());
        $this->assertFalse($parser->isIndexed(), 'parse() only tokenizes');
        $this->assertSame(0, $parser->indexBuilds());

        $this->assertSame('#123456', $parser->declarationsFor($this->target($document))['color']['value']);
        $parser->resolveColors($this->target($document));
        $parser->hidesElement($this->target($document));

        $this->assertTrue($parser->isIndexed());
        $this->assertSame(1, $parser->indexBuilds());
    }

    public function test_lone_class_rules_match_escaped_and_repeated_class_tokens(): void
    {
        [$parser, $document] = $this->parsed('.md\\:muted { color: #123456 } .x { background-color: #fefefe }', "<p id=\"t\" class=\"x\tmd:muted md:muted\">x</p><p class=\"xx\" id=\"u\">y</p>");

        $this->assertSame(['#123456', '#fefefe', false], $this->colors('.md\\:muted { color: #123456 } .x { background-color: #fefefe }', "<p id=\"t\" class=\"x\tmd:muted md:muted\">x</p>"));
        $this->assertCount(2, $parser->declarationsFor($this->target($document)));
        $this->assertSame([], $parser->declarationsFor($document->elementsById()['u']));
    }

    public function test_index_build_stops_at_the_deadline_and_marks_results_approximate(): void
    {
        [$parser, $document] = $this->parsed('.a { color: #123456 }', '<p id="t" class="a">x</p>');

        $parser->buildIndex(microtime(true) - 1.0);

        $this->assertTrue($parser->isIndexed());
        $this->assertTrue($parser->isTruncated());
        $colors = $parser->resolveColors($this->target($document));
        $this->assertTrue($colors['approximate']);
        $this->assertSame('#000000', $colors['foreground']->toHex(), 'rules after the deadline are not applied');

        $parser->buildIndex();
        $this->assertSame(1, $parser->indexBuilds(), 'an existing index is not rebuilt');
    }

    public function test_overflowing_the_rule_index_marks_results_approximate(): void
    {
        $css = '';

        for ($i = 0; $i <= CssParser::MAX_INDEXED_RULES; $i++) {
            $css .= ".u{$i} { color: #111 } .n{$i} { margin: 0 } ";
        }

        [$parser, $document] = $this->parsed($css.'p { color: red }', '<p id="t" class="u1">x</p>');

        $this->assertTrue($parser->isTruncated());
        $colors = $parser->resolveColors($this->target($document));
        $this->assertTrue($colors['approximate']);
        $this->assertSame('#111111', $colors['foreground']->toHex(), 'rules beyond the cap are not applied');
    }

    public function test_font_size_and_weight(): void
    {
        [$parser, $document] = $this->parsed('.big { font-size: 1.5rem } .pt { font-size: 14pt; font-weight: 700 } .em { font-size: 2em }',
            '<h1 id="h">x</h1><p class="big" id="b">x</p><p class="pt" id="p">x</p><div class="em"><span class="em" id="e">x</span></div><h4 id="h4">x</h4>');
        $byId = $document->elementsById();

        $this->assertEqualsWithDelta(32.0, $parser->fontSizePx($byId['h']), 0.01);
        $this->assertEqualsWithDelta(24.0, $parser->fontSizePx($byId['b']), 0.01);
        $this->assertEqualsWithDelta(18.67, $parser->fontSizePx($byId['p']), 0.01);
        $this->assertEqualsWithDelta(64.0, $parser->fontSizePx($byId['e']), 0.01);
        $this->assertTrue($parser->isBold($byId['p']));
        $this->assertTrue($parser->isBold($byId['h4']));
        $this->assertFalse($parser->isBold($byId['b']));
    }

    /** Lower bound of the font size of #t (a p inside the body unless $body says otherwise). */
    private function lowerBound(string $css, string $body = '<p id="t">x</p>'): ?float
    {
        [$parser, $document] = $this->parsed($css, $body);

        return $parser->fontSizeLowerBoundPx($this->target($document));
    }

    /** @return array<string, array{0: string, 1: ?float}> */
    public static function fontSizeLowerBounds(): array
    {
        return [
            '16px' => ['16px', 16.0],
            '2rem' => ['2rem', 32.0],
            'clamp(2rem, 5vw, 3rem)' => ['clamp(2rem, 5vw, 3rem)', 32.0],
            'min(20px, 2rem)' => ['min(20px, 2rem)', 20.0],
            'max(1rem, 5vw)' => ['max(1rem, 5vw)', 16.0],
            'calc(1rem + 2px)' => ['calc(1rem + 2px)', 18.0],
            '150%' => ['150%', 24.0],
            'var(--x)' => ['var(--x)', 30.0],
            '5vw' => ['5vw', null],
            'calc(100vw - 2rem)' => ['calc(100vw - 2rem)', null],
        ];
    }

    #[DataProvider('fontSizeLowerBounds')]
    public function test_font_size_lower_bound(string $value, ?float $expected): void
    {
        $bound = $this->lowerBound(":root{--x:1.875rem} p{font-size:$value}");

        $expected === null ? $this->assertNull($bound) : $this->assertEqualsWithDelta($expected, $bound, 0.001);
    }

    /** @return array<string, array{0: string, 1: ?float}> */
    public static function fontSizeLowerBoundForms(): array
    {
        return [
            'absolute unit' => ['12pt', 16.0],
            'keyword' => ['x-large', 24.0],
            'relative keyword' => ['larger', 19.2],
            'inherit' => ['inherit', 16.0],
            'initial' => ['initial', 16.0],
            'em' => ['1.5em', 24.0],
            'scientific notation' => ['1.5e1px', 15.0],
            'uppercase' => ['CALC(1REM + 2PX)', 18.0],
            'product, number first' => ['calc(2 * 1rem)', 32.0],
            'product, number last' => ['calc(1rem * 1.5)', 24.0],
            'products bind tighter than sums' => ['calc(1rem + 2 * 3px)', 22.0],
            'parentheses and nested functions' => ['calc((1rem + 2px) * 2 + min(4px, 1em))', 40.0],
            'number products' => ['calc(2 * 3 * 1px)', 6.0],
            'a negative sum is clamped to zero' => ['calc(-2px)', 0.0],
            'clamp: the maximum caps the preferred value' => ['clamp(1vw, 30px, 10px)', 10.0],
            'clamp: the preferred value above the minimum' => ['clamp(10px, 20px, 30px)', 20.0],
            'clamp: unknown minimum' => ['clamp(1vw, 20px, 30px)', 20.0],
            'clamp: unknown minimum and maximum' => ['clamp(1vw, 20px, 5vw)', null],
            'min with an unknown argument' => ['min(20px, 5vw)', null],
            'max without a known argument' => ['max(5vw, 1vh)', null],
            'container and font-relative units' => ['calc(1cqi + 1ex)', null],
            'negative factor' => ['calc(-1 * 1rem)', null],
            'subtraction' => ['calc(2rem - 2px)', null],
            'division' => ['calc(2rem / 2)', null],
            'non-finite number' => ['1e999px', null],
            'overflowing product' => ['calc(1e300 * 1e300 * 1px)', null],
            'negative length' => ['-2px', null],
            'unsupported function' => ['round(up, 20px, 1px)', null],
            'operator without whitespace' => ['calc(1px +2px)', null],
            'length plus number' => ['calc(1px + 2)', null],
            'unclosed function' => ['calc(1px + 2px', null],
            'two values' => ['16px 2px', null],
            'parentheses outside a function' => ['(16px)', null],
            'undefined custom property' => ['var(--undefined)', null],
            'custom property fallback' => ['var(--undefined, 20px)', 20.0],
        ];
    }

    #[DataProvider('fontSizeLowerBoundForms')]
    public function test_font_size_lower_bound_forms(string $value, ?float $expected): void
    {
        $bound = $this->lowerBound("p{font-size:$value}");

        $expected === null ? $this->assertNull($bound) : $this->assertEqualsWithDelta($expected, $bound, 0.001);
    }

    public function test_font_size_lower_bound_follows_the_root_and_the_parent(): void
    {
        $this->assertEqualsWithDelta(20.0, $this->lowerBound('html{font-size:62.5%} p{font-size:2rem}'), 0.001);
        $this->assertEqualsWithDelta(32.0, $this->lowerBound('html{font-size:2rem} p{font-size:1rem}'), 0.001, 'rem on the root is relative to the initial size');
        $this->assertEqualsWithDelta(32.0, $this->lowerBound('html{font-size:2vw} p{font-size:2rem}'), 0.001, 'an unknown root size counts as 16px');

        $unknownParent = '<div class="a"><p id="t">x</p><h1 id="h">x</h1></div>';
        $this->assertNull($this->lowerBound('.a{font-size:5vw}', $unknownParent), 'inherits an unknown size');
        $this->assertNull($this->lowerBound('.a{font-size:5vw} p{font-size:2em}', $unknownParent));
        $this->assertNull($this->lowerBound('.a{font-size:5vw} p{font-size:50%}', $unknownParent));
        $this->assertEqualsWithDelta(16.0, $this->lowerBound('.a{font-size:5vw} p{font-size:1rem}', $unknownParent), 0.001);
        $this->assertEqualsWithDelta(32.0, $this->lowerBound('.a{font-size:1rem}', '<div class="a"><h1 id="t">x</h1></div>'), 0.001, 'the UA heading size');
        $this->assertNull($this->lowerBound('.a{font-size:5vw}', '<div class="a"><h1 id="t">x</h1></div>'));
    }

    public function test_font_size_px_falls_back_to_the_estimate_without_a_lower_bound(): void
    {
        [$parser, $document] = $this->parsed('.a{font-size:5vw} p{font-size:2em}', '<div class="a"><p id="t">x</p></div>');

        $this->assertNull($parser->fontSizeLowerBoundPx($this->target($document)));
        $this->assertEqualsWithDelta(32.0, $parser->fontSizePx($this->target($document)), 0.001);
    }

    public function test_font_shorthand_sets_size_and_weight(): void
    {
        $cases = [
            'p{font:italic bold 20px/1.5 serif}' => [20.0, true],
            'p{font:700 clamp(2rem, 5vw, 3rem)/1 sans-serif}' => [32.0, true],
            'p{font:small-caps 600 condensed 1.5rem / 2 "Open Sans", serif}' => [24.0, false],
            'p{font:oblique 10deg 30px serif}' => [30.0, false],
            ':root{--f:bold 30px serif} p{font:var(--f)}' => [30.0, true],
            'div{font:bold 30px serif} p{font:inherit}' => [30.0, true],
            'p{font-size:30px} p.a{font:20px serif}' => [20.0, false],
            'p.a{font-size:30px} p{font:bold 20px serif}' => [30.0, true],
            'p{font:menu}' => [null, false],
            'p{font:20px}' => [null, false],
        ];

        foreach ($cases as $css => [$size, $bold]) {
            [$parser, $document] = $this->parsed($css, '<div><p id="t" class="a">x</p></div>');
            $bound = $parser->fontSizeLowerBoundPx($this->target($document));

            $size === null ? $this->assertNull($bound, $css) : $this->assertEqualsWithDelta($size, $bound, 0.001, $css);
            $this->assertSame($bold, $parser->isBold($this->target($document)), $css);
        }

        [$parser, $document] = $this->parsed('h3{font:20px serif}', '<h3 id="t">x</h3>');
        $this->assertFalse($parser->isBold($this->target($document)), 'the shorthand resets the weight to normal');
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function fontWeights(): array
    {
        return [
            'inherit walks up instead of the UA bold' => ['h3{font-weight:inherit}', '<div><h3 id="t">x</h3></div>', false],
            'inherit takes a bold parent' => ['div{font-weight:700} h3{font-weight:inherit}', '<div><h3 id="t">x</h3></div>', true],
            'unset inherits' => ['div{font-weight:bold} p{font-weight:unset}', '<div><p id="t">x</p></div>', true],
            'missing inherits' => ['div{font-weight:bold}', '<div><p id="t">x</p></div>', true],
            'UA bold without a font-weight rule' => ['h3{color:#111}', '<h3 id="t">x</h3>', true],
            'a font-weight rule replaces the UA bold' => ['h3{font-weight:normal}', '<h3 id="t">x</h3>', false],
            'numeric below 700' => ['p{font-weight:650}', '<p id="t">x</p>', false],
            'numeric 700' => ['p{font-weight:700}', '<p id="t">x</p>', true],
            'custom property' => [':root{--font-weight-bold:700} p{font-weight:var(--font-weight-bold)}', '<p id="t">x</p>', true],
            'undefined custom property inherits' => ['div{font-weight:800} p{font-weight:var(--undefined)}', '<div><p id="t">x</p></div>', true],
            'invalid value is dropped' => ['p{font-weight:1001}', '<div><p id="t">x</p></div>', false],
            'bolder below 350' => ['div{font-weight:349} p{font-weight:bolder}', '<div><p id="t">x</p></div>', false],
            'bolder from 350' => ['div{font-weight:350} p{font-weight:bolder}', '<div><p id="t">x</p></div>', true],
            'bolder below 100' => ['div{font-weight:99} p{font-weight:bolder}', '<div><p id="t">x</p></div>', false],
            'bolder at 900 keeps it' => ['div{font-weight:950} p{font-weight:bolder}', '<div><p id="t">x</p></div>', true],
            'lighter below 750' => ['div{font-weight:749} p{font-weight:lighter}', '<div><p id="t">x</p></div>', false],
            'lighter from 750' => ['div{font-weight:750} p{font-weight:lighter}', '<div><p id="t">x</p></div>', true],
            'lighter from 900' => ['div{font-weight:900} p{font-weight:lighter}', '<div><p id="t">x</p></div>', true],
            'lighter twice from 900' => ['div{font-weight:900} p{font-weight:lighter} span{font-weight:lighter}', '<div><p><span id="t">x</span></p></div>', false],
            'bolder then lighter from 550' => ['div{font-weight:550} p{font-weight:bolder} span{font-weight:lighter}', '<div><p><span id="t">x</span></p></div>', true],
            'bolder twice from 100' => ['div{font-weight:100} p{font-weight:bolder} span{font-weight:bolder}', '<div><p><span id="t">x</span></p></div>', true],
            'lighter then bolder from 100' => ['div{font-weight:100} p{font-weight:lighter} span{font-weight:bolder}', '<div><p><span id="t">x</span></p></div>', false],
        ];
    }

    #[DataProvider('fontWeights')]
    public function test_font_weight(string $css, string $body, bool $bold): void
    {
        [$parser, $document] = $this->parsed($css, $body);

        $this->assertSame($bold, $parser->isBold($this->target($document)));
    }

    public function test_font_size_math_is_capped_in_depth_and_length(): void
    {
        $nested = fn (int $depth, string $open = 'calc(') => str_repeat($open, $depth).'1px'.str_repeat(')', $depth);
        $sum = fn (int $terms) => 'calc('.implode(' + ', array_fill(0, $terms, '1px')).')';

        $this->assertEqualsWithDelta(1.0, $this->lowerBound('p{font-size:'.$nested(32).'}'), 0.001, '32 levels are followed');
        $this->assertNull($this->lowerBound('p{font-size:'.$nested(33).'}'), 'deeper than 32 levels is unknown');
        $this->assertNull($this->lowerBound('p{font-size:calc('.$nested(2000, '(').')}'), 'deep parentheses within the length limit');
        $this->assertEqualsWithDelta(600.0, $this->lowerBound('p{font-size:'.$sum(600).'}'), 0.001);
        $this->assertNull($this->lowerBound('p{font-size:'.$sum(700).'}'), 'longer than MAX_RESOLVED_LENGTH is unknown');
    }

    /** @return array<string, array{0: string}> */
    public static function hostileFontSizes(): array
    {
        return ['calc() nested 10,000 deep' => ['nested'], '1 MB sum' => ['sum'], '1 MB sum in the font shorthand' => ['shorthand'], '2,000 nested parentheses' => ['parentheses']];
    }

    #[Group('performance')]
    #[DataProvider('hostileFontSizes')]
    public function test_hostile_font_sizes_resolve_within_the_time_budget(string $shape): void
    {
        $sum = 'calc(1px'.str_repeat(' + 1px', 174000).')'; // about 1 MB
        $declaration = match ($shape) {
            'nested' => 'font-size:'.str_repeat('calc(', 10000).'1px'.str_repeat(')', 10000),
            'sum' => 'font-size:'.$sum,
            'shorthand' => 'font:700 '.$sum.'/1 serif',
            'parentheses' => 'font-size:calc('.str_repeat('(', 2000).'1px'.str_repeat(')', 2000).')',
        };
        [$parser, $document] = $this->parsed("p{{$declaration}}", '<div><p id="t">x</p></div>');
        $parser->buildIndex();

        $start = microtime(true);
        $bound = $parser->fontSizeLowerBoundPx($this->target($document));
        $estimate = $parser->fontSizePx($this->target($document));
        $parser->isBold($this->target($document));
        $elapsed = microtime(true) - $start;

        $this->assertNull($bound);
        $this->assertEqualsWithDelta(16.0, $estimate, 0.001);
        $this->assertLessThan(PerformanceBudget::seconds(0.2), $elapsed, sprintf('took %.3f s', $elapsed));
    }

    public function test_rules_carry_the_index_of_their_stylesheet(): void
    {
        $document = HtmlDocument::fromHtml('<html><head><style>a{color:red}</style><style media="print">b{color:red}</style><style>c{color:red}</style></head><body></body></html>');

        $rules = (new CssParser)->parse($document)->rules();

        $this->assertSame([['a', 0], ['c', 1]], array_map(fn (array $rule) => [$rule['selector'], $rule['sheet']], $rules));
    }

    public function test_hides_element_uses_display_and_visibility_from_stylesheets(): void
    {
        [$parser, $document] = $this->parsed(
            '.modal { display: none } .modal.show { display: block } .invisible { visibility: hidden } .shown { visibility: visible }',
            '<div class="modal" id="closed"><button id="b1">x</button></div><div class="modal show" id="open"><button id="b2">y</button></div>'
                .'<div class="invisible"><p id="p1">a</p><p class="shown" id="p2">b</p></div>'
        );
        $byId = $document->elementsById();

        $this->assertTrue($parser->hidesElement($byId['closed']));
        $this->assertTrue($parser->hidesElement($byId['b1']));
        $this->assertFalse($parser->hidesElement($byId['b2']));
        $this->assertTrue($parser->hidesElement($byId['p1']));
        $this->assertFalse($parser->hidesElement($byId['p2']));
    }

    public function test_custom_properties_resolve_from_the_root_with_fallbacks(): void
    {
        $root = ':root { --fg: #222; --muted: var(--gray-500); --gray-500: oklch(55% 0 0); --Brand: #0000ff } html { --bg: #fafafa }';

        $this->assertSame(['#222222', '#ffffff', false], $this->colors($root.' p { color: var(--fg) }', '<p id="t">x</p>'));
        $this->assertSame(['#717171', '#ffffff', false], $this->colors($root.' p { color: var(--muted) }', '<p id="t">x</p>'), 'nested references');
        $this->assertSame(['#0000ff', '#fafafa', false], $this->colors($root.' p { color: var(--Brand); background: var(--bg) }', '<p id="t">x</p>'), 'names are case-sensitive and kept');
        $this->assertSame(['#333333', '#ffffff', false], $this->colors($root.' p { color: var(--missing, #333) }', '<p id="t">x</p>'), 'fallback for an undefined name');
        $this->assertSame(['#444444', '#ffffff', false], $this->colors($root.' p { color: var(--missing, var(--also-missing, #444)) }', '<p id="t">x</p>'));
        $this->assertSame(['#000000', '#ffffff', true], $this->colors($root.' p { color: var(--brand) }', '<p id="t">x</p>'), 'undefined without fallback stays approximate');
        $this->assertSame(['#ffffff', '#ffffff', false], $this->colors('.dark { --fg: #fff } p { color: var(--fg) }', '<div class="dark"><p id="t">x</p></div>'), 'element-scoped properties inherit');
    }

    public function test_custom_properties_follow_the_cascade_and_the_html_style_attribute(): void
    {
        $document = HtmlDocument::fromHtml('<html style="--c: #111"><head><style>:root { --a: #aaa !important; --b: #bbb } :root { --a: #ccc; --b: #ddd }</style></head><body></body></html>');
        $parser = (new CssParser)->parse($document);

        $this->assertSame(['--a' => '#aaa', '--b' => '#ddd', '--c' => '#111'], $parser->customProperties());
        $this->assertSame('1px solid #ddd', $parser->resolveVariables('1px solid var(--b)'));
        $this->assertNull($parser->resolveVariables('var(--nope)'));
        $this->assertNull($parser->resolveVariables('var(--b'));
    }

    public function test_nested_custom_properties_are_capped_instead_of_expanding_exponentially(): void
    {
        $css = ':root { --v0: #111; ';

        for ($level = 1; $level <= 8; $level++) {
            $css .= "--v$level: ".implode(' ', array_fill(0, 8, 'var(--v'.($level - 1).')')).'; ';
        }

        [$parser] = $this->parsed($css.'}', '<p id="t">x</p>');

        $this->assertNull($parser->resolveVariables('var(--v8)'));
        $this->assertSame(str_repeat('#111 ', 7).'#111', $parser->resolveVariables('var(--v1)'), 'short values still resolve');
    }

    #[Group('performance')]
    public function test_nested_custom_properties_resolve_within_the_time_budget(): void
    {
        $css = ':root { --v0: #111; ';

        for ($level = 1; $level <= 8; $level++) {
            $css .= "--v$level: ".implode(' ', array_fill(0, 8, 'var(--v'.($level - 1).')')).'; ';
        }

        [$parser] = $this->parsed($css.'}', '<p id="t">x</p>');

        $start = microtime(true);
        $parser->resolveVariables('var(--v8)');
        $this->assertLessThan(PerformanceBudget::seconds(0.1), microtime(true) - $start);
    }

    public function test_custom_properties_declared_outside_the_root_resolve_per_element(): void
    {
        $this->assertSame(['#eeeeee', '#ffffff', false], $this->colors(':root { --fg: #111 } .dark { --fg: #eee } p { color: var(--fg) }', '<div class="dark"><p id="t">x</p></div>'), 'the closest declaration wins');
        $this->assertSame(['#111111', '#ffffff', false], $this->colors(':root { --fg: #111 } html.dark { --fg: #eee } p { color: var(--fg) }', '<p id="t">x</p>'), 'a rule that matches nothing changes nothing');
        $this->assertSame(['#eeeeee', '#ffffff', false], $this->colors(':root { --fg: #111 } @media (prefers-color-scheme: dark) { :root { --fg: #eee } } p { color: var(--fg) }', '<p id="t">x</p>'), 'conditional rules apply as they do for color');
        $this->assertSame(['#111111', '#fafafa', false], $this->colors(':root { --fg: #111; --bg: #fafafa } .x { --fg: #222 } p { color: var(--fg); background: var(--bg) }', '<p id="t">x</p>'));
        $this->assertSame(['#111111', '#fafafa', false], $this->colors('@layer theme { :root { --fg: #111 } } @media screen { :root { --bg: #fafafa } } p { color: var(--fg); background: var(--bg) }', '<p id="t">x</p>'), '@layer and plain screen media are unconditional');
    }

    public function test_guaranteed_invalid_initial_uses_the_fallback(): void
    {
        [$parser, $document] = $this->parsed('*{--tw-text:initial} p{color:var(--tw-text, #777)}', '<p id="t">x</p>');
        $colors = $parser->resolveColors($this->target($document));

        $this->assertSame('#777777', $colors['foreground']->toHex());
        $this->assertFalse($colors['approximate']);

        $this->assertSame(['#777777', '#ffffff', false], $this->colors(':root{--tw-text:#111} *{--tw-text:initial} p{color:var(--tw-text, #777)}', '<p id="t">x</p>'), 'a reset on the element itself wins over the inherited value');
        $this->assertSame(['#000000', '#ffffff', true], $this->colors('*{--tw-text:INITIAL} p{color:var(--tw-text)}', '<p id="t">x</p>'), 'initial without a fallback is unresolvable');
    }

    public function test_cyclic_custom_properties_are_approximate(): void
    {
        [$parser, $document] = $this->parsed('body{color:#333} p{--a:var(--b);--b:var(--a);color:var(--a)}', '<p id="t">x</p>');
        $colors = $parser->resolveColors($this->target($document));

        $this->assertTrue($colors['approximate']);
        $this->assertSame('#333333', $colors['foreground']->toHex(), 'an unresolvable colour walks up');
        $this->assertSame([null, true], $parser->substituteFor($this->target($document), 'var(--a)'));
        $this->assertNull($parser->customProperty($this->target($document), '--b'));

        $this->assertSame(['#123456', '#ffffff', false], $this->colors('p{--a:var(--b);--b:#123456;color:var(--a)}', '<p id="t">x</p>'), 'a chain without a cycle resolves');
    }

    public function test_custom_property_rules_do_not_evict_colour_rules(): void
    {
        $css = '';

        for ($i = 0; $i < 2500; $i++) {
            $css .= ".v{$i}{--x{$i}:1}";
        }

        [$parser, $document] = $this->parsed($css.'*{--tw-a:initial} .low{color:#ccc}', '<p id="t" class="low v2499">x</p>');
        $paragraph = $this->target($document);

        $this->assertFalse($parser->isTruncated());
        $this->assertSame('#cccccc', $parser->resolveColors($paragraph)['foreground']->toHex());
        $this->assertSame('1', $parser->customProperty($paragraph, '--x2499'), 'custom-property-only rules are indexed');
        $this->assertNull($parser->customProperty($paragraph, '--x1'));
        $this->assertNull($parser->customProperty($paragraph, '--tw-a'));
    }

    public function test_overflowing_the_custom_property_budget_marks_results_approximate(): void
    {
        $css = '';

        for ($i = 0; $i <= CssParser::MAX_CUSTOM_PROPERTY_RULES; $i++) {
            $css .= ".v{$i}{--x:#{$i}}";
        }

        [$parser, $document] = $this->parsed($css.'.low{color:#ccc} .late{--late:#123456}', '<p id="t" class="low late">x</p>');

        $this->assertTrue($parser->isTruncated());
        $colors = $parser->resolveColors($this->target($document));
        $this->assertSame('#cccccc', $colors['foreground']->toHex(), 'colour rules after the overflow still apply');
        $this->assertTrue($colors['approximate']);
        $this->assertNull($parser->customProperty($this->target($document), '--late'), 'custom-property rules beyond the cap are not applied');
    }

    public function test_custom_properties_resolve_per_element(): void
    {
        [$parser, $document] = $this->parsed(
            ':root{--fg:#111;--ref:var(--fg)} .a{--fg:#222} .b{--fg:#333 !important} .c{--fg:inherit} .d{--fg:unset} .e{--fg:initial}',
            '<div class="a" id="a"><p id="inherits">x</p><p id="inline" style="--fg: #444">x</p><p id="important" class="b" style="--fg: #444">x</p>'
                .'<p id="ref">x</p><div class="e" id="e"><p class="c" id="c">x</p><p class="d" id="d">x</p></div></div><p id="root">x</p>',
        );
        $byId = $document->elementsById();

        $this->assertSame('#222', $parser->customProperty($byId['a'], '--fg'));
        $this->assertSame('#222', $parser->customProperty($byId['inherits'], '--fg'), 'the parent value inherits');
        $this->assertSame('#444', $parser->customProperty($byId['inline'], '--fg'), 'the inline style wins over rules');
        $this->assertSame('#333', $parser->customProperty($byId['important'], '--fg'), 'an important rule wins over the inline style');
        $this->assertSame('#111', $parser->customProperty($byId['root'], '--fg'));
        $this->assertSame('#111', $parser->customProperty($byId['ref'], '--ref'), 'var() inside a custom property resolves where it is declared');
        $this->assertNull($parser->customProperty($byId['e'], '--fg'), 'initial is the guaranteed-invalid value');
        $this->assertNull($parser->customProperty($byId['c'], '--fg'), 'inherit takes the parent value');
        $this->assertNull($parser->customProperty($byId['d'], '--fg'), 'unset inherits');
        $this->assertNull($parser->customProperty($byId['root'], '--missing'));
        $this->assertNull($parser->customProperty($byId['root'], '--FG'), 'names are case-sensitive');

        $this->assertSame(['1px solid #222', false], $parser->substituteFor($byId['inherits'], '1px solid var(--fg)'));
        $this->assertSame(['#abc', false], $parser->substituteFor($byId['e'], 'var(--fg, #abc)'), 'the fallback applies to initial');
        $this->assertSame(['#abc', false], $parser->substituteFor($byId['e'], 'var(--missing, var(--also-missing, #abc))'));
        $this->assertSame([null, true], $parser->substituteFor($byId['e'], 'var(--missing)'));
        $this->assertSame([null, true], $parser->substituteFor($byId['e'], 'var(--fg'), 'unclosed var()');
        $this->assertSame(['red', false], $parser->substituteFor($byId['e'], 'red'));
        $this->assertSame('#111', $parser->resolveVariables('var(--fg)'), 'resolveVariables() stays root-only');
    }

    public function test_host_custom_properties_still_resolve_from_the_root(): void
    {
        $this->assertSame(['#0000ff', '#ffffff', false], $this->colors(':host{--brand:#00f} p{color:var(--brand)}', '<p id="t">x</p>'));
    }

    public function test_conditional_custom_properties_apply_like_other_declarations(): void
    {
        $this->assertSame(['#eeeeee', '#ffffff', false], $this->colors('.x{--fg:#111} @media (min-width: 768px) { .x{--fg:#eee} } p{color:var(--fg)}', '<div class="x"><p id="t">x</p></div>'), 'as declarationsFor() applies a colour from that rule');
        $this->assertSame(['#111111', '#ffffff', false], $this->colors('@supports (color: red) { .x{--fg:#eee} } .x{--fg:#111} p{color:var(--fg)}', '<div class="x"><p id="t">x</p></div>'), 'a lighter conditional declaration loses');
        $this->assertSame(['#eeeeee', '#ffffff', false], $this->colors(':root{--fg:#111} @container (min-width: 1px) { .x{--fg:#eee} } p{color:var(--fg)}', '<div class="x"><p id="t">x</p></div>'));

        [$parser, $document] = $this->parsed('@media (min-width: 768px) { .x{--fg:#eee;color:var(--fg)} }', '<p id="t" class="x">x</p>');
        $this->assertSame('#eee', $parser->declarationsFor($this->target($document))['--fg']['value'], 'both read the same cascade');
        $this->assertSame('#eee', $parser->customProperty($this->target($document), '--fg'));
    }

    public function test_custom_properties_from_unsupported_selectors_are_approximate(): void
    {
        $this->assertSame(['#999999', '#ffffff', true], $this->colors(':root{--fg:#999} li:nth-child(odd){--fg:#111} li{color:var(--fg)}', '<ul><li id="t">x</li></ul>'), 'odd rows may render another value');
        $this->assertSame(['#999999', '#ffffff', false], $this->colors(':root{--fg:#999} li:nth-child(odd){--fg:#111} p{color:var(--fg)}', '<div><p id="t">x</p></div>'), 'the maybe rule covers li elements only');
        $this->assertSame(['#999999', '#ffffff', true], $this->colors(':root{--fg:#999} :root:has(.on){--fg:#111} p{color:var(--fg)}', '<p id="t" class="on">x</p>'), ':root:has() reduces to :root, whose value every element inherits');
        $this->assertSame(['#999999', '#ffffff', true], $this->colors(':root{--fg:#999} .a ~ p{--fg:#111} p{color:var(--fg, #000)}', '<p id="t">x</p>'), 'the rightmost compound decides');
        $this->assertSame(['#999999', '#ffffff', true], $this->colors(':root{--fg:#999} div:nth-child(1){--fg:#111}', '<div><p id="t" style="color:var(--fg)">x</p></div>'), 'inherited from an element a maybe rule covers');
        $this->assertSame(['#999999', '#ffffff', false], $this->colors(':root{--fg:#111} li:nth-child(odd){--fg:#111} #t{--fg:#999} li{color:var(--fg)}', '<ul><li id="t">x</li></ul>'), 'a heavier definite declaration wins over the maybe rule');
        $this->assertSame(['#999999', '#ffffff', true], $this->colors(':root{--fg:#999} .x:not(:hover){--fg:#111} p{color:var(--fg)}', '<p id="t" class="x">x</p>'), ':not(:hover) matches at rest');
        $this->assertSame(['#999999', '#ffffff', true], $this->colors(':root{--fg:#999} :not(:focus-visible){--fg:#111} p{color:var(--fg)}', '<p id="t">x</p>'), 'an empty reduction covers every element');
        $this->assertSame(['#999999', '#ffffff', false], $this->colors(':root{--fg:#999} .x:hover{--fg:#111} a:focus-visible{--fg:#222} :active{--fg:#333} .y:hover .z{--fg:#444} p{color:var(--fg)}', '<p id="t" class="x z">x</p>'), 'user-action states are not the base state');
        $this->assertSame(['#999999', '#ffffff', false], $this->colors(':root{--fg:#999} *, ::before, ::after{--other:1} p::first-line{--fg:#111} p:after{--fg:#111} p{color:var(--fg)}', '<p id="t">x</p>'), 'pseudo-element rules never style the element itself');
        $this->assertSame(['#999999', '#ffffff', false], $this->colors(':root{--fg:#999} .x:hover:nth-child(2){--fg:#111} .peer:hover ~ .peer-hover\\:x{--fg:#111} p{color:var(--fg)}', '<p id="t" class="x peer-hover:x">x</p>'), 'a state outside parentheses never matches at rest, whatever else is unsupported');
        $this->assertSame(['#999999', '#ffffff', true], $this->colors(':root{--fg:#999} :is(.dark .dark\\:x){--fg:#111} p{color:var(--fg)}', '<p id="t" class="dark:x">x</p>'), ':is() arguments reduce to their rightmost compound');
        $this->assertSame(['#999999', '#ffffff', false], $this->colors(':root{--fg:#999} :is(.dark .dark\\:x){--fg:#111} :is(:where(.dark) .dark\\:y, .z){--fg:#111} p{color:var(--fg)}', '<p id="t" class="dark">x</p>'), 'elements without the argument classes stay exact');
        $this->assertSame(['#999999', '#ffffff', true], $this->colors(':root{--fg:#999} :is(:where(.dark) .dark\\:y, .z){--fg:#111} p{color:var(--fg)}', '<p id="t" class="z">x</p>'), 'the union of the arguments');
        $this->assertSame(['#999999', '#ffffff', true], $this->colors(':root{--fg:#999} p:is(.a, :nth-child(2)){--fg:#111} p{color:var(--fg)}', '<p id="t">x</p>'), 'an argument that reduces to nothing leaves the rest of the compound');
        $this->assertSame(['#999999', '#ffffff', false], $this->colors(':root{--fg:#999} p:is(.a, :nth-child(2)){--fg:#111} div{color:var(--fg)}', '<div id="t">x</div>'), 'whose type still applies');
        $this->assertSame(['#999999', '#ffffff', false], $this->colors(':root{--fg:#999} :is(.x:hover){--fg:#111} p{color:var(--fg)}', '<p id="t" class="x">x</p>'), 'an argument that needs a state never matches at rest');
        $this->assertSame(['#999999', '#ffffff', false], $this->colors(':root{--fg:#999} .disabled\\:x:disabled{--fg:#111} .peer:checked ~ .peer-checked\\:x{--fg:#111} p{color:var(--fg)}', '<p id="t" class="x">x</p>'), 'variant classes the element does not carry');
    }

    /**
     * Rules whose rightmost compound nests :is() 8 levels deep with 40 arguments each (followed by 8 :is() of 16 arguments),
     * and a cartesian product: 16 alternatives times an :is() with 20,000 arguments.
     */
    private static function hostileSelectorRule(): string
    {
        $selector = '.x';

        for ($level = 0; $level < 8; $level++) {
            $selector = ':is('.implode(',', array_map(fn (int $i) => ".a{$level}_$i", range(0, 38))).','.$selector.')';
        }

        for ($level = 0; $level < 8; $level++) {
            $selector .= ':is('.implode(',', array_map(fn (int $i) => "li.b{$level}_$i", range(0, 15))).')';
        }

        $product = 'li:is('.implode(',', array_map(fn (int $i) => ".c$i", range(0, 15))).'):is('.implode(',', array_map(fn (int $i) => ".d$i", range(0, 19999))).')';

        return $selector.'{--fg:#111} '.$product.'{--fg:#111} :root{--fg:#999}';
    }

    /** A rule whose rightmost compound is 63 KB: li:is() of 16 arguments, 2000 classes and 1030 :is() of 16 equal arguments. */
    private static function longCompoundRule(): string
    {
        $selector = 'li:is('.implode(',', array_map(fn (int $i) => ".a$i", range(0, 15))).')';

        for ($i = 0; $i < 2000; $i++) {
            $selector .= ".q$i";
        }

        return $selector.str_repeat(':is('.implode(',', array_fill(0, 16, '.r')).')', 1030).'{--fg:#111} :root{--fg:#999}';
    }

    /** @return array<string, array{0: string}> */
    public static function hostileSelectorRules(): array
    {
        $lists = 'li';

        for ($list = 0; $list < 5; $list++) {
            $lists .= ':is('.implode(',', array_map(fn (int $i) => ".e{$list}_$i", range(0, 15))).')';
        }

        return [
            'nested lists and a 16 × 20,000 product' => [self::hostileSelectorRule()],
            // 16^5 alternatives unless the cap applies while they are built: each list stays within the argument cap
            'five lists of 16 arguments' => [$lists.'{--fg:#111} :root{--fg:#999}'],
            'a 63 KB rightmost compound' => [self::longCompoundRule()],
        ];
    }

    #[DataProvider('hostileSelectorRules')]
    public function test_selector_reduction_is_bounded(string $rules): void
    {
        [$parser, $document] = $this->parsed($rules, '<p id="t" class="x">x</p>');
        memory_reset_peak_usage();
        $before = memory_get_usage(true);

        $result = $parser->buildIndex()->substituteFor($this->target($document), 'var(--fg)');

        $this->assertLessThan(8 * 1048576, memory_get_peak_usage(true) - $before, sprintf('peak grew by %.1f MB', (memory_get_peak_usage(true) - $before) / 1048576));
        $this->assertSame(['#999', true], $result, 'too many alternatives or too long to reduce: the maybe rule covers every element');
    }

    #[Group('performance')]
    #[DataProvider('hostileSelectorRules')]
    public function test_selector_reduction_finishes_within_the_time_budget(string $rules): void
    {
        [$parser, $document] = $this->parsed($rules, '<p id="t" class="x">x</p>');

        $start = microtime(true);
        $parser->buildIndex()->substituteFor($this->target($document), 'var(--fg)');

        $this->assertLessThan(PerformanceBudget::seconds(0.5), microtime(true) - $start);
    }

    #[Group('performance')]
    public function test_a_long_compound_reduces_within_the_time_budget(): void
    {
        [$parser, $document] = $this->parsed(self::longCompoundRule(), '<p id="t">x</p>');

        $start = microtime(true);
        $parser->buildIndex()->substituteFor($this->target($document), 'var(--fg)');
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(PerformanceBudget::seconds(0.2), $elapsed, sprintf('the reduction took %.2f s', $elapsed));
    }

    public function test_invalid_at_computed_value_time_uses_the_fallback(): void
    {
        $this->assertSame(['#777777', '#ffffff', false], $this->colors(':root{--a:var(--undef)} p{color:var(--a,#777)}', '<p id="t">x</p>'), 'an undefined reference chain is exact');
        $this->assertSame(['#777777', '#ffffff', true], $this->colors('p{--a:var(--b);--b:var(--a);color:var(--a,#777)}', '<p id="t">x</p>'), 'a cycle is approximate');
        $this->assertSame(['#000000', '#ffffff', true], $this->colors(':root{--a:var(--undef)} p{color:var(--a)}', '<p id="t">x</p>'), 'without a fallback it stays unresolvable');

        [$parser, $document] = $this->parsed(':root{--a:var(--undef)}', '<p id="t">x</p>');
        $this->assertNull($parser->customProperty($this->target($document), '--a'));
        $this->assertSame(['#777', false], $parser->substituteFor($this->target($document), 'var(--a, #777)'));
    }

    public function test_inherited_custom_properties_are_shared_through_owners(): void
    {
        $css = '*{--x:#777} div,p{--x:inherit} .b{--x:#123}';
        $body = '<div><div class="b"><p id="a">x</p></div><p id="c">x</p></div>';

        foreach ([['a', 'c'], ['c', 'a']] as $order) {
            [$parser, $document] = $this->parsed($css, $body);
            $byId = $document->elementsById();
            $values = [];

            foreach ($order as $id) {
                $values[$id] = $parser->customProperty($byId[$id], '--x');
            }

            $this->assertSame(['#123', '#777'], [$values['a'], $values['c']], 'queried as '.implode(', ', $order));
        }

        [$parser, $document] = $this->parsed(':root{--x:#111} .o{--y:1} .o2{--x:var(--y)}', '<div class="o"><div class="o"><p id="t">x</p></div></div><div class="o"><div class="o2"><p id="u">x</p></div></div>');
        $this->assertSame('#111', $parser->customProperty($this->target($document), '--x'), 'owners that declare other names are passed');
        $this->assertSame('1', $parser->customProperty($document->elementsById()['u'], '--x'), 'var() resolves where it is declared');
    }

    public function test_var_references_per_value_are_capped(): void
    {
        [$parser, $document] = $this->parsed(':root{--x:a}', '<p id="t">x</p>');
        $paragraph = $this->target($document);

        $this->assertSame([str_repeat('a', CssParser::MAX_VAR_REFERENCES), false], $parser->substituteFor($paragraph, str_repeat('var(--x)', CssParser::MAX_VAR_REFERENCES)));
        $this->assertSame([null, true], $parser->substituteFor($paragraph, str_repeat('var(--x)', CssParser::MAX_VAR_REFERENCES + 1)));
        $this->assertSame([null, true], $parser->substituteFor($paragraph, str_repeat('var(--undefined,)', CssParser::MAX_VAR_REFERENCES + 1)));
    }

    public function test_nested_custom_properties_per_element_are_capped(): void
    {
        [$parser, $document] = $this->parsed($this->exponentialCustomProperties('p').'}', '<p id="t">x</p>');

        $this->assertSame([null, true], $parser->substituteFor($this->target($document), 'var(--v8)'));
        $this->assertSame([str_repeat('#111 ', 7).'#111', false], $parser->substituteFor($this->target($document), 'var(--v1)'), 'short values still resolve');

        $chain = '--c0: #111; ';

        for ($level = 1; $level <= 9; $level++) {
            $chain .= "--c$level: var(--c".($level - 1).'); ';
        }

        [$parser, $document] = $this->parsed(":root { $chain } p { $chain }", '<p id="t">x</p>');

        $this->assertSame(['#111', false], $parser->substituteFor($this->target($document), 'var(--c8)'));
        $this->assertSame([null, true], $parser->substituteFor($this->target($document), 'var(--c9)'), 'nesting deeper than 8 is unresolvable');
        $this->assertSame(['#111', null], [$parser->resolveVariables('var(--c8)'), $parser->resolveVariables('var(--c9)')], 'the same depth as the root-only resolver');
    }

    #[Group('performance')]
    public function test_per_element_custom_properties_resolve_within_the_time_budget(): void
    {
        [$parser, $document] = $this->parsed($this->exponentialCustomProperties('div').'} p { color: var(--v8, #333); background: var(--v7) }', str_repeat('<div><p>x</p></div>', 300));

        $start = microtime(true);

        foreach ($document->query('//p') as $paragraph) {
            $parser->resolveColors($paragraph);
        }

        $this->assertLessThan(PerformanceBudget::seconds(0.2), microtime(true) - $start);
    }

    /** Opens a rule for $selector with --v0 … --v8, each level referencing the previous one eight times. */
    private function exponentialCustomProperties(string $selector): string
    {
        $css = "$selector { --v0: #111; ";

        for ($level = 1; $level <= 8; $level++) {
            $css .= "--v$level: ".implode(' ', array_fill(0, 8, 'var(--v'.($level - 1).')')).'; ';
        }

        return $css;
    }

    public function test_match_expressions_bucket_by_the_rightmost_compound(): void
    {
        $parser = new CssParser;

        $this->assertSame(['class', 'title'], $parser->matchExpression('.card > h2.title')['bucket']);
        $this->assertSame(['id', 'main'], $parser->matchExpression('body div#main.wide')['bucket']);
        $this->assertNull($parser->matchExpression('nav a')['bucket']);
        $this->assertSame(['class', 'x'], $parser->matchExpression('p:not(.y).x')['bucket']);
        $this->assertNull($parser->matchExpression('a:hover'));
        $this->assertStringStartsWith('self::', $parser->matchExpression('.a .b')['expression']);
        $this->assertNull($parser->matchExpression('nav a[href="#top"]')['bucket'], 'a # inside an attribute value is not an id');
        $this->assertNull($parser->matchExpression('a[href$=".pdf"]')['bucket'], 'a . inside an attribute value is not a class');
        $this->assertSame(['class', 'btn'], $parser->matchExpression('a[href$=".pdf"].btn')['bucket']);
        $this->assertNull($parser->matchExpression('p:not(#intro)')['bucket'], 'a negated id is not a bucket');
        $this->assertSame(['class', 'bg-[#fff]'], $parser->matchExpression('.dark .bg-\[\#fff\]')['bucket'], 'escapes are decoded');
        $this->assertSame(['class', 'title'], $parser->matchExpression('[data-x=".5"].title')['bucket']);
    }

    public function test_bucketed_rules_match_exactly_what_the_document_query_matches(): void
    {
        $selectors = ['.card .title', '.card > .title', 'section .card .title', 'main > section > .card', '#hero .title', 'div.card:first-child .title',
            '.card :not(.title).meta', 'ul > li.item', 'ul li.item.active', '.list .item:last-child', '[data-x] .title', ':root .title', 'body #hero',
            'nav a[href="#top"]', 'a[href$=".pdf"]', 'a[href$=".pdf"].btn', 'p:not(#intro)', '.dark .bg-\[\#fff\]', '[data-x=".5"].title'];
        $body = '<main><section id="hero"><div class="card"><h2 class="title">A</h2><p class="meta">m</p></div><div class="card"><div><h3 class="title">B</h3></div></div></section>'
            .'<section><ul class="list"><li class="item">1</li><li class="item active">2</li></ul><div data-x><span class="title">C</span></div></section>'
            .'<nav><a href="#top">Top</a></nav><p id="intro">i</p><a href="/a.pdf">A</a><a class="btn" href="/b.pdf">B</a>'
            .'<div class="dark"><span class="bg-[#fff]">D</span></div><span data-x=".5" class="title">E</span></main>';
        $css = implode(' ', array_map(fn (string $selector) => $selector.' { color: #123456 }', $selectors));
        [$parser, $document] = $this->parsed($css, $body);
        $parser->buildIndex();

        foreach ($selectors as $position => $selector) {
            $expected = array_map(fn (DOMElement $element) => $element->getNodePath(), $document->query($parser->simpleSelectorToXpath($selector)));
            $actual = [];

            foreach ($document->query('//*') as $element) {
                if (in_array($position, (fn () => $this->index[$element->getNodePath()] ?? [])->call($parser), true)) {
                    $actual[] = $element->getNodePath();
                }
            }

            $this->assertSame($expected, $actual, $selector);
            $this->assertNotSame([], $expected, "$selector should match something in the fixture");
        }
    }
}
