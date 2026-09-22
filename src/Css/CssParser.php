<?php

namespace ItsJustVita\LaravelBfsg\Css;

use DOMElement;
use ItsJustVita\LaravelBfsg\Dom\Element;
use ItsJustVita\LaravelBfsg\Dom\HtmlDocument;
use Throwable;

/**
 * Small CSS engine for static analysis: tokenizes stylesheets, resolves the cascade for a
 * supported selector subset, and computes text/background colours and font metrics.
 *
 * A rule is an array{selector: string, properties: array<string, array{value: string, important: bool}>,
 * specificity: array{0: int, 1: int, 2: int}, order: int, sheet: int}.
 */
class CssParser
{
    /** Upper bound of indexed rules; only rules carrying one of INDEXED_PROPERTIES count. */
    public const MAX_INDEXED_RULES = 2000;

    /** Colour and font properties (spec §6.1) plus display/visibility for CSS-hidden detection. */
    public const INDEXED_PROPERTIES = ['color', 'background', 'background-color', 'font-size', 'font-weight', 'display', 'visibility'];

    /** At-rules whose body is unwrapped (their rules apply unconditionally for static analysis). */
    private const UNWRAPPED_AT_RULES = ['layer', 'supports', 'container', 'scope', 'document', '-moz-document'];

    private const FONT_KEYWORDS = [
        'xx-small' => 9.0, 'x-small' => 10.0, 'small' => 13.0, 'medium' => 16.0,
        'large' => 18.0, 'x-large' => 24.0, 'xx-large' => 32.0, 'xxx-large' => 48.0,
    ];

    /** Default font-size factor relative to the parent, per UA stylesheet. */
    private const TAG_FONT_FACTORS = ['h1' => 2.0, 'h2' => 1.5, 'h3' => 1.17, 'h4' => 1.0, 'h5' => 0.83, 'h6' => 0.67, 'small' => 0.83];

