<?php

namespace ItsJustVita\LaravelBfsg\Css;

/**
 * The condition of an `@supports` rule (CSS Conditional 3 §6, CSS Conditional 4 §2) in three-valued logic: true only
 * when every supported browser is certain to support it, false when it is certain not to, null when the parser cannot
 * decide. `not`, `and`, `or` and parentheses combine the tests (`not null` is null; `and`: any false gives false, else
 * any null null; `or`: any true gives true, else any null null). A `(property: value)` test is true for a colour
 * property whose value Color::evaluate() resolves (color-mix() in a known colour space included), for the pairs of
 * SUPPORTED_VALUES and for a custom property; every other test is null: vendor prefixes, unknown properties or values,
 * selector(), font-tech(), font-format(), at-rule() and any other function or enclosed text. A malformed condition is
 * null. One pass over the condition; nesting deeper than MAX_NESTING levels is null.
 */
final class SupportsCondition
{
    /** Levels of parentheses and `not` followed; a deeper condition is undecidable. */
    public const MAX_NESTING = 32;

    /** Longest declaration value judged; a longer one is undecidable. */
    private const MAX_VALUE_LENGTH = 1024;

    private const YES = 1;

    private const NO = 0;

    private const MAYBE = 2;

    /** Properties whose value is a single colour: a value Color::evaluate() resolves is supported. */
    private const COLOR_PROPERTIES = [
        'color' => true, 'background-color' => true, 'background' => true, 'border-color' => true,
        'border-top-color' => true, 'border-right-color' => true, 'border-bottom-color' => true, 'border-left-color' => true,
        'border-block-color' => true, 'border-block-start-color' => true, 'border-block-end-color' => true,
        'border-inline-color' => true, 'border-inline-start-color' => true, 'border-inline-end-color' => true,
        'outline-color' => true, 'text-decoration-color' => true, 'text-emphasis-color' => true, 'column-rule-color' => true,
        'caret-color' => true, 'accent-color' => true, 'fill' => true, 'stroke' => true, 'stop-color' => true,
        'flood-color' => true, 'lighting-color' => true,
    ];

    /** Property/value pairs every supported browser has (keyword values; gap and aspect-ratio are checked by form). */
    private const SUPPORTED_VALUES = [
        'display' => ['grid' => true, 'flex' => true, 'inline-flex' => true, 'inline-grid' => true, 'contents' => true, 'flow-root' => true],
        'position' => ['sticky' => true],
    ];

    /** Colour spaces color-mix() interpolates in (CSS Color 5), the polar ones with an optional hue method. */
    private const MIX_SPACE = '/^in\s++(?:srgb|srgb-linear|display-p3|a98-rgb|prophoto-rgb|rec2020|lab|oklab|xyz|xyz-d50|xyz-d65|(?:hsl|hwb|lch|oklch)(?:\s++(?:shorter|longer|increasing|decreasing)\s++hue)?)$/D';

    /** A length (or zero, or a percentage) as `gap` takes it. */
    private const LENGTH = '/^(?:[+]?(?:\d++\.?\d*+|\.\d++)(?:e[+-]?\d++)?(?:px|em|rem|%|ex|ch|lh|rlh|vw|vh|vi|vb|vmin|vmax|[sld]v(?:w|h|i|b|min|max)|cq(?:w|h|i|b|min|max)|cm|mm|q|in|pt|pc)|0)$/D';

    /** A <ratio> as `aspect-ratio` takes it, optionally with `auto`. */
    private const RATIO = '/^(?:auto\s++)?(?:\d++\.?\d*+|\.\d++)(?:\s*+\/\s*+(?:\d++\.?\d*+|\.\d++))?(?:\s++auto)?$/D';

    private int $position = 0;

    private bool $malformed = false;

    private function __construct(private readonly string $text, private readonly int $length) {}

    public static function evaluate(string $condition): ?bool
    {
        $parser = new self($condition, strlen($condition));
        $result = $parser->condition(0);
        $parser->skipWhitespace();

        if ($parser->malformed || $parser->position < $parser->length) {
            return null;
        }

        return match ($result) {
            self::YES => true,
            self::NO => false,
            default => null,
        };
    }

