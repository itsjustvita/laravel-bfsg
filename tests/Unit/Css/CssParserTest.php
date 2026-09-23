<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit\Css;

use DOMElement;
use ItsJustVita\LaravelBfsg\Css\CssParser;
use ItsJustVita\LaravelBfsg\Dom\HtmlDocument;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

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
        $this->assertSame(['#000000', '#ffffff', true], $this->colors('.dark { --fg: #fff } p { color: var(--fg) }', '<div class="dark"><p id="t">x</p></div>'), 'element-scoped properties are not resolved');
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

        $start = microtime(true);
        $this->assertNull($parser->resolveVariables('var(--v8)'));
        $this->assertLessThan(0.1, microtime(true) - $start);
        $this->assertSame(str_repeat('#111 ', 7).'#111', $parser->resolveVariables('var(--v1)'), 'short values still resolve');
    }

    public function test_custom_properties_overridden_outside_the_root_are_approximate(): void
    {
        $this->assertSame(['#111111', '#ffffff', true], $this->colors(':root { --fg: #111 } .dark { --fg: #eee } p { color: var(--fg) }', '<div class="dark"><p id="t">x</p></div>'));
        $this->assertSame(['#111111', '#ffffff', true], $this->colors(':root { --fg: #111 } html.dark { --fg: #eee } p { color: var(--fg) }', '<p id="t">x</p>'));
        $this->assertSame(['#111111', '#ffffff', true], $this->colors(':root { --fg: #111 } @media (prefers-color-scheme: dark) { :root { --fg: #eee } } p { color: var(--fg) }', '<p id="t">x</p>'));
        $this->assertSame(['#111111', '#fafafa', true], $this->colors(':root { --fg: #111; --bg: #fafafa } .x { --fg: #222 } p { color: var(--fg); background: var(--bg) }', '<p id="t">x</p>'));
        $this->assertSame(['#111111', '#fafafa', false], $this->colors('@layer theme { :root { --fg: #111 } } @media screen { :root { --bg: #fafafa } } p { color: var(--fg); background: var(--bg) }', '<p id="t">x</p>'), '@layer and plain screen media are unconditional');
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
