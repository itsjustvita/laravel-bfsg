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

    public function test_contrast_ratio_is_unrounded(): void
    {
        $ratio = Color::parse('#777')->contrastRatio(Color::parse('#fff'));

        $this->assertEqualsWithDelta(4.4781, $ratio, 0.0001);
        $this->assertLessThan(4.5, $ratio);
        $this->assertSame(4.48, Color::parse('#777')->contrastWith(Color::parse('#fff')));
    }

    public function test_unresolvable_values(): void
    {
        foreach (['inherit', 'initial', 'unset', 'currentColor', 'var(--fg)', 'calc(1px)', 'color-mix(in srgb, red, blue)'] as $value) {
            $this->assertTrue(Color::isUnresolvable($value), $value);
        }

        $this->assertFalse(Color::isUnresolvable('#fff'));
        $this->assertFalse(Color::isUnresolvable('transparent'));
    }

    public function test_background_values(): void
    {
        [$color, $approximate] = Color::fromBackground('url("a;b.png") no-repeat center / cover #123456');
        $this->assertSame('#123456', $color->toHex());
        $this->assertFalse($approximate);

        [$color, $approximate] = Color::fromBackground('linear-gradient(180deg, rgba(0,0,0,1) 0%, #fff 100%)');
        $this->assertSame('#000000', $color->toHex());
        $this->assertTrue($approximate);

        $this->assertSame([null, false], Color::fromBackground('none'));
        $this->assertSame([null, true], Color::fromBackground('var(--surface)'));
        $this->assertSame(0.0, Color::fromBackground('transparent')[0]->a);
        $this->assertSame([null, false], Color::fromBackground('url(red.png)'));
    }

    public function test_parses_oklch_and_oklab(): void
    {
        $this->assertSame('#d1d5dc', Color::parse('oklch(87.2% .01 258.338)')->toHex(), 'Tailwind v4 gray-300');
        $this->assertSame('#4f39f6', Color::parse('OKLCH(51.1% 0.262 276.966)')->toHex(), 'Tailwind v4 indigo-600');
        $this->assertSame('#ffffff', Color::parse('oklch(1 0 0)')->toHex());
        $this->assertSame('#000000', Color::parse('oklch(0% 0 none)')->toHex());
        $this->assertSame('#ff0000', Color::parse('oklch(62.8% 0.2577 29.23deg)')->toHex(), 'out-of-gamut channels are clamped');
        $this->assertSame(Color::parse('oklch(50% 0.1 180)')->toHex(), Color::parse('oklch(50% 0.1 0.5turn)')->toHex());
        $this->assertEqualsWithDelta(0.5, Color::parse('oklab(0.5 0.1 -0.1 / 50%)')->a, 0.001);
        $this->assertSame('#717171', Color::parse('oklab(55% 0 0)')->toHex());
        $this->assertNull(Color::parse('oklch(bad)'));
        $this->assertNull(Color::parse('oklch(50% 0.1)'));

        [$color, $approximate] = Color::fromBackground('oklch(98.5% 0.002 247.839) url(x.png)');
        $this->assertSame('#f9fafb', $color->toHex());
        $this->assertFalse($approximate);
    }
}