    /** `not <in-parens>`, or `<in-parens>` joined by one kind of `and` / `or`. */
    private function condition(int $depth): int
    {
        if ($depth > self::MAX_NESTING) {
            $this->malformed = true;

            return self::MAYBE;
        }

        $this->skipWhitespace();

        if ($this->keyword('not')) {
            $value = $this->inParens($depth);

            return $value === self::MAYBE ? self::MAYBE : self::YES - $value;
        }

        $value = $this->inParens($depth);
        $operator = null;

        while (! $this->malformed) {
            $this->skipWhitespace();
            $next = $this->keyword('and') ? 'and' : ($this->keyword('or') ? 'or' : null);

            if ($next === null) {
                break;
            }

            if ($operator !== null && $operator !== $next) {
                $this->malformed = true; // `and` and `or` mixed without parentheses

                break;
            }

            $operator = $next;
            $other = $this->inParens($depth);
            $value = $operator === 'and' ? $this->both($value, $other) : $this->either($value, $other);
        }

        return $value;
    }

    /** `( <condition> )`, `( <declaration> )`, a function such as selector(), or other enclosed text (undecidable). */
    private function inParens(int $depth): int
    {
        $this->skipWhitespace();

        if ($this->malformed || $this->position >= $this->length) {
            $this->malformed = true;

            return self::MAYBE;
        }

        if ($this->text[$this->position] !== '(') {
            $name = $this->identifier();

            if ($name === '' || ($this->text[$this->position] ?? '') !== '(') {
                $this->malformed = true;

                return self::MAYBE;
            }

            $this->position++;
            $this->skipToClosing();

            return self::MAYBE; // selector(), font-tech(), font-format(), at-rule(), …
        }

        $this->position++;
        $this->skipWhitespace();
        $start = $this->position;
        $name = $this->identifier();
        $this->skipWhitespace();

        if ($name !== '' && ($this->text[$this->position] ?? '') === ':') {
            $this->position++;
            $valueStart = $this->position;
            $this->skipToClosing();

            return $this->malformed ? self::MAYBE : $this->declaration($name, substr($this->text, $valueStart, $this->position - 1 - $valueStart));
        }

        $this->position = $start;
        $char = $this->text[$this->position] ?? '';

        if ($char === '(' || $this->keywordAhead('not') || ($name !== '' && $this->opensFunction($start + strlen($name)))) {
            $value = $this->condition($depth + 1);
            $this->skipWhitespace();

            if ($this->malformed || ($this->text[$this->position] ?? '') !== ')') {
                $this->malformed = true;

                return self::MAYBE;
            }

            $this->position++;

            return $value;
        }

        $this->skipToClosing();

        return self::MAYBE; // general enclosed: `(display grid)`
    }

    /** A `(property: value)` test. */
    private function declaration(string $name, string $value): int
    {
        if ($name === '--' || $this->hasTopLevelSemicolon($value)) {
            return self::NO; // not a declaration (`--` is reserved): a browser evaluates the test to false
        }

        $value = trim($value);

        if (strlen($value) > self::MAX_VALUE_LENGTH) {
            return self::MAYBE;
        }

        if (str_starts_with($name, '--')) {
            return self::YES;
        }

        $value = strtolower(trim(preg_replace('/!\s*+important$/Di', '', $value) ?? $value));
        $name = strtolower($name);

        if ($value === '' || $name[0] === '-') {
            return self::MAYBE; // an empty value, a vendor prefix
        }

        if (isset(self::COLOR_PROPERTIES[$name])) {
            return $this->isColor($value) ? self::YES : self::MAYBE;
        }

        $supported = match ($name) {
            'gap' => $this->isGap($value),
            'aspect-ratio' => preg_match(self::RATIO, $value) === 1,
            default => isset(self::SUPPORTED_VALUES[$name][$value]),
        };

        return $supported ? self::YES : self::MAYBE;
    }

    /**
     * A value Color::evaluate() resolves exactly, or a color-mix() in a known colour space it resolves approximately;
     * rgb() and hsl() also need the syntax browsers accept (strictSyntax()), which Color is more lenient about.
     */
    private function isColor(string $value): bool
    {
        $result = Color::evaluate($value);

        if ($result['color'] === null || ! $this->strictSyntax($value)) {
            return false;
        }

        if (! $result['approximate']) {
            return true;
        }

        $comma = strpos($value, ',');

        return str_starts_with($value, 'color-mix(') && $comma !== false && preg_match(self::MIX_SPACE, trim(substr($value, 10, $comma - 10))) === 1;
    }

