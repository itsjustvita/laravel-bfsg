<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit\Css;

use ItsJustVita\LaravelBfsg\Css\Color;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class ColorTest extends TestCase
{
    public function test_parses_hex_forms(): void
    {
        $this->assertSame('#ffffff', Color::parse('#fff')->toHex());
        $this->assertSame('#112233', Color::parse('#123')->toHex());
        $this->assertSame('#aabbcc', Color::parse('#AABBCC')->toHex());
        $this->assertEqualsWithDelta(0.5, Color::parse('#00000080')->a, 0.01);
        $this->assertEqualsWithDelta(1.0, Color::parse('#000f')->a, 0.01);
        $this->assertNull(Color::parse('#12'));
    }

    public function test_parses_functional_and_named_forms(): void
    {
        $this->assertSame('#ff0000', Color::parse('rgb(255, 0, 0)')->toHex());
        $this->assertSame('#ff0000', Color::parse('rgb(255 0 0)')->toHex());
        $this->assertEqualsWithDelta(0.5, Color::parse('rgba(255,0,0,0.5)')->a, 0.01);
        $this->assertEqualsWithDelta(0.5, Color::parse('rgb(255 0 0 / 50%)')->a, 0.01);
        $this->assertSame('#ff0000', Color::parse('hsl(0, 100%, 50%)')->toHex());
        $this->assertSame('#00ff00', Color::parse('hsl(120 100% 50%)')->toHex());
        $this->assertSame('#000080', Color::parse('navy')->toHex());
        $this->assertSame('#f5f5f5', Color::parse('WhiteSmoke')->toHex());
        $this->assertSame('#c0c0c0', Color::parse('silver')->toHex());
        $this->assertEqualsWithDelta(0.0, Color::parse('transparent')->a, 0.001);
        $this->assertNull(Color::parse('currentColor'));
        $this->assertNull(Color::parse('var(--fg)'));
        $this->assertNull(Color::parse('inherit'));
        $this->assertNull(Color::parse(''));
    }

    public function test_luminance_and_contrast(): void
    {
        $black = Color::parse('#000');
        $white = Color::parse('#fff');

        $this->assertEqualsWithDelta(0.0, $black->luminance(), 0.0001);
        $this->assertEqualsWithDelta(1.0, $white->luminance(), 0.0001);
        $this->assertSame(21.0, $black->contrastWith($white));
        $this->assertSame(21.0, $white->contrastWith($black));
        $this->assertSame(4.54, Color::parse('#767676')->contrastWith($white));
        $this->assertSame(1.61, Color::parse('#ccc')->contrastWith($white));
    }

    public function test_alpha_compositing(): void
    {
        $half = Color::parse('rgba(0,0,0,0.5)');
        $composited = $half->over(Color::parse('#fff'));

        $this->assertEqualsWithDelta(1.0, $composited->a, 0.001);
        $this->assertSame('#808080', $composited->toHex());
        $this->assertSame('#ffffff', Color::parse('transparent')->over(Color::parse('#fff'))->toHex());
    }
}
