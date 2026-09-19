<?php

namespace ItsJustVita\LaravelBfsg\Css;

final class Color
{
    /** CSS named colours. */
    public const NAMED = [
        'aliceblue' => '#f0f8ff', 'antiquewhite' => '#faebd7', 'aqua' => '#00ffff', 'aquamarine' => '#7fffd4',
        'azure' => '#f0ffff', 'beige' => '#f5f5dc', 'bisque' => '#ffe4c4', 'black' => '#000000',
        'blanchedalmond' => '#ffebcd', 'blue' => '#0000ff', 'blueviolet' => '#8a2be2', 'brown' => '#a52a2a',
        'burlywood' => '#deb887', 'cadetblue' => '#5f9ea0', 'chartreuse' => '#7fff00', 'chocolate' => '#d2691e',
        'coral' => '#ff7f50', 'cornflowerblue' => '#6495ed', 'cornsilk' => '#fff8dc', 'crimson' => '#dc143c',
        'cyan' => '#00ffff', 'darkblue' => '#00008b', 'darkcyan' => '#008b8b', 'darkgoldenrod' => '#b8860b',
        'darkgray' => '#a9a9a9', 'darkgreen' => '#006400', 'darkgrey' => '#a9a9a9', 'darkkhaki' => '#bdb76b',
        'darkmagenta' => '#8b008b', 'darkolivegreen' => '#556b2f', 'darkorange' => '#ff8c00', 'darkorchid' => '#9932cc',
        'darkred' => '#8b0000', 'darksalmon' => '#e9967a', 'darkseagreen' => '#8fbc8f', 'darkslateblue' => '#483d8b',
        'darkslategray' => '#2f4f4f', 'darkslategrey' => '#2f4f4f', 'darkturquoise' => '#00ced1', 'darkviolet' => '#9400d3',
        'deeppink' => '#ff1493', 'deepskyblue' => '#00bfff', 'dimgray' => '#696969', 'dimgrey' => '#696969',
        'dodgerblue' => '#1e90ff', 'firebrick' => '#b22222', 'floralwhite' => '#fffaf0', 'forestgreen' => '#228b22',
        'fuchsia' => '#ff00ff', 'gainsboro' => '#dcdcdc', 'ghostwhite' => '#f8f8ff', 'gold' => '#ffd700',
        'goldenrod' => '#daa520', 'gray' => '#808080', 'green' => '#008000', 'greenyellow' => '#adff2f',
        'grey' => '#808080', 'honeydew' => '#f0fff0', 'hotpink' => '#ff69b4', 'indianred' => '#cd5c5c',
        'indigo' => '#4b0082', 'ivory' => '#fffff0', 'khaki' => '#f0e68c', 'lavender' => '#e6e6fa',
        'lavenderblush' => '#fff0f5', 'lawngreen' => '#7cfc00', 'lemonchiffon' => '#fffacd', 'lightblue' => '#add8e6',
        'lightcoral' => '#f08080', 'lightcyan' => '#e0ffff', 'lightgoldenrodyellow' => '#fafad2', 'lightgray' => '#d3d3d3',
        'lightgreen' => '#90ee90', 'lightgrey' => '#d3d3d3', 'lightpink' => '#ffb6c1', 'lightsalmon' => '#ffa07a',
        'lightseagreen' => '#20b2aa', 'lightskyblue' => '#87cefa', 'lightslategray' => '#778899', 'lightslategrey' => '#778899',
        'lightsteelblue' => '#b0c4de', 'lightyellow' => '#ffffe0', 'lime' => '#00ff00', 'limegreen' => '#32cd32',
        'linen' => '#faf0e6', 'magenta' => '#ff00ff', 'maroon' => '#800000', 'mediumaquamarine' => '#66cdaa',
        'mediumblue' => '#0000cd', 'mediumorchid' => '#ba55d3', 'mediumpurple' => '#9370db', 'mediumseagreen' => '#3cb371',
        'mediumslateblue' => '#7b68ee', 'mediumspringgreen' => '#00fa9a', 'mediumturquoise' => '#48d1cc', 'mediumvioletred' => '#c71585',
        'midnightblue' => '#191970', 'mintcream' => '#f5fffa', 'mistyrose' => '#ffe4e1', 'moccasin' => '#ffe4b5',
        'navajowhite' => '#ffdead', 'navy' => '#000080', 'oldlace' => '#fdf5e6', 'olive' => '#808000',
        'olivedrab' => '#6b8e23', 'orange' => '#ffa500', 'orangered' => '#ff4500', 'orchid' => '#da70d6',
        'palegoldenrod' => '#eee8aa', 'palegreen' => '#98fb98', 'paleturquoise' => '#afeeee', 'palevioletred' => '#db7093',
        'papayawhip' => '#ffefd5', 'peachpuff' => '#ffdab9', 'peru' => '#cd853f', 'pink' => '#ffc0cb',
        'plum' => '#dda0dd', 'powderblue' => '#b0e0e6', 'purple' => '#800080', 'rebeccapurple' => '#663399',
        'red' => '#ff0000', 'rosybrown' => '#bc8f8f', 'royalblue' => '#4169e1', 'saddlebrown' => '#8b4513',
        'salmon' => '#fa8072', 'sandybrown' => '#f4a460', 'seagreen' => '#2e8b57', 'seashell' => '#fff5ee',
        'sienna' => '#a0522d', 'silver' => '#c0c0c0', 'skyblue' => '#87ceeb', 'slateblue' => '#6a5acd',
        'slategray' => '#708090', 'slategrey' => '#708090', 'snow' => '#fffafa', 'springgreen' => '#00ff7f',
        'steelblue' => '#4682b4', 'tan' => '#d2b48c', 'teal' => '#008080', 'thistle' => '#d8bfd8',
        'tomato' => '#ff6347', 'turquoise' => '#40e0d0', 'violet' => '#ee82ee', 'wheat' => '#f5deb3',
        'white' => '#ffffff', 'whitesmoke' => '#f5f5f5', 'yellow' => '#ffff00', 'yellowgreen' => '#9acd32',
    ];