    /**
     * Whether an rgb()/rgba()/hsl()/hsla() value has a syntax every browser accepts: the legacy form has exactly 3 or 4
     * comma-separated single tokens, the modern one 3 space-separated channels and an optional `/ alpha`; rgb channels
     * are all numbers or all percentages, hsl saturation and lightness percentages; `none` only in the modern form.
     * Other values pass. Linear in the value.
     */
    private function strictSyntax(string $value): bool
    {
        if (preg_match('/^(rgba?|hsla?)\(/', $value, $m) !== 1 || ! str_ends_with($value, ')')) {
            return true;
        }

        $inner = trim(substr($value, strlen($m[0]), -1));
        $legacy = str_contains($inner, ',');

        if ($legacy) {
            $channels = array_map('trim', explode(',', $inner));

            if (count($channels) < 3 || count($channels) > 4 || preg_grep('/[\s\/]|^$|^none$/', $channels) !== []) {
                return false;
            }

            $channels = array_slice($channels, 0, 3);
        } else {
            $parts = explode('/', $inner);
            $channels = preg_split('/\s++/', trim($parts[0]), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if (count($parts) > 2 || count($channels) !== 3 || (count($parts) === 2 && preg_match('/^\S++$/', trim($parts[1])) !== 1)) {
                return false;
            }
        }

        $percent = array_map(fn (string $channel): bool => str_ends_with($channel, '%'), $channels);

        if ($m[1][0] === 'h') {
            return $percent[1] && $percent[2] && ! $percent[0];
        }

        return count(array_unique($percent)) === 1;
    }

    /** Whether the value has a `;` outside brackets and strings: it would end the declaration. */
    private function hasTopLevelSemicolon(string $value): bool
    {
        $depth = 0;
        $quote = null;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if ($char === '\\') {
                $i++;
            } elseif ($quote !== null) {
                $quote = $char === $quote ? null : $quote;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                $depth = max(0, $depth - 1);
            } elseif ($char === ';' && $depth === 0) {
                return true;
            }
        }

        return false;
    }

    /** One or two lengths. */
    private function isGap(string $value): bool
    {
        $parts = preg_split('/\s++/', $value, 3) ?: [];

        return count($parts) <= 2 && array_filter($parts, fn (string $part): bool => preg_match(self::LENGTH, $part) !== 1) === [];
    }

    private function both(int $a, int $b): int
    {
        return match (true) {
            $a === self::NO || $b === self::NO => self::NO,
            $a === self::MAYBE || $b === self::MAYBE => self::MAYBE,
            default => self::YES,
        };
    }

    private function either(int $a, int $b): int
    {
        return match (true) {
            $a === self::YES || $b === self::YES => self::YES,
            $a === self::MAYBE || $b === self::MAYBE => self::MAYBE,
            default => self::NO,
        };
    }

    /** Consume `not`, `and` or `or` (any case) when whitespace follows it. */
    private function keyword(string $word): bool
    {
        if (! $this->keywordAhead($word)) {
            return false;
        }

        $this->position += strlen($word);
        $this->skipWhitespace();

        return true;
    }

    private function keywordAhead(string $word): bool
    {
        $end = $this->position + strlen($word);

        return $end < $this->length && strncasecmp(substr($this->text, $this->position, strlen($word)), $word, strlen($word)) === 0
            && ctype_space($this->text[$end]);
    }

    /** Whether a function token starts here: an identifier directly followed by `(`. */
    private function opensFunction(int $end): bool
    {
        return ($this->text[$end] ?? '') === '(';
    }

    /** The identifier at the position (escapes and non-ASCII characters included), consumed; '' when there is none. */
    private function identifier(): string
    {
        $start = $this->position;

        while ($this->position < $this->length) {
            $char = $this->text[$this->position];

            if ($char === '\\' && $this->position + 1 < $this->length) {
                $this->position += 2;
            } elseif (ctype_alnum($char) || $char === '-' || $char === '_' || ord($char) >= 0x80) {
                $this->position++;
            } else {
                break;
            }
        }

        $name = substr($this->text, $start, $this->position - $start);

        if ($name === '' || $name === '-' || ctype_digit($name[0]) || ($name[0] === '-' && ctype_digit($name[1] ?? ''))) {
            $this->position = $start;

            return '';
        }

        return $name;
    }

    /** Move past the `)` that closes the parenthesis just opened, skipping nested brackets, strings and escapes. */
    private function skipToClosing(): void
    {
        $depth = 0;
        $quote = null;

        for (; $this->position < $this->length; $this->position++) {
            $char = $this->text[$this->position];

            if ($char === '\\') {
                $this->position++;
            } elseif ($quote !== null) {
                $quote = $char === $quote ? null : $quote;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                if ($depth === 0) {
                    if ($char !== ')') {
                        break;
                    }

                    $this->position++;

                    return;
                }

                $depth--;
            }
        }

        $this->malformed = true;
    }

    private function skipWhitespace(): void
    {
        $this->position += strspn($this->text, " \t\r\n\f", $this->position);
    }
}
