<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Services\HtmlLoader;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class HtmlLoaderTest extends TestCase
{
    public function test_decodes_utf8_when_no_charset_is_declared(): void
    {
        $html = '<html><body><a href="#main">Zum Inhalt überspringen</a></body></html>';

        $dom = HtmlLoader::load($html);

        $this->assertStringContainsString('überspringen', $dom->textContent);
        $this->assertStringNotContainsString('Ã¼', $dom->textContent);
    }

    public function test_respects_a_declared_non_utf8_charset(): void
    {
        // "für" encoded as ISO-8859-1 (0xFC), declared via meta charset.
        $html = "<html><head><meta charset=\"iso-8859-1\"></head><body><p>f\xFCr</p></body></html>";

        $dom = HtmlLoader::load($html);

        $this->assertStringContainsString('für', $dom->textContent);
    }

    public function test_does_not_leave_a_processing_instruction_in_the_document(): void
    {
        $dom = HtmlLoader::load('<html><body><p>Ünïcode</p></body></html>');

        foreach ($dom->childNodes as $node) {
            $this->assertNotSame(XML_PI_NODE, $node->nodeType, 'Encoding hint must be removed after parsing');
        }
    }

    public function test_returns_empty_document_for_blank_html(): void
    {
        $dom = HtmlLoader::load("   \n ");

        $this->assertNull($dom->documentElement);
    }

    public function test_passes_libxml_flags_through(): void
    {
        $fragment = '<p>Fragment</p>';

        $implied = HtmlLoader::load($fragment);
        $bare = HtmlLoader::load($fragment, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $this->assertSame('html', $implied->documentElement->nodeName);
        $this->assertSame('p', $bare->documentElement->nodeName);
    }

    public function test_does_not_emit_php_warnings_for_malformed_html(): void
    {
        $dom = HtmlLoader::load('<div><span>unclosed<p>tags</div>');

        $this->assertNotNull($dom->documentElement);
        $this->assertSame([], libxml_get_errors(), 'libxml errors must be cleared after loading');
    }
}
