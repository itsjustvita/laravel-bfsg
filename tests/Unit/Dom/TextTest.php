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
}
