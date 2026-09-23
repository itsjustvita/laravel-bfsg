<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit\Dom;

use DOMElement;
use ItsJustVita\LaravelBfsg\Dom\Element;
use ItsJustVita\LaravelBfsg\Dom\HtmlDocument;
use ItsJustVita\LaravelBfsg\Dom\Text;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class ElementTest extends TestCase
{
    private function el(string $html, string $xpath = '//*[@data-t]'): DOMElement
    {
        return HtmlDocument::fromHtml('<html><body>'.$html.'</body></html>')->query($xpath)[0];
    }

    public function test_describe_uses_tag_id_and_up_to_two_classes(): void
    {
        $this->assertSame('img#hero.banner.wide', Element::describe($this->el('<img data-t id="hero" class="banner  wide extra">')));
        $this->assertSame('p', Element::describe($this->el('<p data-t>x</p>')));
        $this->assertSame('a.btn', Element::describe($this->el('<a data-t class="btn" href="/">x</a>')));
    }

    public function test_snippet_is_normalized_and_truncated(): void
    {
        $el = $this->el("<p data-t class=\"x\">\n   Hello\n   <b>world</b>  </p>");
        $this->assertSame('<p data-t class="x"> Hello <b>world</b> </p>', Element::snippet($el));

        $long = $this->el('<p data-t>'.str_repeat('ü', 200).'</p>');
        $snippet = Element::snippet($long, 50);
        $this->assertLessThanOrEqual(50, mb_strlen($snippet));
        $this->assertTrue(mb_check_encoding($snippet, 'UTF-8'));
    }

    public function test_own_text_versus_text(): void
    {
        $el = $this->el('<li data-t style="color:#aaa"> Menu <a href="/">Impressum</a> </li>');

        $this->assertSame('Menu', Element::ownText($el));
        $this->assertSame('Menu Impressum', Element::text($el));
    }

    public function test_hidden_detection(): void
    {
        $this->assertTrue(Element::isHidden($this->el('<p data-t hidden>x</p>')));
        $this->assertTrue(Element::isHidden($this->el('<div aria-hidden="true"><p data-t>x</p></div>')));
        $this->assertTrue(Element::isHidden($this->el('<p data-t style="display: none">x</p>')));
        $this->assertTrue(Element::isHidden($this->el('<div style="visibility:hidden"><p data-t>x</p></div>')));
        $this->assertTrue(Element::isHidden($this->el('<template><p data-t>x</p></template>')));
        $this->assertTrue(Element::isHidden($this->el('<noscript><p data-t>x</p></noscript>')));
        $this->assertFalse(Element::isHidden($this->el('<p data-t aria-hidden="false">x</p>')));
        $this->assertFalse(Element::isHidden($this->el('<p data-t class="d-none">x</p>')), 'classes are not interpreted');
    }

    public function test_class_tokens_and_enum_attr(): void
    {
        $el = $this->el('<div data-t class="alert  alert-success yellow" scope="COL">x</div>');

        $this->assertSame(['alert', 'alert-success', 'yellow'], Element::classTokens($el));
        $this->assertTrue(Element::hasClassToken($el, 'alert'));
        $this->assertFalse(Element::hasClassToken($el, 'owl'), 'substring of "yellow" must not match');
        $this->assertSame('col', Element::enumAttr($el, 'scope'));
        $this->assertSame('', Element::enumAttr($el, 'missing'));
        $this->assertNull(Element::attr($el, 'missing'));
        $this->assertSame('COL', Element::attr($el, 'scope'));
    }

    public function test_roles_and_tabindex(): void
    {
        $this->assertSame(['none', 'presentation'], Element::roles($this->el('<table data-t role=" none  presentation ">')));
        $this->assertSame([], Element::roles($this->el('<div data-t role="">')));
        $this->assertSame([], Element::roles($this->el('<div data-t>')));
        $this->assertSame(-1, Element::tabindex($this->el('<div data-t tabindex="-1">')));
        $this->assertNull(Element::tabindex($this->el('<div data-t tabindex="abc">')));
        $this->assertNull(Element::tabindex($this->el('<div data-t>')));
    }

    public function test_interactive_focusable_and_disabled(): void
    {
        $this->assertTrue(Element::isNativelyInteractive($this->el('<a data-t href="/">x</a>')));
        $this->assertFalse(Element::isNativelyInteractive($this->el('<a data-t>x</a>')));
        $this->assertTrue(Element::isNativelyInteractive($this->el('<input data-t type="text">')));
        $this->assertFalse(Element::isNativelyInteractive($this->el('<input data-t type="hidden">')));
        $this->assertTrue(Element::isNativelyInteractive($this->el('<summary data-t>x</summary>')));
        $this->assertTrue(Element::isNativelyInteractive($this->el('<div data-t contenteditable="true">x</div>')));

        $this->assertTrue(Element::isFocusable($this->el('<button data-t>x</button>')));
        $this->assertFalse(Element::isFocusable($this->el('<button data-t disabled>x</button>')));
        $this->assertFalse(Element::isFocusable($this->el('<fieldset disabled><button data-t>x</button></fieldset>')));
        $this->assertFalse(Element::isFocusable($this->el('<button data-t tabindex="-1">x</button>')));
        $this->assertTrue(Element::isFocusable($this->el('<div data-t tabindex="0">x</div>')));
        $this->assertFalse(Element::isFocusable($this->el('<div data-t>x</div>')));
        $this->assertTrue(Element::isDisabled($this->el('<fieldset disabled><input data-t></fieldset>')));
    }

    public function test_previous_element_and_closest(): void
    {
        $doc = HtmlDocument::fromHtml('<html><body><label><span>Name</span> <input id="i"></label></body></html>');
        $input = $doc->query('//input')[0];

        $this->assertSame('span', Element::previousElement($input)->nodeName);
        $this->assertSame('label', Element::closest($input, 'label')->nodeName);
        $this->assertNull(Element::closest($input, 'form'));
        $this->assertSame('input', Element::tag($input));
    }

    public function test_hidden_style_must_be_a_whole_declaration(): void
    {
        $this->assertTrue(Element::isHidden($this->el('<p data-t style="color:red; display : none !important">x</p>')));
        $this->assertTrue(Element::isHidden($this->el('<p data-t style="VISIBILITY:HIDDEN">x</p>')));
        $this->assertFalse(Element::isHidden($this->el('<p data-t style="--display:none">x</p>')));
        $this->assertFalse(Element::isHidden($this->el('<p data-t style="display: none-ish">x</p>')));
        $this->assertFalse(Element::isHidden($this->el('<p data-t style="content-visibility: hidden">x</p>')));
    }

    public function test_not_rendered_ignores_aria_hidden(): void
    {
        $this->assertFalse(Element::isNotRendered($this->el('<div aria-hidden="true"><a data-t href="/">x</a></div>')));
        $this->assertTrue(Element::isHidden($this->el('<div aria-hidden="TRUE"><a data-t href="/">x</a></div>')));
        $this->assertTrue(Element::isNotRendered($this->el('<div hidden><a data-t href="/">x</a></div>')));
        $this->assertTrue(Element::isNotRendered($this->el('<div style="display:none"><a data-t href="/">x</a></div>')));
    }

    public function test_idrefs_split_on_any_whitespace(): void
    {
        $this->assertSame(['a', 'b', 'c'], Element::idrefs($this->el("<p data-t aria-describedby=\" a\tb\n c \">x</p>"), 'aria-describedby'));
        $this->assertSame([], Element::idrefs($this->el('<p data-t>x</p>'), 'aria-describedby'));
    }

    public function test_snippet_stops_serializing_large_subtrees(): void
    {
        $document = HtmlDocument::fromHtml('<html lang="en"><body class="page">'.str_repeat('<div class="row"><p>Paragraph text</p></div>', 60000).'</body></html>');
        $body = $document->body();

        $start = microtime(true);
        $snippet = Element::snippet($body);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(0.01, $elapsed, sprintf('snippet took %.3f s', $elapsed));
        $this->assertStringStartsWith('<body class="page"><div class="row"><p>Paragraph text</p></div>', $snippet);
        $this->assertSame(120, mb_strlen($snippet));
        $this->assertSame(Text::truncate(Text::normalize((string) $document->dom()->saveHTML($body)), 120), $snippet);
    }

    public function test_snippet_of_small_elements_equals_the_full_serialization(): void
    {
        foreach (['<img data-t src="a.jpg" alt="">', '<input data-t type="checkbox" checked disabled>', '<p data-t>a &amp; b <br> c</p>', '<a data-t href="/x?a=1&amp;b=2">x</a>', '<table data-t><tr><td>1</td></tr></table>'] as $html) {
            $element = $this->el($html);

            $this->assertSame(Text::truncate(Text::normalize((string) $element->ownerDocument->saveHTML($element)), 120), Element::snippet($element), $html);
        }
    }

    public function test_has_any_class_token_is_case_insensitive_and_whole_token(): void
    {
        $element = $this->el('<div data-t class="Alert  alert-success">x</div>');

        $this->assertTrue(Element::hasAnyClassToken($element, ['toast', 'alert']));
        $this->assertTrue(Element::hasAnyClassToken($element, ['ALERT-SUCCESS']));
        $this->assertFalse(Element::hasAnyClassToken($element, ['success', 'aler']));
        $this->assertFalse(Element::hasAnyClassToken($this->el('<div data-t>x</div>'), ['alert']));
    }
}