    private const BOLD_TAGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'th', 'b', 'strong'];

    /** @var list<array{selector: string, properties: array<string, array{value: string, important: bool}>, specificity: array{0: int, 1: int, 2: int}, order: int, sheet: int}> */
    protected array $rules = [];

    /** @var array<string, list<int>> node path => indexes into */
    protected array $index = [];

    protected bool $indexed = false;

    protected bool $truncated = false;

    protected ?HtmlDocument $document = null;

    /** @var array<string, array{0: Color, 1: bool}> */
    private array $backgroundCache = [];

    /** @var array<string, array{0: Color, 1: bool}> */
    private array $foregroundCache = [];

    /** @var array<string, float> */
    private array $fontSizeCache = [];

    /** @var array<string, array<string, array{value: string, important: bool}>> */
    private array $declarationCache = [];

    /** @var array<string, array<string, list<int>>> node path => property => cascade weight of the winner */
    private array $weightCache = [];

    /** Parse every screen stylesheet of the document and build the rule index. */
    public function parse(HtmlDocument $document): static
    {
        $this->document = $document;
        $this->rules = [];
        $this->index = [];
        $this->truncated = false;
        $this->backgroundCache = $this->foregroundCache = $this->fontSizeCache = $this->declarationCache = $this->weightCache = [];

        foreach ($this->stylesheetsFor($document) as $sheet => $css) {
            foreach ($this->parseStylesheet($css, count($this->rules)) as $rule) {
                $rule['sheet'] = $sheet;
                $this->rules[] = $rule;
            }
        }

        $this->buildIndex();

        return $this;
    }

    /** @return list<string> */
    public function stylesheetsFor(HtmlDocument $document): array
    {
        return $document->styleSheets();
    }

    /** @return list<array{selector: string, properties: array<string, array{value: string, important: bool}>, specificity: array{0: int, 1: int, 2: int}, order: int, sheet: int}> */
    public function rules(): array
    {
        return $this->rules;
    }

    public function isIndexed(): bool
    {
        return $this->indexed;
    }

    /** True when more than MAX_INDEXED_RULES colour/font rules exist; results are approximate. */
    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    /**
     * Tokenize one stylesheet into qualified rules. Comments are stripped; @media is kept for
     * all/screen only; @layer, @supports, @container are unwrapped; every other at-rule is dropped.
     *
     * @return list<array{selector: string, properties: array<string, array{value: string, important: bool}>, specificity: array{0: int, 1: int, 2: int}, order: int, sheet: int}>
     */
    public function parseStylesheet(string $css, int $orderOffset = 0): array
    {
        $rules = [];
        $this->parseBlock(self::stripComments($css), $rules, $orderOffset);

        return $rules;
    }

    public static function stripComments(string $css): string
    {
        return preg_replace('~/\*.*?(\*/|$)~s', '', $css) ?? $css;
    }

    /**
     * Declarations of a block (or style attribute): names lowercased, `!important` detected and stripped,
     * `;` inside parentheses or quotes (data URIs, url()) does not split, nested blocks are skipped.
     *
     * @return array<string, array{value: string, important: bool}>
     */
    public function parseProperties(string $block): array
    {
        $properties = [];

        foreach ($this->splitTopLevel(self::stripComments($block), ';', true) as $declaration) {
            $colon = strpos($declaration, ':');

            if ($colon === false) {
                continue;
            }

            $name = strtolower(trim(substr($declaration, 0, $colon)));
            $value = trim(substr($declaration, $colon + 1));
            $important = false;

            if (preg_match('/!\s*important\s*$/i', $value) === 1) {
                $important = true;
                $value = trim(preg_replace('/!\s*important\s*$/i', '', $value) ?? $value);
            }

            if ($name === '' || $value === '' || preg_match('/^-?[a-z_][a-z0-9_-]*$/', $name) !== 1) {
                continue;
            }

            if (isset($properties[$name]) && $properties[$name]['important'] && ! $important) {
                continue;
            }

            $properties[$name] = ['value' => $value, 'important' => $important];
        }

        return $properties;
    }

    /** @return array<string, array{value: string, important: bool}> */
    public function inlineStyle(DOMElement $element): array
    {
        return $this->parseProperties($element->getAttribute('style'));
    }

    /** Specificity as [ids, classes/attributes/pseudo-classes, types/pseudo-elements]. */
    public function calculateSpecificity(string $selector): array
    {
        $selector = preg_replace('/\\\\./', 'x', $selector) ?? $selector;
        $selector = preg_replace('/:(?:not|is|where)\(/i', ' ', $selector) ?? $selector;
        $selector = str_replace(')', ' ', $selector);

        $ids = preg_match_all('/#[\w-]+/', $selector);
        $pseudoElements = preg_match_all('/::[\w-]+/', $selector);
        $selector = preg_replace('/::[\w-]+/', ' ', $selector) ?? $selector;
        $classes = preg_match_all('/\.[\w-]+/', $selector) + preg_match_all('/\[[^\]]*\]/', $selector) + preg_match_all('/:[\w-]+/', $selector);

        $stripped = preg_replace(['/#[\w-]+/', '/\.[\w-]+/', '/\[[^\]]*\]/', '/:[\w-]+(\([^)]*\))?/'], ' ', $selector) ?? $selector;
        $types = preg_match_all('/(?:^|[\s>+~])([a-zA-Z][\w-]*)/', $stripped);

        return [$ids, $classes, $types + $pseudoElements];
    }

    /**
     * Convert a selector of the supported subset to an absolute XPath, or null when any part is unsupported.
     * Supported: type, *, .class, #id, [attr], [attr=|~=|^=|$=|*=||=value], :root, :link, :first-child,
     * :last-child, :not(<compound>), descendant and child combinators.
     */
    public function simpleSelectorToXpath(string $selector): ?string
    {
        $selector = trim($selector);

        if ($selector === '') {
            return null;
        }

        $xpath = '';
        $position = 0;
        $length = strlen($selector);
        $axis = '//';

        while ($position < $length) {
            $compound = $this->compoundToXpath($selector, $position);

            if ($compound === null) {
                return null;
            }

            $xpath .= $axis.$compound;
            $whitespace = $this->skipWhitespace($selector, $position);

            if ($position >= $length) {
                break;
            }

            if ($selector[$position] === '>') {
                $position++;
                $this->skipWhitespace($selector, $position);
                $axis = '/';
            } elseif ($whitespace) {
                $axis = '//';
            } else {
                return null;
            }
        }

        return $xpath;
    }

    /** @return array<string, array{value: string, important: bool}> cascaded declarations of the element itself */
    public function declarationsFor(DOMElement $element): array
    {
        $key = $this->nodeKey($element);

        if (isset($this->declarationCache[$key])) {
            return $this->declarationCache[$key];
        }

        $winners = [];

        foreach ($this->matchingRules($element) as $rule) {
            foreach ($rule['properties'] as $name => $declaration) {
                $weight = [$declaration['important'] ? 1 : 0, 0, ...$rule['specificity'], $rule['order']];
                $this->keepHeavier($winners, $name, $declaration, $weight);
            }
        }

        foreach ($this->inlineStyle($element) as $name => $declaration) {
            $weight = [$declaration['important'] ? 1 : 0, 1, 0, 0, 0, PHP_INT_MAX];
            $this->keepHeavier($winners, $name, $declaration, $weight);
        }

        $this->weightCache[$key] = array_map(fn (array $winner) => $winner['weight'], $winners);

        return $this->declarationCache[$key] = array_map(fn (array $winner) => $winner['declaration'], $winners);
    }

    /**
     * Effective text and background colour of an element.
     *
     * @return array{foreground: Color, background: Color, approximate: bool}
     */
    public function resolveColors(DOMElement $element): array
    {
        [$background, $bgApproximate] = $this->background($element);
        [$foreground, $fgApproximate] = $this->foreground($element);

        if ($foreground->a < 1) {
            $foreground = $foreground->over($background);
        }

        return [
            'foreground' => $foreground,
            'background' => $background,
            'approximate' => $bgApproximate || $fgApproximate || $this->truncated,
        ];
    }

    public function fontSizePx(DOMElement $element): float
    {
        $key = $this->nodeKey($element);

        if (isset($this->fontSizeCache[$key])) {
            return $this->fontSizeCache[$key];
        }

        $parent = $element->parentNode instanceof DOMElement ? $this->fontSizePx($element->parentNode) : 16.0;
        $value = strtolower($this->declarationsFor($element)['font-size']['value'] ?? '');
        $size = $this->fontSizeFrom($value, $parent);

        if ($size === null) {
            $size = $parent * (self::TAG_FONT_FACTORS[Element::tag($element)] ?? 1.0);
        }

        return $this->fontSizeCache[$key] = $size;
    }

    /**
     * Whether the stylesheets hide the element: cascaded display:none on self or an ancestor, or an inherited
     * visibility:hidden/collapse that no closer element resets to visible.
     */
    public function hidesElement(DOMElement $element): bool
    {
        $visibility = null;

        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
            $declarations = $this->declarationsFor($node);

            if (strtolower($declarations['display']['value'] ?? '') === 'none') {
                return true;
            }

            $own = strtolower($declarations['visibility']['value'] ?? '');

            if ($visibility === null && in_array($own, ['visible', 'hidden', 'collapse'], true)) {
                $visibility = $own;
            }
        }

        return $visibility === 'hidden' || $visibility === 'collapse';
    }

    public function isBold(DOMElement $element): bool
    {
        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
            $value = strtolower($this->declarationsFor($node)['font-weight']['value'] ?? '');

            if ($value === '' || in_array($value, ['inherit', 'unset'], true)) {
                if (in_array(Element::tag($node), self::BOLD_TAGS, true)) {
                    return true;
                }

                continue;
            }

            if (in_array($value, ['bold', 'bolder'], true)) {
                return true;
            }

            return is_numeric($value) && (int) $value >= 700;
        }

        return false;
    }

    /** @param  list<array{selector: string, properties: array<string, array{value: string, important: bool}>, specificity: array{0: int, 1: int, 2: int}, order: int, sheet: int}>  $rules */
    private function parseBlock(string $css, array &$rules, int $orderOffset): void
    {
        $position = 0;
        $length = strlen($css);

        while ($position < $length) {
            $this->skipWhitespace($css, $position);

            if ($position >= $length) {
                break;
            }

            if ($css[$position] === '@') {
                preg_match('/@([\w-]+)/A', $css, $m, 0, $position);
                $name = strtolower($m[1] ?? '');
                $position += strlen($m[0] ?? '@');
                [$prelude, $terminator] = $this->readUntil($css, $position, ['{', ';']);

                if ($terminator !== '{') {
                    continue; // statement at-rule: @charset, @import, @layer a, b; @namespace
                }

                $body = $this->readBlock($css, $position);

                if ($name === 'media' && HtmlDocument::mediaAppliesToScreen($prelude)) {
                    $this->parseBlock($body, $rules, $orderOffset);
                } elseif (in_array($name, self::UNWRAPPED_AT_RULES, true)) {
                    $this->parseBlock($body, $rules, $orderOffset);
                }

                continue; // @font-face, @keyframes, @page, @property, print media, …
            }

            [$prelude, $terminator] = $this->readUntil($css, $position, ['{']);

            if ($terminator !== '{') {
                break;
            }

            $properties = $this->parseProperties($this->readBlock($css, $position));

            if ($properties === []) {
                continue;
            }

            foreach ($this->splitTopLevel($prelude, ',') as $selector) {
                $selector = trim(preg_replace('/\s+/', ' ', $selector) ?? $selector);

                if ($selector === '') {
                    continue;
                }

                $rules[] = [
                    'selector' => $selector,
                    'properties' => $properties,
                    'specificity' => $this->calculateSpecificity($selector),
                    'order' => $orderOffset + count($rules),
                    'sheet' => 0,
                ];
            }
        }
    }

    private function buildIndex(): void
    {
        $indexedRules = 0;
        $xpath = $this->document?->xpath();

        foreach ($this->rules as $position => $rule) {
            if (array_intersect(array_keys($rule['properties']), self::INDEXED_PROPERTIES) === []) {
                continue;
            }

            if (++$indexedRules > self::MAX_INDEXED_RULES) {
                $this->truncated = true;

                break;
            }

            $expression = $this->simpleSelectorToXpath($rule['selector']);

            if ($expression === null || $xpath === null) {
                continue;
            }

            foreach ($this->document->query($expression) as $element) {
                $this->index[$this->nodeKey($element)][] = $position;
            }
        }

        $this->indexed = true;
    }

    /** @return list<array{selector: string, properties: array<string, array{value: string, important: bool}>, specificity: array{0: int, 1: int, 2: int}, order: int, sheet: int}> */
    private function matchingRules(DOMElement $element): array
    {
        if ($this->indexed) {
            return array_map(fn (int $position) => $this->rules[$position], $this->index[$this->nodeKey($element)] ?? []);
        }

        return [];
    }

    /**
     * @param  array<string, array{declaration: array{value: string, important: bool}, weight: list<int>}>  $winners
     * @param  array{value: string, important: bool}  $declaration
     * @param  list<int>  $weight
     */
    private function keepHeavier(array &$winners, string $name, array $declaration, array $weight): void
    {
        if (! isset($winners[$name]) || $weight >= $winners[$name]['weight']) {
            $winners[$name] = ['declaration' => $declaration, 'weight' => $weight];
        }
    }

    /** @return array{0: Color, 1: bool} colour and whether it is approximate */
    private function background(DOMElement $element): array
    {
        $key = $this->nodeKey($element);

        if (isset($this->backgroundCache[$key])) {
            return $this->backgroundCache[$key];
        }

        [$parent, $approximate] = $element->parentNode instanceof DOMElement
            ? $this->background($element->parentNode)
            : [new Color(255, 255, 255), false];

        $value = $this->winningBackground($element);

        if ($value === null) {
            return $this->backgroundCache[$key] = [$parent, $approximate];
        }

        [$own, $ownApproximate] = Color::fromBackground($value);

        if ($own === null || $own->a <= 0) {
            return $this->backgroundCache[$key] = [$parent, $approximate || $ownApproximate];
        }

        return $this->backgroundCache[$key] = [$own->a < 1 ? $own->over($parent) : $own, $approximate || $ownApproximate];
    }

    /** @return array{0: Color, 1: bool} */
    private function foreground(DOMElement $element): array
    {
        $key = $this->nodeKey($element);

        if (isset($this->foregroundCache[$key])) {
            return $this->foregroundCache[$key];
        }

        [$parent, $approximate] = $element->parentNode instanceof DOMElement
            ? $this->foreground($element->parentNode)
            : [new Color(0, 0, 0), false];

        $value = $this->declarationsFor($element)['color']['value'] ?? null;

        if ($value === null) {
            return $this->foregroundCache[$key] = [$parent, $approximate];
        }

        $own = Color::isUnresolvable($value) ? null : Color::parse($value);

        if ($own === null) {
            return $this->foregroundCache[$key] = [$parent, true];
        }

        return $this->foregroundCache[$key] = [$own, $approximate];
    }

    /** The value of whichever of background / background-color wins the cascade for the element. */
    private function winningBackground(DOMElement $element): ?string
    {
        $declarations = $this->declarationsFor($element);
        $weights = $this->weightCache[$this->nodeKey($element)] ?? [];
        $shorthand = $declarations['background'] ?? null;
        $longhand = $declarations['background-color'] ?? null;

        if ($shorthand === null || $longhand === null) {
            return ($longhand ?? $shorthand)['value'] ?? null;
        }

        return $weights['background-color'] >= $weights['background'] ? $longhand['value'] : $shorthand['value'];
    }

    private function fontSizeFrom(string $value, float $parent): ?float
    {
        if ($value === '' || in_array($value, ['inherit', 'unset'], true)) {
            return $value === '' ? null : $parent;
        }

        if ($value === 'initial') {
            return 16.0;
        }

        if (isset(self::FONT_KEYWORDS[$value])) {
            return self::FONT_KEYWORDS[$value];
        }

        if ($value === 'larger' || $value === 'smaller') {
            return $parent * ($value === 'larger' ? 1.2 : 0.83);
        }

        if (preg_match('/^(\d*\.?\d+)(px|pt|em|rem|%)$/', $value, $m) !== 1) {
            return null;
        }

        $number = (float) $m[1];

        return match ($m[2]) {
            'px' => $number,
            'pt' => $number * 4 / 3,
            'em' => $number * $parent,
            'rem' => $number * 16,
            '%' => $number / 100 * $parent,
        };
    }

    private function compoundToXpath(string $selector, int &$position): ?string
    {
        $length = strlen($selector);
        $name = '*';
        $predicates = [];

        if ($position < $length && $selector[$position] === '*') {
            $position++;
        } elseif (preg_match('/[a-zA-Z][a-zA-Z0-9-]*/A', $selector, $m, 0, $position) === 1) {
            $name = strtolower($m[0]);
            $position += strlen($m[0]);
        }

        while ($position < $length && ! ctype_space($selector[$position]) && $selector[$position] !== '>') {
            $predicate = $this->simplePredicate($selector, $position);

            if ($predicate === null) {
                return null;
            }

            $predicates[] = $predicate;
        }

        if ($name === '*' && $predicates === [] && ($position === 0 || $selector[$position - 1] !== '*')) {
            return null;
        }

        return $name.($predicates === [] ? '' : '['.implode(' and ', $predicates).']');
    }

    private function simplePredicate(string $selector, int &$position): ?string
    {
        $char = $selector[$position];
        $ident = '-?(?:[a-zA-Z_]|\\\\.|[^\x00-\x7F])(?:[\w-]|\\\\.|[^\x00-\x7F])*';

        if ($char === '#' || $char === '.') {
            if (preg_match('/'.$ident.'/A', $selector, $m, 0, $position + 1) !== 1) {
                return null;
            }

            $position += 1 + strlen($m[0]);
            $value = $this->unescape($m[0]);

            return $char === '#'
                ? '@id='.HtmlDocument::xpathLiteral($value)
                : 'contains(concat(" ", normalize-space(@class), " "), '.HtmlDocument::xpathLiteral(' '.$value.' ').')';
        }

        if ($char === '[') {
            $pattern = '/\[\s*([a-zA-Z_:][\w:.-]*)\s*(?:([~|^$*]?=)\s*(?:"([^"]*)"|\'([^\']*)\'|('.$ident.'))\s*)?\]/A';

            if (preg_match($pattern, $selector, $m, 0, $position) !== 1) {
                return null;
            }

            $position += strlen($m[0]);

            return $this->attributePredicate(strtolower($m[1]), $m[2] ?? '', ($m[3] ?? '').($m[4] ?? '').$this->unescape($m[5] ?? ''));
        }

        if ($char !== ':' || ($selector[$position + 1] ?? '') === ':') {
            return null;
        }

        if (preg_match('/:(root|link|first-child|last-child)(?![\w(-])/Ai', $selector, $m, 0, $position) === 1) {
            $position += strlen($m[0]);

            return match (strtolower($m[1])) {
                'root' => 'not(parent::*)',
                'link' => '(local-name()="a" or local-name()="area") and @href',
                'first-child' => 'not(preceding-sibling::*)',
                'last-child' => 'not(following-sibling::*)',
            };
        }

        if (preg_match('/:not\(\s*([^()]*?)\s*\)/Ai', $selector, $m, 0, $position) === 1) {
            $inner = $m[1];
            $innerPosition = 0;
            $compound = $this->compoundToXpath($inner, $innerPosition);

            if ($compound === null || $innerPosition !== strlen($inner)) {
                return null;
            }

            $position += strlen($m[0]);

            return 'not(self::'.$compound.')';
        }

        return null;
    }

    private function attributePredicate(string $attribute, string $operator, string $value): ?string
    {
        $attr = '@'.$attribute;
        $literal = HtmlDocument::xpathLiteral($value);

        return match ($operator) {
            '' => $attr,
            '=' => $attr.'='.$literal,
            '~=' => $value === '' || preg_match('/\s/', $value) === 1 ? 'false()' : 'contains(concat(" ", normalize-space('.$attr.'), " "), '.HtmlDocument::xpathLiteral(' '.$value.' ').')',
            '^=' => $value === '' ? 'false()' : 'starts-with('.$attr.', '.$literal.')',
            '$=' => $value === '' ? 'false()' : 'substring('.$attr.', string-length('.$attr.') - '.strlen($value).' + 1) = '.$literal,
            '*=' => $value === '' ? 'false()' : 'contains('.$attr.', '.$literal.')',
            '|=' => '('.$attr.'='.$literal.' or starts-with('.$attr.', '.HtmlDocument::xpathLiteral($value.'-').'))',
            default => null,
        };
    }

    private function unescape(string $identifier): string
    {
        return preg_replace('/\\\\(.)/s', '$1', $identifier) ?? $identifier;
    }

    private function skipWhitespace(string $css, int &$position): bool
    {
        $start = $position;
        $length = strlen($css);

        while ($position < $length && ctype_space($css[$position])) {
            $position++;
        }

        return $position > $start;
    }

    /**
     * Read up to (and consume) the first of $terminators outside strings and parentheses.
     *
     * @param  list<string>  $terminators
     * @return array{0: string, 1: ?string} text before the terminator, the terminator (null at end of input)
     */
    private function readUntil(string $css, int &$position, array $terminators): array
    {
        $start = $position;
        $length = strlen($css);
        $depth = 0;
        $quote = null;

        for (; $position < $length; $position++) {
            $char = $css[$position];

            if ($quote !== null) {
                if ($char === '\\') {
                    $position++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && in_array($char, $terminators, true)) {
                $text = substr($css, $start, $position - $start);
                $position++;

                return [$text, $char];
            }
        }

        return [substr($css, $start), null];
    }

    /** Read a balanced block body; $position is just after the opening brace and ends after the closing one. */
    private function readBlock(string $css, int &$position): string
    {
        $start = $position;
        $length = strlen($css);
        $depth = 1;
        $quote = null;

        for (; $position < $length; $position++) {
            $char = $css[$position];

            if ($quote !== null) {
                if ($char === '\\') {
                    $position++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}' && --$depth === 0) {
                $body = substr($css, $start, $position - $start);
                $position++;

                return $body;
            }
        }

        return substr($css, $start);
    }

    /**
     * Split on $separator outside strings, parentheses and brackets; optionally drop nested {…} blocks.
     *
     * @return list<string>
     */
    private function splitTopLevel(string $text, string $separator, bool $skipBlocks = false): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $braces = 0;
        $quote = null;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($quote !== null) {
                $current .= $char;

                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $text[++$i];
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($skipBlocks && $char === '{') {
                $braces++;

                continue;
            }

            if ($skipBlocks && $char === '}') {
                $braces = max(0, $braces - 1);
                $current = '';

                continue;
            }

            if ($braces > 0) {
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth = max(0, $depth - 1);
            } elseif ($char === $separator && $depth === 0) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $parts[] = $current;

        return array_values(array_filter(array_map('trim', $parts), fn (string $part) => $part !== ''));
    }

    private function nodeKey(DOMElement $element): string
    {
        try {
            return $element->getNodePath() ?? spl_object_hash($element);
        } catch (Throwable) {
            return spl_object_hash($element);
        }
    }
}