    public function __construct(
        public readonly float $r,
        public readonly float $g,
        public readonly float $b,
        public readonly float $a = 1.0,
    ) {}

    public static function parse(string $value): ?self
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            return null;
        }

        if ($value === 'transparent') {
            return new self(0, 0, 0, 0.0);
        }

        if (isset(self::NAMED[$value])) {
            $value = self::NAMED[$value];
        }

        if (preg_match('/^#([0-9a-f]{3,8})$/', $value, $m) === 1) {
            return self::fromHex($m[1]);
        }

        if (preg_match('/^rgba?\((.+)\)$/', $value, $m) === 1) {
            return self::fromChannels($m[1], false);
        }

        if (preg_match('/^hsla?\((.+)\)$/', $value, $m) === 1) {
            return self::fromChannels($m[1], true);
        }

        return null;
    }

    public function luminance(): float
    {
        $channel = function (float $c): float {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($this->r) + 0.7152 * $channel($this->g) + 0.0722 * $channel($this->b);
    }

    public function contrastWith(self $other): float
    {
        $l1 = $this->luminance();
        $l2 = $other->luminance();

        return round((max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05), 2);
    }

    public function over(self $background): self
    {
        $a = $this->a + $background->a * (1 - $this->a);

        if ($a <= 0) {
            return new self(0, 0, 0, 0.0);
        }

        $mix = fn (float $fg, float $bg): float => ($fg * $this->a + $bg * $background->a * (1 - $this->a)) / $a;

        return new self($mix($this->r, $background->r), $mix($this->g, $background->g), $mix($this->b, $background->b), $a);
    }

    public function toHex(): string
    {
        return sprintf('#%02x%02x%02x', (int) round($this->r), (int) round($this->g), (int) round($this->b));
    }

    private static function fromHex(string $hex): ?self
    {
        $length = strlen($hex);

        if ($length === 3 || $length === 4) {
            $hex = implode('', array_map(fn (string $c) => $c.$c, str_split($hex)));
            $length *= 2;
        }

        if ($length !== 6 && $length !== 8) {
            return null;
        }

        $alpha = $length === 8 ? hexdec(substr($hex, 6, 2)) / 255 : 1.0;

        return new self(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), $alpha);
    }

    private static function fromChannels(string $inner, bool $hsl): ?self
    {
        $inner = str_replace('/', ' ', $inner);
        $parts = preg_split('/[\s,]+/', trim($inner), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) < 3) {
            return null;
        }

        $alpha = isset($parts[3]) ? self::number($parts[3], 1) : 1.0;

        if ($hsl) {
            [$r, $g, $b] = self::hslToRgb((float) $parts[0], self::number($parts[1], 100) / 100, self::number($parts[2], 100) / 100);

            return new self($r, $g, $b, max(0.0, min(1.0, $alpha)));
        }

        return new self(self::number($parts[0], 255), self::number($parts[1], 255), self::number($parts[2], 255), max(0.0, min(1.0, $alpha)));
    }

    /** "50%" → 50 % of $scale, plain numbers unchanged. */
    private static function number(string $token, float $scale): float
    {
        if (str_ends_with($token, '%')) {
            return (float) rtrim($token, '%') / 100 * $scale;
        }

        return (float) $token;
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function hslToRgb(float $h, float $s, float $l): array
    {
        $h = fmod($h, 360) / 360;
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;

        $channel = function (float $t) use ($p, $q): float {
            if ($t < 0) {
                $t += 1;
            }
            if ($t > 1) {
                $t -= 1;
            }
            if ($t < 1 / 6) {
                return $p + ($q - $p) * 6 * $t;
            }
            if ($t < 1 / 2) {
                return $q;
            }
            if ($t < 2 / 3) {
                return $p + ($q - $p) * (2 / 3 - $t) * 6;
            }

            return $p;
        };

        return [$channel($h + 1 / 3) * 255, $channel($h) * 255, $channel($h - 1 / 3) * 255];
    }
}
