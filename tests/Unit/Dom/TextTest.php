<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit\Dom;

use ItsJustVita\LaravelBfsg\Dom\Text;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class TextTest extends TestCase
{
    public function test_normalize_collapses_whitespace_and_nbsp(): void
    {
        $this->assertSame('Zum Menü springen', Text::normalize("  Zum\n\t Menü\u{00A0}springen  "));
        $this->assertSame('', Text::normalize("\u{00A0}\n "));
    }

    public function test_truncate_is_multibyte_safe(): void
    {
        $long = str_repeat('a', 45).'Über 2025 wissen müssen';
        $short = Text::truncate($long, 50);

        $this->assertTrue(mb_check_encoding($short, 'UTF-8'));
        $this->assertLessThanOrEqual(50, mb_strlen($short));
        $this->assertStringEndsWith('…', $short);
        $this->assertSame('kurz', Text::truncate('kurz', 50));
        $this->assertNotFalse(json_encode(Text::truncate(str_repeat('x', 49).'ü', 50)));
    }

    public function test_lower_and_trailing_punctuation(): void
    {
        $this->assertSame('mehr erfahren', Text::lower('Mehr ERFAHREN'));
        $this->assertSame('Read more', Text::stripTrailingPunctuation('Read more »'));
        $this->assertSame('Weiterlesen', Text::stripTrailingPunctuation('Weiterlesen…'));
        $this->assertSame('Mehr erfahren', Text::stripTrailingPunctuation('Mehr erfahren →'));
        $this->assertSame('Details', Text::stripTrailingPunctuation('Details:'));
    }

    public function test_is_blank_and_length(): void
    {
        $this->assertTrue(Text::isBlank(null));
        $this->assertTrue(Text::isBlank("\u{00A0} "));
        $this->assertFalse(Text::isBlank('x'));
        $this->assertSame(2, Text::length('§5'));
        $this->assertSame(1, Text::length('→'));
    }

    public function test_trailing_marker_class_has_no_duplicates_and_still_strips_guillemets(): void
    {
        $this->assertSame('Weiter', Text::stripTrailingPunctuation('Weiter »»'));
        $this->assertSame('Weiter', Text::stripTrailingPunctuation('Weiter ›'));
    }

    public function test_contains_word_matches_whole_words_case_insensitively(): void
    {
        $this->assertTrue(Text::containsWord('Zum Inhalt springen', 'zum inhalt'));
        $this->assertTrue(Text::containsWord('ZUM MENÜ', 'zum menü'));
        $this->assertTrue(Text::containsWord('Skip to content', 'skip'));
        $this->assertFalse(Text::containsWord('Skipper', 'skip'));
        $this->assertFalse(Text::containsWord('Hauptmenü', 'menü'));
        $this->assertTrue(Text::containsAnyWord('Bericht (PDF, 2 MB)', ['download', 'pdf']));
        $this->assertFalse(Text::containsAnyWord('Bericht', ['download', 'pdf']));
    }
}
