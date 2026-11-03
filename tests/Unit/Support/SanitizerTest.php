<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit\Support;

use ItsJustVita\LaravelBfsg\Support\Sanitizer;
use League\CommonMark\CommonMarkConverter;
use PHPUnit\Framework\TestCase;

class SanitizerTest extends TestCase
{
    public function test_terminal_removes_escape_and_bidi_controls(): void
    {
        $out = Sanitizer::terminal("a\x1b]0;PWNED\x07\x1b[2Jb\u{0085}c\u{202E}d");
        $this->assertStringNotContainsString("\x1b", $out);
        $this->assertStringNotContainsString("\x07", $out);
        $this->assertStringNotContainsString("\u{0085}", $out);
        $this->assertStringNotContainsString("\u{202E}", $out);
    }

    public function test_terminal_keeps_german_and_emoji(): void
    {
        $text = "Ärger über Straße 😀 – „Zitat“\n\tEnde";
        $this->assertSame($text, Sanitizer::terminal($text));
    }

    public function test_terminal_scrubs_invalid_utf8(): void
    {
        $this->assertTrue(mb_check_encoding(Sanitizer::terminal("caf\xe9"), 'UTF-8'));
    }

    public function test_terminal_removes_exactly_the_listed_code_points(): void
    {
        $removed = [0x00, 0x08, 0x0B, 0x0C, 0x0D, 0x1B, 0x1F, 0x7F, 0x80, 0x9B, 0x9F, 0x202A, 0x202E, 0x2066, 0x2069];
        $kept = [0x09, 0x0A, 0x20, 0x7E, 0xA0, 0x2029, 0x202F, 0x2065, 0x206A];

        foreach ($removed as $codePoint) {
            $this->assertSame('ab', Sanitizer::terminal('a'.mb_chr($codePoint, 'UTF-8').'b'), sprintf('U+%04X is removed', $codePoint));
        }

        foreach ($kept as $codePoint) {
            $text = 'a'.mb_chr($codePoint, 'UTF-8').'b';
            $this->assertSame($text, Sanitizer::terminal($text), sprintf('U+%04X is kept', $codePoint));
        }
    }

    public function test_markdown_cannot_inject_inline_markup(): void
    {
        $payload = 'a.png) **All checks passed** ![ok](https://evil.example/badge.png';
        $html = (new CommonMarkConverter)->convert('Image: '.Sanitizer::markdown($payload))->getContent();
        $this->assertStringNotContainsString('<strong>', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_markdown_escapes_entities_then_inline_punctuation(): void
    {
        $this->assertSame(
            'a\\\\b\\`c\\*d\\_e\\[f\\]g\\(h\\)i\\!j\\#k\\|l\\~m&lt;n&gt;o&amp;p. Ärger 😀',
            Sanitizer::markdown("a\\b`c*d_e[f]g(h)i!j#k|l~m<n>o&p.\x1b Ärger 😀"),
        );
    }

    public function test_truncate_is_multibyte_safe_and_marks_the_cut(): void
    {
        $this->assertSame('Straße 😀', Sanitizer::truncate('Straße 😀', 8));
        $this->assertSame('Straß…', Sanitizer::truncate('Straße 😀 Ende', 6));
        $this->assertSame('😀😀…', Sanitizer::truncate('😀😀😀😀', 3));
        $this->assertSame('…', Sanitizer::truncate('abc', 1));
        $this->assertSame('', Sanitizer::truncate('abc', 0));
        $this->assertTrue(mb_check_encoding(Sanitizer::truncate("caf\xe9 au lait", 4), 'UTF-8'));
    }
}
