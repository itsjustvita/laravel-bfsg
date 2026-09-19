<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit\Dom;

use ItsJustVita\LaravelBfsg\Dom\HtmlDocument;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class HtmlDocumentTest extends TestCase
{
    public function test_full_document_is_not_a_fragment_and_has_head_and_body(): void
    {
        $doc = HtmlDocument::fromHtml('<!DOCTYPE html><html lang="de"><head><title>T</title></head><body><p>x</p></body></html>');

        $this->assertFalse($doc->isFragment());
        $this->assertFalse($doc->isEmpty());
        $this->assertSame('html', $doc->root()->nodeName);
        $this->assertSame('head', $doc->head()->nodeName);
        $this->assertSame('body', $doc->body()->nodeName);
    }

    public function test_fragment_is_detected_and_not_wrapped(): void
    {
        $doc = HtmlDocument::fromHtml('<div><p>Partial</p></div>');

        $this->assertTrue($doc->isFragment());
        $this->assertSame('div', $doc->root()->nodeName);
        $this->assertNull($doc->body());
        $this->assertCount(1, $doc->query('//p'));
    }

    public function test_fragment_option_overrides_detection(): void
    {
        $doc = HtmlDocument::fromHtml('<p>x</p>', ['fragment' => false]);

        $this->assertFalse($doc->isFragment());
        $this->assertSame('html', $doc->root()->nodeName);
    }

    public function test_blank_input_yields_empty_document(): void
    {
        $doc = HtmlDocument::fromHtml("  \n ");

        $this->assertTrue($doc->isEmpty());
        $this->assertNull($doc->root());
        $this->assertSame([], $doc->query('//*'));
    }

    public function test_decodes_utf8_without_charset_and_respects_declared_charset(): void
    {
        $utf8 = HtmlDocument::fromHtml('<html><body><a href="#n">Zum Menü</a></body></html>');
        $this->assertStringContainsString('Menü', $utf8->dom()->textContent);

        $latin = HtmlDocument::fromHtml("<html><head><meta charset=\"iso-8859-1\"></head><body><p>f\xFCr</p></body></html>");
        $this->assertStringContainsString('für', $latin->dom()->textContent);

        foreach ($utf8->dom()->childNodes as $node) {
            $this->assertNotSame(XML_PI_NODE, $node->nodeType);
        }
        $this->assertSame([], libxml_get_errors());
    }

    public function test_query_returns_elements_only_and_swallows_invalid_xpath(): void
    {
        $doc = HtmlDocument::fromHtml('<html><body><div><p id="a">t</p></div><p>u</p></body></html>');

        $this->assertCount(2, $doc->query('//p'));
        $this->assertSame([], $doc->query('//p[@id=\'unterminated'));
        $this->assertSame([], $doc->query('//p/text()'), 'text nodes are filtered out');
        $this->assertCount(1, $doc->query('.//p', $doc->query('//div')[0]), 'context node scopes the query');
    }

    public function test_id_index_and_duplicates(): void
    {
        $doc = HtmlDocument::fromHtml('<html><body><div id="a"></div><span id="b"></span><i id="a"></i></body></html>');

        $ids = $doc->elementsById();
        $this->assertSame(['a', 'b'], array_keys($ids));
        $this->assertSame('div', $ids['a']->nodeName, 'first occurrence wins');
        $this->assertSame(['a' => 2], $doc->duplicateIds());
    }

    public function test_stylesheets_skips_print_media(): void
    {
        $doc = HtmlDocument::fromHtml('<html><head><style>p{color:#000}</style><style media="print">p{color:#999}</style><style media="screen">a{color:#00f}</style></head><body></body></html>');

        $this->assertSame(['p{color:#000}', 'a{color:#00f}'], array_map('trim', $doc->styleSheets()));
    }

    public function test_remove_matching_css_selectors_and_reset_id_index(): void
    {
        $doc = HtmlDocument::fromHtml('<html><body><div id="chat" class="widget"></div><iframe src="https://chatbase.co/x"></iframe><p id="keep">x</p></body></html>');

        $removed = $doc->removeMatching(['#chat', 'iframe[src*="chatbase"]', 'not a selector [[[']);

        $this->assertSame(2, $removed);
        $this->assertSame([], $doc->query('//iframe'));
        $this->assertSame(['keep'], array_keys($doc->elementsById()));
    }

    public function test_selector_for_builds_an_indexed_absolute_xpath(): void
    {
        $doc = HtmlDocument::fromHtml('<html><body><main><img src="a"><img src="b"></main></body></html>');
        [$first, $second] = $doc->query('//img');

        $this->assertSame('/html[1]/body[1]/main[1]/img[1]', $doc->selectorFor($first));
        $this->assertSame('/html[1]/body[1]/main[1]/img[2]', $doc->selectorFor($second));
        $this->assertSame($second, $doc->query($doc->selectorFor($second))[0]);
    }

    public function test_xpath_literal_quotes_safely(): void
    {
        $doc = HtmlDocument::fromHtml('<html><body><label for="o\'brien">x</label></body></html>');

        $this->assertCount(1, $doc->query('//label[@for='.$doc->xpathLiteral("o'brien").']'));
        $this->assertSame("'plain'", $doc->xpathLiteral('plain'));
        $this->assertSame('"it\'s"', $doc->xpathLiteral("it's"));
    }
}
