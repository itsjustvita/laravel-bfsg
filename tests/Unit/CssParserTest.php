<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Services\CssParser;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class CssParserTest extends TestCase
{
    protected CssParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new CssParser;
    }

    protected function parseDom(string $html): \DOMDocument
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        return $dom;
    }

    // --- CSS Parsing ---

    public function test_extracts_rules_from_style_block(): void
    {
        $html = '<html><head><style>p { color: red; } .btn { background-color: blue; }</style></head><body><p>Text</p></body></html>';
        $dom = $this->parseDom($html);
        $this->parser->parse($dom);
        $rules = $this->parser->getRules();

        $this->assertCount(2, $rules);
        $this->assertEquals('p', $rules[0]['selector']);
        $this->assertEquals('red', $rules[0]['properties']['color']);
        $this->assertEquals('.btn', $rules[1]['selector']);
    }

    public function test_handles_multiple_style_blocks(): void
    {
        $html = '<html><head><style>p { color: red; }</style><style>.x { color: blue; }</style></head><body><p>Text</p></body></html>';
        $dom = $this->parseDom($html);
        $this->parser->parse($dom);

        $this->assertCount(2, $this->parser->getRules());
    }

    public function test_handles_comma_separated_selectors(): void
    {
        $html = '<html><head><style>h1, h2, h3 { color: navy; }</style></head><body><h1>Hi</h1></body></html>';
        $dom = $this->parseDom($html);
        $this->parser->parse($dom);

        $this->assertCount(3, $this->parser->getRules());
    }

    public function test_strips_css_comments(): void
    {
        $html = '<html><head><style>/* comment */ p { color: red; } /* another */</style></head><body><p>Text</p></body></html>';
        $dom = $this->parseDom($html);
        $this->parser->parse($dom);

        $this->assertCount(1, $this->parser->getRules());
        $this->assertEquals('red', $this->parser->getRules()[0]['properties']['color']);
    }

    public function test_skips_media_queries(): void
    {
        $html = '<html><head><style>p { color: red; } @media (max-width: 768px) { p { color: blue; } }</style></head><body><p>Text</p></body></html>';
        $dom = $this->parseDom($html);
        $this->parser->parse($dom);

        // Only the non-media rule should be parsed
        $this->assertCount(1, $this->parser->getRules());
        $this->assertEquals('red', $this->parser->getRules()[0]['properties']['color']);
    }

    // --- Specificity ---

    public function test_specificity_element(): void
    {
        $this->assertEquals([0, 0, 1], $this->parser->calculateSpecificity('p'));
    }

    public function test_specificity_class(): void
    {
        $this->assertEquals([0, 1, 0], $this->parser->calculateSpecificity('.btn'));
    }

    public function test_specificity_id(): void
    {
        $this->assertEquals([1, 0, 0], $this->parser->calculateSpecificity('#header'));
    }

    public function test_specificity_combined(): void
    {
        // div.btn = 0,1,1
        $this->assertEquals([0, 1, 1], $this->parser->calculateSpecificity('div.btn'));
    }

    public function test_specificity_complex(): void
    {
        // #nav .item a = 1,1,1
        $this->assertEquals([1, 1, 1], $this->parser->calculateSpecificity('#nav .item a'));
    }

    // --- Selector Matching ---

    public function test_matches_element_selector(): void
    {
        $html = '<html><head><style>p { color: red; }</style></head><body><p id="target">Text</p></body></html>';
        $dom = $this->parseDom($html);
        $this->parser->parse($dom);

        $xpath = new \DOMXPath($dom);
        $element = $xpath->query('//*[@id="target"]')->item(0);

        $this->assertTrue($this->parser->selectorMatchesElement('p', $element));
    }

    public function test_matches_class_selector(): void
    {
        $html = '<html><head><style>.red { color: red; }</style></head><body><p class="red" id="target">Text</p></body></html>';
        $dom = $this->parseDom($html);
        $this->parser->parse($dom);

        $xpath = new \DOMXPath($dom);
        $element = $xpath->query('//*[@id="target"]')->item(0);

        $this->assertTrue($this->parser->selectorMatchesElement('.red', $element));
    }

    public function test_matches_id_selector(): void
    {
        $html = '<html><head><style>#target { color: red; }</style></head><body><p id="target">Text</p></body></html>';
        $dom = $this->parseDom($html);
        $this->parser->parse($dom);

        $xpath = new \DOMXPath($dom);
        $element = $xpath->query('//*[@id="target"]')->item(0);

        $this->assertTrue($this->parser->selectorMatchesElement('#target', $element));
    }

    public function test_does_not_match_wrong_selector(): void
    {
        $html = '<html><head><style>.blue { color: blue; }</style></head><body><p class="red" id="target">Text</p></body></html>';
        $dom = $this->parseDom($html);
        $this->parser->parse($dom);

        $xpath = new \DOMXPath($dom);
        $element = $xpath->query('//*[@id="target"]')->item(0);

        $this->assertFalse($this->parser->selectorMatchesElement('.blue', $element));
    }

    // --- Color Resolution ---

    public function test_resolves_color_from_css(): void
    {
        $html = '<html><head><style>p { color: #333333; background-color: #ffffff; }</style></head><body><p id="target">Text</p></body></html>';
        $dom = $this->parseDom($html);
        $this->parser->parse($dom);

        $xpath = new \DOMXPath($dom);
        $element = $xpath->query('//*[@id="target"]')->item(0);

        $colors = $this->parser->getResolvedColors($element);
        $this->assertEquals('#333333', $colors['color']);
        $this->assertEquals('#ffffff', $colors['backgroundColor']);
    }

    public function test_inline_style_overrides_css(): void
    {
        $html = '<html><head><style>p { color: red; }</style></head><body><p id="target" style="color: blue;">Text</p></body></html>';
        $dom = $this->parseDom($html);
        $this->parser->parse($dom);

        $xpath = new \DOMXPath($dom);
        $element = $xpath->query('//*[@id="target"]')->item(0);

        $colors = $this->parser->getResolvedColors($element);
        $this->assertEquals('blue', $colors['color']);
    }

    public function test_higher_specificity_wins(): void
    {
        $html = '<html><head><style>p { color: red; } p.special { color: green; }</style></head><body><p class="special" id="target">Text</p></body></html>';
        $dom = $this->parseDom($html);
        $this->parser->parse($dom);

        $xpath = new \DOMXPath($dom);
        $element = $xpath->query('//*[@id="target"]')->item(0);

        $colors = $this->parser->getResolvedColors($element);
        $this->assertEquals('green', $colors['color']);
    }

    public function test_inherits_color_from_parent(): void
    {
        $html = '<html><head><style>.parent { color: navy; }</style></head><body><div class="parent"><p id="target">Text</p></div></body></html>';
        $dom = $this->parseDom($html);
        $this->parser->parse($dom);

        $xpath = new \DOMXPath($dom);
        $element = $xpath->query('//*[@id="target"]')->item(0);

        $colors = $this->parser->getResolvedColors($element);
        $this->assertEquals('navy', $colors['color']);
        $this->assertTrue($colors['approximate']);
    }

    public function test_defaults_to_black_on_white(): void
    {
        $html = '<html><head></head><body><p id="target">Text</p></body></html>';
        $dom = $this->parseDom($html);
        $this->parser->parse($dom);

        $xpath = new \DOMXPath($dom);
        $element = $xpath->query('//*[@id="target"]')->item(0);

        $colors = $this->parser->getResolvedColors($element);
        $this->assertEquals('#000000', $colors['color']);
        $this->assertEquals('#ffffff', $colors['backgroundColor']);
        $this->assertTrue($colors['approximate']);
    }

    public function test_css_variable_marked_approximate(): void
    {
        $html = '<html><head><style>p { color: var(--text-color); }</style></head><body><p id="target">Text</p></body></html>';
        $dom = $this->parseDom($html);
        $this->parser->parse($dom);

        $xpath = new \DOMXPath($dom);
        $element = $xpath->query('//*[@id="target"]')->item(0);

        $colors = $this->parser->getResolvedColors($element);
        $this->assertTrue($colors['approximate']);
    }

    public function test_background_shorthand(): void
    {
        $html = '<html><head><style>p { background: #f0f0f0 url(bg.png) no-repeat; }</style></head><body><p id="target">Text</p></body></html>';
        $dom = $this->parseDom($html);
        $this->parser->parse($dom);

        $xpath = new \DOMXPath($dom);
        $element = $xpath->query('//*[@id="target"]')->item(0);

        $colors = $this->parser->getResolvedColors($element);
        $this->assertEquals('#f0f0f0', $colors['backgroundColor']);
    }

    public function test_no_style_blocks(): void
    {
        $html = '<html><body><p id="target">Text</p></body></html>';
        $dom = $this->parseDom($html);
        $this->parser->parse($dom);

        $this->assertEmpty($this->parser->getRules());
    }
}
