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

    private const CASCADE_KEYWORDS = ['inherit', 'initial', 'unset', 'revert', 'revert-layer', 'currentcolor'];

    private const SYSTEM_COLORS = [
        'canvas', 'canvastext', 'buttonface', 'buttontext', 'buttonborder', 'field', 'fieldtext', 'highlight',
        'highlighttext', 'linktext', 'visitedtext', 'activetext', 'graytext', 'mark', 'marktext',
        'selecteditem', 'selecteditemtext', 'accentcolor', 'accentcolortext',
    ];

    /** Functions in a background value that paint an image, not a colour. */
    private const IMAGE_FUNCTION = '/^(?:-[a-z]+-)?(?:image-set|image|cross-fade|element|paint|(?:repeating-)?(?:linear|radial|conic)-gradient)$/';

    /** Longest colour function we evaluate; anything longer is reported as approximate. */
    private const MAX_FUNCTION_LENGTH = 1024;

    /** Linear-light sRGB (D65) from XYZ (D65), CSS Color 4 §10.4. */
    private const XYZ_TO_SRGB = [
        [3.2409699419045226, -1.537383177570094, -0.4986107602930034],
        [-0.9692436362808796, 1.8759675015077202, 0.04155505740717559],
        [0.05563007969699366, -0.20397695888897652, 1.0569715142428786],
    ];

    /** Linear-light display-p3 to XYZ (D65), CSS Color 4 §10.4. */
    private const P3_TO_XYZ = [
        [0.4865709486482162, 0.26566769316909306, 0.1982172852343625],
        [0.2289745640697488, 0.6917385218365064, 0.079286914093745],
        [0.0, 0.04511338185890264, 1.043944368900976],
    ];

    /** Bradford chromatic adaptation D50 to D65, CSS Color 4 §10.6. */
    private const D50_TO_D65 = [
        [0.955473421488075, -0.02309845494876471, 0.06325924320057072],
        [-0.0283697093338637, 1.0099953980813041, 0.021041441191917323],
        [0.012314014864481998, -0.020507649298898964, 1.330365926242124],
    ];

    public static function parse(string $value): ?self
    {
        $result = self::evaluate($value);

        return $result['approximate'] ? null : $result['color'];
    }

    /**
     * Evaluates a colour value. `approximate` is true when the colour is not known exactly: functions and
     * keywords that depend on context (light-dark, relative colours, system colours, cascade keywords,
     * unsupported colour spaces) and colour-mix in a space that is not computed here.
     *
     * @return array{color: ?self, approximate: bool}
     */
    public static function evaluate(string $value): array
    {
        $value = strtolower(trim($value));

        if ($value === '' || $value === 'none') {
            return ['color' => null, 'approximate' => false];
        }

        if ($value === 'transparent') {
            return ['color' => new self(0, 0, 0, 0.0), 'approximate' => false];
        }

        if (in_array($value, self::CASCADE_KEYWORDS, true) || in_array($value, self::SYSTEM_COLORS, true)) {
            return ['color' => null, 'approximate' => true];
        }

        if (isset(self::NAMED[$value])) {
            $value = self::NAMED[$value];
        }

        if ($value[0] === '#') {
            return ['color' => preg_match('/^#([0-9a-f]{3,8})$/D', $value, $m) === 1 ? self::fromHex($m[1]) : null, 'approximate' => false];
        }

        $function = self::splitFunction($value);

        if ($function === null) {
            return ['color' => null, 'approximate' => false];
        }

        [$name, $inner] = $function;

        if (strlen($inner) > self::MAX_FUNCTION_LENGTH) {
            return ['color' => null, 'approximate' => true];
        }

        if ($name === 'color-mix') {
            return self::colorMix($inner);
        }

        $color = match ($name) {
            'rgb', 'rgba' => self::fromChannels($inner, false),
            'hsl', 'hsla' => self::fromChannels($inner, true),
            'hwb' => self::fromHwb($inner),
            'lab' => self::fromLab($inner, false),
            'lch' => self::fromLab($inner, true),
            'oklab' => self::fromOklab($inner, false),
            'oklch' => self::fromOklab($inner, true),
            'color' => self::fromColorFunction($inner),
            default => null,
        };

        if ($color !== null && ! (is_finite($color->r) && is_finite($color->g) && is_finite($color->b) && is_finite($color->a))) {
            $color = null;
        }

        return ['color' => $color, 'approximate' => $color === null];
    }

    /** Values that cannot be resolved statically: the caller walks up the tree and marks the result approximate. */
    public static function isUnresolvable(string $value): bool
    {
        $value = strtolower(trim($value));

        return in_array($value, self::CASCADE_KEYWORDS, true)
            || preg_match('/\b(?:var|calc|env|attr)\(/', $value) === 1
            || self::evaluate($value)['approximate'];
    }

    /**
     * Colour of a background / background-color value: url() is stripped first, gradients yield their
     * first colour stop (approximate), `none`/`transparent` yield a transparent colour, unresolvable
     * values yield null + approximate.
     *
     * @return array{0: ?self, 1: bool} colour (null = not set or unresolvable) and whether it is approximate
     */
    public static function fromBackground(string $value): array
    {
        $value = strtolower(trim($value));
        $value = trim(preg_replace('/url\((?:[^()\'"]|\'[^\']*\'|"[^"]*")*+\)/', ' ', $value) ?? $value);

        if ($value === '' || $value === 'none') {
            return [null, false];
        }

        $haystack = $value;
        $gradient = false;

        if (preg_match('/(?:repeating-)?(?:linear|radial|conic)-gradient\(/', $value, $m, PREG_OFFSET_CAPTURE) === 1) {
            $start = $m[0][1] + strlen($m[0][0]);
            $end = strrpos($value, ')');

            if ($end !== false && $end >= $start) {
                $gradient = true;
                $haystack = substr($value, $start, $end - $start);
            }
        }

        $unresolved = false;

        foreach (self::tokens($haystack) as $token) {
            if (str_contains($token, '(') && preg_match(self::IMAGE_FUNCTION, substr($token, 0, (int) strpos($token, '('))) === 1) {
                continue;
            }

            $result = self::evaluate($token);

            if ($result['color'] !== null) {
                return [$result['color'], $gradient || $result['approximate']];
            }

            $unresolved = $unresolved || $result['approximate'];
        }

        return [null, $gradient || $unresolved];
    }

    /**
     * Colour-like tokens of a CSS value in one linear pass: hex colours, identifiers and whole function calls
     * with balanced parentheses.
     *
     * @return list<string>
     */
    private static function tokens(string $text): array
    {
        $tokens = [];
        $length = strlen($text);
        $i = 0;

        while ($i < $length) {
            $c = $text[$i];

            if ($c === '#') {
                $j = $i + 1;
                while ($j < $length && ctype_xdigit($text[$j])) {
                    $j++;
                }
                $tokens[] = substr($text, $i, $j - $i);
                $i = $j;

                continue;
            }

            if (! ctype_alpha($c) && ! ($c === '-' && $i + 1 < $length && ctype_alpha($text[$i + 1]))) {
                $i++;

                continue;
            }

            $j = $i + 1;
            while ($j < $length && (ctype_alnum($text[$j]) || $text[$j] === '-')) {
                $j++;
            }

            if ($j < $length && $text[$j] === '(') {
                $depth = 0;
                for ($k = $j; $k < $length; $k++) {
                    if ($text[$k] === '(') {
                        $depth++;
                    } elseif ($text[$k] === ')' && --$depth === 0) {
                        break;
                    }
                }
                $j = min($k + 1, $length);
            }

            $tokens[] = substr($text, $i, $j - $i);
            $i = $j;
        }

        return $tokens;
    }

    /** "name(inner)" spanning the whole value, with balanced parentheses, or null. @return ?array{0: string, 1: string} */
    private static function splitFunction(string $value): ?array
    {
        if (preg_match('/^([a-z][a-z0-9-]*+)\(/', $value, $m) !== 1) {
            return null;
        }

        $start = strlen($m[0]);
        $length = strlen($value);
        $depth = 1;

        for ($i = $start; $i < $length; $i++) {
            if ($value[$i] === '(') {
                $depth++;
            } elseif ($value[$i] === ')' && --$depth === 0) {
                return $i === $length - 1 ? [$m[1], substr($value, $start, $i - $start)] : null;
            }
        }

        return null;
    }

    /** Splits at a delimiter outside parentheses; ' ' stands for any whitespace and drops empty parts. @return ?list<string> */
    private static function splitTopLevel(string $text, string $delimiter): ?array
    {
        $parts = [];
        $current = '';
        $depth = 0;

        for ($i = 0, $length = strlen($text); $i < $length; $i++) {
            $c = $text[$i];

            if ($c === '(') {
                $depth++;
            } elseif ($c === ')' && --$depth < 0) {
                return null;
            }

            if ($depth === 0 && ($delimiter === ' ' ? ctype_space($c) : $c === $delimiter)) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $c;
        }

        $parts[] = $current;

        return $depth === 0 ? ($delimiter === ' ' ? array_values(array_filter($parts, fn (string $p) => $p !== '')) : $parts) : null;
    }

    /**
     * color-mix(in <space> [<hue> hue], <colour> [<pct>], <colour> [<pct>]) per CSS Color 5. Computed for
     * srgb, srgb-linear, oklab and oklch; other spaces fall back to the first colour (approximate).
     *
     * @return array{color: ?self, approximate: bool}
     */
    private static function colorMix(string $inner): array
    {
        $unknown = ['color' => null, 'approximate' => true];
        $parts = self::splitTopLevel($inner, ',');

        if ($parts === null || count($parts) !== 3) {
            return $unknown;
        }

        $head = preg_split('/\s+/', trim($parts[0]), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($head) < 2 || $head[0] !== 'in') {
            return $unknown;
        }

        $hueMethod = 'shorter';

        if (count($head) > 2) {
            if (count($head) !== 4 || $head[3] !== 'hue' || ! in_array($head[2], ['shorter', 'longer', 'increasing', 'decreasing'], true)) {
                return $unknown;
            }
            $hueMethod = $head[2];
        }

        $args = [self::mixArgument($parts[1]), self::mixArgument($parts[2])];

        if ($args[0] === null || $args[1] === null) {
            return $unknown;
        }

        $first = $args[0]['result'];
        $exact = $first['color'] !== null && ! $first['approximate'] && $args[1]['result']['color'] !== null && ! $args[1]['result']['approximate'];

        if (! in_array($head[1], ['srgb', 'srgb-linear', 'oklab', 'oklch'], true) || ! $exact) {
            return ['color' => $first['color'], 'approximate' => true];
        }

        [$p1, $p2] = [$args[0]['percent'], $args[1]['percent']];
        [$p1, $p2] = match (true) {
            $p1 === null && $p2 === null => [50.0, 50.0],
            $p1 === null => [100 - $p2, $p2],
            $p2 === null => [$p1, 100 - $p1],
            default => [$p1, $p2],
        };
        $sum = $p1 + $p2;

        if ($sum <= 0) {
            return $unknown;
        }

        $multiplier = $sum < 100 ? $sum / 100 : 1.0;
        $weights = [$p1 / $sum, $p2 / $sum];
        $colors = [$first['color'], $args[1]['result']['color']];
        $polar = $head[1] === 'oklch';

        $alpha = $colors[0]->a * $weights[0] + $colors[1]->a * $weights[1];

        if ($alpha <= 0) {
            return ['color' => new self(0, 0, 0, 0.0), 'approximate' => false];
        }

        $components = array_map(fn (self $c) => self::toMixSpace($c, $head[1]), $colors);
        $mixed = [];

        foreach ([0, 1, 2] as $i) {
            if ($polar && $i === 2) {
                $mixed[2] = self::mixHue($components[0][2], $components[1][2], $weights[1], $hueMethod);

                continue;
            }

            $mixed[$i] = ($components[0][$i] * $colors[0]->a * $weights[0] + $components[1][$i] * $colors[1]->a * $weights[1]) / $alpha;
        }

        $alpha = max(0.0, min(1.0, $alpha * $multiplier));

        $color = match ($head[1]) {
            'srgb' => new self(self::clamp($mixed[0] * 255, 0, 255), self::clamp($mixed[1] * 255, 0, 255), self::clamp($mixed[2] * 255, 0, 255), $alpha),
            'srgb-linear' => self::linearToColor($mixed, $alpha),
            'oklab' => self::oklabToColor($mixed[0], $mixed[1], $mixed[2], $alpha),
            default => self::oklabToColor($mixed[0], $mixed[1] * cos(deg2rad($mixed[2])), $mixed[1] * sin(deg2rad($mixed[2])), $alpha),
        };

        return ['color' => $color, 'approximate' => false];
    }

    /** One colour-mix operand: a colour with an optional leading or trailing percentage. @return ?array{result: array{color: ?self, approximate: bool}, percent: ?float} */
    private static function mixArgument(string $text): ?array
    {
        $tokens = self::splitTopLevel(trim($text), ' ');
        $isPercent = fn (string $t): bool => strlen($t) < 32 && preg_match('/^(?:\d++(?:\.\d*+)?|\.\d++)%$/D', $t) === 1;
        $percent = null;

        if ($tokens === null || $tokens === []) {
            return null;
        }

        if (count($tokens) > 1 && $isPercent($tokens[0])) {
            $percent = (float) array_shift($tokens);
        } elseif (count($tokens) > 1 && $isPercent($tokens[array_key_last($tokens)])) {
            $percent = (float) array_pop($tokens);
        }

        if ($percent !== null && $percent > 100) {
            return null;
        }

        $result = self::evaluate(implode(' ', $tokens));

        if ($result['color'] === null && ! $result['approximate']) {
            return null;
        }

        return ['result' => $result, 'percent' => $percent];
    }

    /** @return array{0: float, 1: float, 2: ?float} components of a colour in a colour-mix space (hue is null when powerless) */
    private static function toMixSpace(self $color, string $space): array
    {
        $srgb = [$color->r / 255, $color->g / 255, $color->b / 255];

        if ($space === 'srgb') {
            return $srgb;
        }

        $linear = array_map(self::decode(...), $srgb);

        if ($space === 'srgb-linear') {
            return $linear;
        }

        [$l, $a, $b] = self::linearToOklab($linear);

        if ($space === 'oklab') {
            return [$l, $a, $b];
        }

        $chroma = hypot($a, $b);

        return [$l, $chroma, $chroma < 0.0001 ? null : fmod(rad2deg(atan2($b, $a)) + 360, 360)];
    }

    private static function mixHue(?float $h1, ?float $h2, float $weight, string $method): float
    {
        if ($h1 === null || $h2 === null) {
            return $h1 ?? $h2 ?? 0.0;
        }

        $delta = $h2 - $h1;

        match ($method) {
            'longer' => $delta = $delta > 0 && $delta < 180 ? $delta - 360 : ($delta > -180 && $delta <= 0 ? $delta + 360 : $delta),
            'increasing' => $delta = $delta < 0 ? $delta + 360 : $delta,
            'decreasing' => $delta = $delta > 0 ? $delta - 360 : $delta,
            default => $delta = $delta > 180 ? $delta - 360 : ($delta < -180 ? $delta + 360 : $delta),
        };

        return fmod(fmod($h1 + $delta * $weight, 360) + 360, 360);
    }

    public function luminance(): float
    {
        $channel = function (float $c): float {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($this->r) + 0.7152 * $channel($this->g) + 0.0722 * $channel($this->b);
    }

    /** Unrounded WCAG contrast ratio; compare thresholds against this value. */
    public function contrastRatio(self $other): float
    {
        $l1 = $this->luminance();
        $l2 = $other->luminance();

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    /** Contrast ratio rounded to two decimals, for display. */
    public function contrastWith(self $other): float
    {
        return round($this->contrastRatio($other), 2);
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

    /** Numeric channel token (number, percentage, angle or `none`), bounded in length. */
    private static function isChannel(string $token): bool
    {
        return $token === 'none' || (strlen($token) <= 32
            && preg_match('/^[+-]?+(?:\d++(?:\.\d*+)?|\.\d++)(?:e[+-]?+\d++)?+(?:%|deg|rad|grad|turn)?+$/D', $token) === 1
            && is_finite((float) $token));
    }

    /**
     * Splits the arguments of a colour function into channel tokens and an optional alpha token.
     * Legacy rgb()/hsl() also accept commas and a fourth channel as alpha.
     *
     * @return ?array{0: list<string>, 1: ?string}
     */
    private static function channelTokens(string $inner, bool $legacy, int $count = 3): ?array
    {
        $sides = explode('/', $inner);

        if (count($sides) > 2) {
            return null;
        }

        $channels = preg_split($legacy ? '/[\s,]+/' : '/\s+/', trim($sides[0]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $alpha = null;

        if (isset($sides[1])) {
            $alphaTokens = preg_split('/\s+/', trim($sides[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if (count($alphaTokens) !== 1) {
                return null;
            }

            $alpha = $alphaTokens[0];
        } elseif ($legacy && count($channels) === $count + 1) {
            $alpha = array_pop($channels);
        }

        if (count($channels) !== $count) {
            return null;
        }

        foreach ($alpha === null ? $channels : [...$channels, $alpha] as $token) {
            if (! self::isChannel($token)) {
                return null;
            }
        }

        return [$channels, $alpha];
    }

    private static function alpha(?string $token): float
    {
        return $token === null ? 1.0 : self::clamp(self::number($token, 1), 0.0, 1.0);
    }

    private static function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    private static function fromChannels(string $inner, bool $hsl): ?self
    {
        $tokens = self::channelTokens($inner, true);

        if ($tokens === null || ($hsl && str_ends_with($tokens[0][0], '%'))) {
            return null;
        }

        [$p, $alpha] = $tokens;
        $alpha = self::alpha($alpha);

        if ($hsl) {
            [$r, $g, $b] = self::hslToRgb(
                self::degrees($p[0]),
                self::clamp(self::number($p[1], 100) / 100, 0.0, 1.0),
                self::clamp(self::number($p[2], 100) / 100, 0.0, 1.0),
            );

            return new self($r, $g, $b, $alpha);
        }

        return new self(
            self::clamp(self::number($p[0], 255), 0.0, 255.0),
            self::clamp(self::number($p[1], 255), 0.0, 255.0),
            self::clamp(self::number($p[2], 255), 0.0, 255.0),
            $alpha,
        );
    }

    /** hwb(H W B [/ A]); W and B are normalised when they sum to more than 100% (CSS Color 4 §8). */
    private static function fromHwb(string $inner): ?self
    {
        $tokens = self::channelTokens($inner, false);

        if ($tokens === null || str_ends_with($tokens[0][0], '%')) {
            return null;
        }

        [$p, $alpha] = $tokens;
        $white = self::clamp(self::number($p[1], 100) / 100, 0.0, 1.0);
        $black = self::clamp(self::number($p[2], 100) / 100, 0.0, 1.0);
        $alpha = self::alpha($alpha);

        if ($white + $black >= 1) {
            $gray = $white / ($white + $black) * 255;

            return new self($gray, $gray, $gray, $alpha);
        }

        $rgb = self::hslToRgb(self::degrees($p[0]), 1.0, 0.5);
        $scale = 1 - $white - $black;

        [$r, $g, $b] = array_map(fn (float $c) => ($c / 255 * $scale + $white) * 255, $rgb);

        return new self($r, $g, $b, $alpha);
    }

    /**
     * lab(L a b) or lch(L C H): CIE Lab with the D50 white point, adapted to D65 with Bradford (CSS Color 4 §9).
     * L is 0..100 or a percentage; a and b accept percentages of 125, C of 150.
     */
    private static function fromLab(string $inner, bool $polar): ?self
    {
        $tokens = self::channelTokens($inner, false);

        if ($tokens === null || ($polar && str_ends_with($tokens[0][2], '%'))) {
            return null;
        }

        [$p, $alpha] = $tokens;
        $lightness = self::clamp(self::number($p[0], 100), 0.0, 100.0);

        if ($polar) {
            $chroma = max(0.0, self::number($p[1], 150));
            $hue = deg2rad(self::degrees($p[2]));
            [$a, $b] = [$chroma * cos($hue), $chroma * sin($hue)];
        } else {
            [$a, $b] = [self::number($p[1], 125), self::number($p[2], 125)];
        }

        $epsilon = 216 / 24389;
        $kappa = 24389 / 27;
        $fy = ($lightness + 16) / 116;
        $fx = $a / 500 + $fy;
        $fz = $fy - $b / 200;

        $xyzD50 = [
            ($fx ** 3 > $epsilon ? $fx ** 3 : (116 * $fx - 16) / $kappa) * 0.3457 / 0.3585,
            $lightness > $kappa * $epsilon ? $fy ** 3 : $lightness / $kappa,
            ($fz ** 3 > $epsilon ? $fz ** 3 : (116 * $fz - 16) / $kappa) * (1 - 0.3457 - 0.3585) / 0.3585,
        ];

        return self::linearToColor(self::multiply(self::XYZ_TO_SRGB, self::multiply(self::D50_TO_D65, $xyzD50)), self::alpha($alpha));
    }

    /** color(srgb|srgb-linear|display-p3 r g b [/ a]), clamped to the sRGB gamut; other spaces are not evaluated. */
    private static function fromColorFunction(string $inner): ?self
    {
        $head = preg_split('/\s+/', trim($inner), 2) ?: [];

        if (count($head) !== 2 || ! in_array($head[0], ['srgb', 'srgb-linear', 'display-p3'], true)) {
            return null;
        }

        $tokens = self::channelTokens($head[1], false);

        if ($tokens === null || preg_grep('/(?:deg|rad|grad|turn)$/', $tokens[0]) !== []) {
            return null;
        }

        $channels = array_map(fn (string $t) => self::number($t, 1), $tokens[0]);
        $alpha = self::alpha($tokens[1]);

        return match ($head[0]) {
            'srgb' => new self(self::clamp($channels[0] * 255, 0.0, 255.0), self::clamp($channels[1] * 255, 0.0, 255.0), self::clamp($channels[2] * 255, 0.0, 255.0), $alpha),
            'srgb-linear' => self::linearToColor($channels, $alpha),
            default => self::linearToColor(
                self::multiply(self::XYZ_TO_SRGB, self::multiply(self::P3_TO_XYZ, array_map(self::decode(...), $channels))),
                $alpha,
            ),
        };
    }

    /**
     * oklab(L a b [/ alpha]) or oklch(L C H [/ alpha]) → sRGB, clamped to the gamut. L is 0..1 or a percentage;
     * a, b and C accept percentages of 0.4; H is in degrees unless it carries deg/rad/grad/turn; `none` is 0.
     */
    private static function fromOklab(string $inner, bool $polar): ?self
    {
        $tokens = self::channelTokens($inner, false);

        if ($tokens === null || ($polar && str_ends_with($tokens[0][2], '%'))) {
            return null;
        }

        [$p, $alpha] = $tokens;
        $lightness = self::number($p[0], 1);

        if ($polar) {
            $chroma = self::number($p[1], 0.4);
            $hue = deg2rad(self::degrees($p[2]));
            [$a, $b] = [$chroma * cos($hue), $chroma * sin($hue)];
        } else {
            [$a, $b] = [self::number($p[1], 0.4), self::number($p[2], 0.4)];
        }

        return self::oklabToColor($lightness, $a, $b, self::alpha($alpha));
    }

    /** Björn Ottosson's Oklab (https://bottosson.github.io/posts/oklab/), gamut-clamped. */
    private static function oklabToColor(float $lightness, float $a, float $b, float $alpha): self
    {
        $l = ($lightness + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($lightness - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($lightness - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        return self::linearToColor([
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ], $alpha);
    }

    /** @param  array{0: float, 1: float, 2: float}  $linear  @return array{0: float, 1: float, 2: float} */
    private static function linearToOklab(array $linear): array
    {
        [$r, $g, $b] = $linear;
        $l = self::cubeRoot(0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b);
        $m = self::cubeRoot(0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b);
        $s = self::cubeRoot(0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b);

        return [
            0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s,
            1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s,
            0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s,
        ];
    }

    /** Linear-light sRGB (0..1) to a gamma-encoded colour, clipped to the gamut. @param  list<float>  $linear */
    private static function linearToColor(array $linear, float $alpha): self
    {
        [$r, $g, $b] = array_map(function (float $channel): float {
            $channel = self::clamp($channel, 0.0, 1.0);
            $gamma = $channel <= 0.0031308 ? 12.92 * $channel : 1.055 * $channel ** (1 / 2.4) - 0.055;

            return self::clamp($gamma * 255, 0.0, 255.0);
        }, $linear);

        return new self($r, $g, $b, self::clamp($alpha, 0.0, 1.0));
    }

    private static function cubeRoot(float $value): float
    {
        return $value < 0 ? -((-$value) ** (1 / 3)) : $value ** (1 / 3);
    }

    /** sRGB transfer function (0..1 to linear light), sign-preserving. */
    private static function decode(float $channel): float
    {
        $abs = abs($channel);
        $linear = $abs <= 0.04045 ? $abs / 12.92 : (($abs + 0.055) / 1.055) ** 2.4;

        return $channel < 0 ? -$linear : $linear;
    }

    /** @param  array<int, array<int, float>>  $matrix  @param  array<int, float>  $vector  @return list<float> */
    private static function multiply(array $matrix, array $vector): array
    {
        return array_map(fn (array $row) => $row[0] * $vector[0] + $row[1] * $vector[1] + $row[2] * $vector[2], $matrix);
    }

    /** A CSS <angle> or bare number (degrees) in degrees; `none` is 0. */
    private static function degrees(string $token): float
    {
        foreach (['turn' => 360.0, 'grad' => 0.9, 'rad' => 180 / M_PI, 'deg' => 1.0] as $unit => $factor) {
            if (str_ends_with($token, $unit)) {
                return (float) substr($token, 0, -strlen($unit)) * $factor;
            }
        }

        return (float) $token;
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
        $h = fmod(fmod($h, 360) + 360, 360) / 360;
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
