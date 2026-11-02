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
 * specificity: array{0: int, 1: int, 2: int}, order: int, sheet: int, conditional: bool}.
 */
class CssParser
{
    /** Upper bound of indexed rules; only rules carrying one of INDEXED_PROPERTIES count. */
    public const MAX_INDEXED_RULES = 2000;

    /** Upper bound of rules indexed only for their custom properties (no INDEXED_PROPERTIES); a separate budget, so they never push colour rules out. */
    public const MAX_CUSTOM_PROPERTY_RULES = 4000;

    /** var() references followed per value; a value with more is unresolvable (approximate). */
    public const MAX_VAR_REFERENCES = 64;

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

    /** @var list<array{selector: string, properties: array<string, array{value: string, important: bool}>, specificity: array{0: int, 1: int, 2: int}, order: int, sheet: int, conditional: bool}> */
    protected array $rules = [];

    /** @var array<string, list<int>> node path => positions in rules() of the matching rules that carry one of INDEXED_PROPERTIES */
    protected array $index = [];

    /** @var array<string, list<int>> node path => positions in rules() of the matching rules that carry custom properties but none of INDEXED_PROPERTIES */
    protected array $customIndex = [];

    /** @var list<int> custom-property-only rules with the selector `*`: they match every element, so they are not indexed per element */
    private array $universalCustomRules = [];

    /** @var list<int> every rule with the selector `*` that declares custom properties (both indexes) */
    private array $universalRules = [];

    /** @var array<string, true> node path => true for elements whose non-universal matching rules or style attribute declare custom properties */
    private array $customOwners = [];

    /** @var array<string, true> custom property names declared by rules whose selector cannot be evaluated (user-action states and pseudo-elements aside) */
    private array $uncertainNames = [];

    protected bool $indexed = false;

    protected bool $truncated = false;

    /** Number of times the element index was built (test probe; stays 1 per parse). */
    protected int $indexBuilds = 0;

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

    /** @var array<string, string>|null custom property name => value, from :root / html / :host */
    private ?array $customProperties = null;

    /** @var array<string, true> custom property names also declared by non-root or conditional rules (substitution is approximate) */
    private array $overriddenProperties = [];

    /** @var array<string, array{0: ?string, 1: bool}> "depth:name" => substituted value (null: unresolvable) and whether it is approximate */
    private array $resolvedProperties = [];

    /** Substituted values longer than this are given up (approximate): guards against exponential var() nesting. */
    private const MAX_RESOLVED_LENGTH = 4096;

    /** Nesting levels of var() references followed before a value counts as unresolvable. */
    private const MAX_VAR_DEPTH = 8;

    /** CSS-wide keywords that make a custom property take its parent's value (custom properties inherit). */
    private const INHERITING_KEYWORDS = ['inherit', 'unset', 'revert', 'revert-layer'];

    /** Computed custom-property values kept in the cache; beyond it a value that needs one is unresolvable (approximate). */
    private const MAX_CACHED_CUSTOM_PROPERTIES = 50000;

    /** @var array<string, true>|null every custom property name a rule or a style attribute declares; null until prepared */
    private ?array $declaredNames = null;

    /** @var array<string, true> names whose heaviest universal (`*`) declaration is a value: every element declares them */
    private array $universalNames = [];

    /** @var array<string, string> node path => node path of the nearest custom-property owner (the element or an ancestor), '' for none */
    private array $ownerOf = [];

    /** @var array<string, array{0: DOMElement, 1: ?string}> owner node path => the element and the next owner above ('' for none, null until known) */
    private array $owners = [];

    /**
     * @var array<string, string|null|false> "owner path\0depth:name" => computed value of a declaration that contains var()
     *                                       (null: invalid at computed-value time, false: unresolvable)
     */
    private array $customPropertyCache = [];

    /** @var array<string, true> customPropertyCache keys whose value is approximate */
    private array $approximateProperties = [];

    /** @var array<string, array<string, array{value: string, important: bool}>> node path => custom properties of its style attribute */
    private array $inlineCustomProperties = [];

    /** @var array<string, true> "owner path\0name" of the custom properties being computed (cycle detection) */
    private array $computingProperties = [];

    /** @var array{0: ?DOMElement, 1: string}|null the document element and its node path */
    private ?array $rootContext = null;

    /** Selectors whose custom properties are resolved globally (Tailwind v4 and most design systems declare them here). */
    private const ROOT_SELECTORS = [':root', 'html', ':host'];

    /**
     * Parse every screen stylesheet of the document. Only tokenizes and collects rules: the element index
     * is built lazily by the first call that needs it (declarationsFor, colour/font resolution, hidesElement).
     */
    public function parse(HtmlDocument $document): static
    {
        $this->document = $document;
        $this->rules = [];
        $this->index = $this->customIndex = $this->universalCustomRules = $this->universalRules = $this->customOwners = $this->uncertainNames = [];
        $this->indexed = false;
        $this->indexBuilds = 0;
        $this->truncated = false;
        $this->backgroundCache = $this->foregroundCache = $this->fontSizeCache = $this->declarationCache = $this->weightCache = [];
        $this->customProperties = null;
        $this->overriddenProperties = $this->resolvedProperties = [];
        $this->declaredNames = $this->rootContext = null;
        $this->universalNames = $this->ownerOf = $this->owners = $this->customPropertyCache = $this->approximateProperties = [];
        $this->inlineCustomProperties = $this->computingProperties = [];

        foreach ($this->stylesheetsFor($document) as $sheet => $css) {
            foreach ($this->parseStylesheet($css, count($this->rules)) as $rule) {
                $rule['sheet'] = $sheet;
                $this->rules[] = $rule;
            }
        }

        $counts = ['rule' => 0, 'custom' => 0];

        foreach ($this->rules as $rule) {
            $kind = $this->indexKind($rule);

            if ($kind !== null) {
                $counts[$kind]++;
            }
        }

        $this->truncated = $counts['rule'] > self::MAX_INDEXED_RULES || $counts['custom'] > self::MAX_CUSTOM_PROPERTY_RULES;

        return $this;
    }

    /**
     * Build the element index unless it exists. Rules carrying one of INDEXED_PROPERTIES and rules carrying only
     * custom properties have separate budgets (MAX_INDEXED_RULES, MAX_CUSTOM_PROPERTY_RULES): rules beyond a budget
     * are skipped, and the build stops when the optional deadline (microtime(true) value) passes. Either marks the
     * results truncated (approximate).
     */
    public function buildIndex(?float $deadline = null): static
    {
        if ($this->indexed) {
            return $this;
        }

        $this->indexBuilds++;
        $counts = ['rule' => 0, 'custom' => 0];
        $limits = ['rule' => self::MAX_INDEXED_RULES, 'custom' => self::MAX_CUSTOM_PROPERTY_RULES];
        $tokens = null;

        // The two indexes are the one cascade that declarationsFor() and the custom properties share.
        foreach ($this->rules as $position => $rule) {
            $kind = $this->indexKind($rule);

            if ($kind === null || ! $this->inBaseCascade($rule)) {
                continue;
            }

            if (++$counts[$kind] > $limits[$kind]) {
                $this->truncated = true;

                continue;
            }

            if ($deadline !== null && microtime(true) > $deadline) {
                $this->truncated = true;

                break;
            }

            $expression = $this->document === null ? null : $this->simpleSelectorToXpath($rule['selector']);
            $custom = $kind === 'custom' || $this->declaresCustomProperties($rule);

            if ($expression === null) {
                if ($custom) {
                    $this->recordUncertainNames($rule);
                }

                continue;
            }

            if ($custom && $expression === '//*') {
                $this->universalRules[] = $position;

                if ($kind === 'custom') {
                    $this->universalCustomRules[] = $position;

                    continue;
                }

                $custom = false; // every element declares these: no element is their owner
            }

            foreach ($this->elementsMatching($rule['selector'], $expression, $tokens) as $element) {
                $key = $this->nodeKey($element);

                if ($kind === 'rule') {
                    $this->index[$key][] = $position;
                } else {
                    $this->customIndex[$key][] = $position;
                }

                if ($custom) {
                    $this->customOwners[$key] = true;
                }
            }
        }

        $this->indexed = true;

        return $this;
    }

    public function indexBuilds(): int
    {
        return $this->indexBuilds;
    }

    /** @return list<string> */
    public function stylesheetsFor(HtmlDocument $document): array
    {
        return $document->styleSheets();
    }

    /** @return list<array{selector: string, properties: array<string, array{value: string, important: bool}>, specificity: array{0: int, 1: int, 2: int}, order: int, sheet: int, conditional: bool}> */
    public function rules(): array
    {
        return $this->rules;
    }

    public function isIndexed(): bool
    {
        return $this->indexed;
    }

    /** True when more than MAX_INDEXED_RULES colour/font rules or MAX_CUSTOM_PROPERTY_RULES custom-property rules exist, or the index build hit its deadline; results are approximate. */
    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    /**
     * Tokenize one stylesheet into qualified rules. Comments are stripped; @media is kept for
     * all/screen only; @layer, @supports, @container are unwrapped; every other at-rule is dropped.
     *
     * @return list<array{selector: string, properties: array<string, array{value: string, important: bool}>, specificity: array{0: int, 1: int, 2: int}, order: int, sheet: int, conditional: bool}>
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

            $name = trim(substr($declaration, 0, $colon));
            $name = str_starts_with($name, '--') ? $name : strtolower($name); // custom properties are case-sensitive
            $value = trim(substr($declaration, $colon + 1));
            $important = false;

            if (preg_match('/!\s*important\s*$/i', $value) === 1) {
                $important = true;
                $value = trim(preg_replace('/!\s*important\s*$/i', '', $value) ?? $value);
            }

            if ($name === '' || $value === '' || preg_match('/^(?:--[\w-]+|-?[a-z_][a-z0-9_-]*)$/', $name) !== 1) {
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
        $compounds = $this->compounds($selector);

        if ($compounds === null) {
            return null;
        }

        $xpath = '';

        foreach ($compounds as $compound) {
            $xpath .= ($compound['combinator'] === '>' ? '/' : '//').$compound['xpath'];
        }

        return $xpath;
    }

    /**
     * The compounds of a supported selector, left to right; `combinator` joins a compound to the previous one
     * ('' for the first, ' ' descendant, '>' child). Null when any part is unsupported.
     *
     * `tokens` holds the first id and the first class of the compound itself (not inside [...] or :not(...)).
     *
     * @return list<array{combinator: string, xpath: string, tokens: array{id?: string, class?: string}}>|null
     */
    private function compounds(string $selector): ?array
    {
        $selector = trim($selector);

        if ($selector === '') {
            return null;
        }

        $compounds = [];
        $position = 0;
        $length = strlen($selector);
        $combinator = '';

        while ($position < $length) {
            $tokens = [];
            $compound = $this->compoundToXpath($selector, $position, $tokens);

            if ($compound === null) {
                return null;
            }

            $compounds[] = ['combinator' => $combinator, 'xpath' => $compound, 'tokens' => $tokens];
            $whitespace = $this->skipWhitespace($selector, $position);

            if ($position >= $length) {
                break;
            }

            if ($selector[$position] === '>') {
                $position++;
                $this->skipWhitespace($selector, $position);
                $combinator = '>';
            } elseif ($whitespace) {
                $combinator = ' ';
            } else {
                return null;
            }
        }

        return $compounds;
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

    /** @param  list<array{selector: string, properties: array<string, array{value: string, important: bool}>, specificity: array{0: int, 1: int, 2: int}, order: int, sheet: int, conditional: bool}>  $rules */
    private function parseBlock(string $css, array &$rules, int $orderOffset, bool $conditional = false): void
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

                // Rules behind a media feature, @supports or @container apply only sometimes (`conditional`).
                if ($name === 'media' && HtmlDocument::mediaAppliesToScreen($prelude)) {
                    $this->parseBlock($body, $rules, $orderOffset, $conditional || str_contains($prelude, '('));
                } elseif (in_array($name, self::UNWRAPPED_AT_RULES, true)) {
                    $this->parseBlock($body, $rules, $orderOffset, $conditional || in_array($name, ['supports', 'container'], true));
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
                    'conditional' => $conditional,
                ];
            }
        }
    }

    /**
     * Elements a rule applies to. Rules are bucketed by their rightmost compound: a lone class is answered by the
     * class map; a selector whose rightmost compound names a class or an id only tests the elements carrying that
     * token, with a reverse (self::/ancestor::/parent::) expression; everything else is one document query.
     *
     * @param  array{class: array<string, list<DOMElement>>, id: array<string, list<DOMElement>>}|null  $tokens  built on first use
     * @return list<DOMElement>
     */
    private function elementsMatching(string $selector, string $expression, ?array &$tokens): array
    {
        if (preg_match('/^\.((?:[\w-]|\\\\.)+)$/', $selector, $m) === 1) {
            return ($tokens ??= $this->elementsByToken())['class'][$this->unescape($m[1])] ?? [];
        }

        $match = $this->matchExpression($selector);

        if ($match === null || $match['bucket'] === null) {
            return $this->document?->query($expression) ?? [];
        }

        [$type, $token] = $match['bucket'];
        $xpath = $this->document->xpath();

        return array_values(array_filter(
            ($tokens ??= $this->elementsByToken())[$type][$token] ?? [],
            fn (DOMElement $element): bool => $xpath->evaluate('boolean('.$match['expression'].')', $element) === true,
        ));
    }

    /** @return array{class: array<string, list<DOMElement>>, id: array<string, list<DOMElement>>} token => elements, in document order */
    private function elementsByToken(): array
    {
        $map = ['class' => [], 'id' => []];

        foreach ($this->document?->query('//*[@class or @id]') ?? [] as $element) {
            foreach (array_unique(preg_split('/[ \t\n\r]+/', $element->getAttribute('class'), -1, PREG_SPLIT_NO_EMPTY) ?: []) as $class) {
                $map['class'][$class][] = $element;
            }

            if ($element->getAttribute('id') !== '') {
                $map['id'][$element->getAttribute('id')][] = $element;
            }
        }

        return $map;
    }

    /**
     * A selector of the supported subset as an expression evaluated with the candidate element as context node
     * (`self::c[ancestor::b[parent::a]]` for `a > b c`), plus the bucket of its rightmost compound: its first id,
     * else its first class, else null.
     *
     * @return array{expression: string, bucket: array{0: 'class'|'id', 1: string}|null}|null
     */
    public function matchExpression(string $selector): ?array
    {
        $compounds = $this->compounds($selector);

        if ($compounds === null) {
            return null;
        }

        $last = array_pop($compounds);
        $inner = '';

        foreach ($compounds as $index => $compound) {
            $axis = (($compounds[$index + 1] ?? $last)['combinator'] === '>') ? 'parent' : 'ancestor';
            $inner = $axis.'::'.$compound['xpath'].($inner === '' ? '' : '['.$inner.']');
        }

        $bucket = match (true) {
            isset($last['tokens']['id']) => ['id', $last['tokens']['id']],
            isset($last['tokens']['class']) => ['class', $last['tokens']['class']],
            default => null,
        };

        return ['expression' => 'self::'.$last['xpath'].($inner === '' ? '' : '['.$inner.']'), 'bucket' => $bucket];
    }

    /**
     * Resolve every var(--name[, fallback]) in a value against the root custom properties (fallbacks used for
     * undefined names, nested references followed up to 8 levels). Null when a reference cannot be resolved or
     * the substituted value grows beyond MAX_RESOLVED_LENGTH.
     */
    public function resolveVariables(string $value, int $depth = 0): ?string
    {
        return $this->substitute($value, $depth)[0];
    }

    /**
     * resolveVariables() plus whether the result is approximate: a substituted name is also declared outside the
     * unconditional root rules (`.dark { --fg: … }`, `@media (…) { :root { … } }`), so the element may see another value.
     *
     * @return array{0: ?string, 1: bool}
     */
    private function substitute(string $value, int $depth = 0): array
    {
        if (stripos($value, 'var(') === false) {
            return [$value, false];
        }

        if ($depth > 8) {
            return [null, true];
        }

        $properties = $this->customProperties();
        $resolved = '';
        $position = 0;
        $approximate = false;

        while (($start = stripos($value, 'var(', $position)) !== false) {
            $end = $this->closingParenthesis($value, $start + 3);

            if ($end === null) {
                return [null, true];
            }

            $inner = substr($value, $start + 4, $end - $start - 4);
            $parts = $this->splitTopLevel($inner, ',');
            $name = trim($parts[0] ?? '');
            $comma = strpos($inner, ',');
            $fallback = $comma === false ? null : trim(substr($inner, $comma + 1));

            if (isset($properties[$name])) {
                [$replacement, $nested] = $this->resolvedProperties[($depth + 1).':'.$name] ??= $this->substitute($properties[$name], $depth + 1);
            } else {
                [$replacement, $nested] = $fallback === null ? [null, true] : $this->substitute($fallback, $depth + 1);
            }

            if ($replacement === null) {
                return [null, true];
            }

            $approximate = $approximate || $nested || isset($this->overriddenProperties[$name]);
            $resolved .= substr($value, $position, $start - $position).$replacement;
            $position = $end + 1;

            if (strlen($resolved) > self::MAX_RESOLVED_LENGTH) {
                return [null, true];
            }
        }

        $resolved .= substr($value, $position);

        return strlen($resolved) > self::MAX_RESOLVED_LENGTH ? [null, true] : [$resolved, $approximate];
    }

    /**
     * Custom properties declared on :root, html or :host outside media features, @supports and @container
     * (cascade winner), and on <html style>. Names that other rules also declare are recorded as overridden.
     *
     * @return array<string, string>
     */
    public function customProperties(): array
    {
        if ($this->customProperties !== null) {
            return $this->customProperties;
        }

        $winners = [];
        $this->overriddenProperties = [];

        foreach ($this->rules as $rule) {
            $root = ! ($rule['conditional'] ?? false) && in_array(strtolower($rule['selector']), self::ROOT_SELECTORS, true);

            foreach ($rule['properties'] as $name => $declaration) {
                if (! str_starts_with($name, '--')) {
                    continue;
                }

                if (! $root) {
                    $this->overriddenProperties[$name] = true;
                } else {
                    $this->keepHeavier($winners, $name, $declaration, [$declaration['important'] ? 1 : 0, 0, ...$rule['specificity'], $rule['order']]);
                }
            }
        }

        $root = $this->document?->root();

        foreach ($root === null ? [] : $this->inlineStyle($root) as $name => $declaration) {
            if (str_starts_with($name, '--')) {
                $this->keepHeavier($winners, $name, $declaration, [$declaration['important'] ? 1 : 0, 1, 0, 0, 0, PHP_INT_MAX]);
            }
        }

        return $this->customProperties = array_map(fn (array $winner) => $winner['declaration']['value'], $winners);
    }

    /**
     * Computed value of the custom property $name on the element: the element's cascaded declaration (matching rules,
     * then its style attribute), else the parent's value, up to the root; :host declarations count for the root.
     * var() references inside the value are substituted where the winning declaration sits, as in the CSS computed
     * value. Null when the property is undefined, `initial` (the guaranteed-invalid value), invalid at computed-value
     * time (a reference without value or fallback, a cycle) or beyond a limit (nesting deeper than 8 levels,
     * MAX_VAR_REFERENCES).
     */
    public function customProperty(DOMElement $element, string $name): ?string
    {
        $value = $this->customPropertyValue($element, $this->nodeKey($element), $name, 1)[0];

        return is_string($value) ? $value : null;
    }

    /**
     * Substitute every var(--name[, fallback]) in a value with the element's custom properties (customProperty()). The
     * fallback is used when the property is undefined, `initial` or invalid at computed-value time (approximate for a
     * cycle). Approximate when a substituted name is also declared by a rule whose selector cannot be evaluated.
     * [null, true] when a reference cannot be resolved: undefined without a fallback, a cycle without a fallback,
     * nesting deeper than 8 levels, more than MAX_VAR_REFERENCES references in one value, or a result longer than
     * MAX_RESOLVED_LENGTH.
     *
     * @return array{0: ?string, 1: bool}
     */
    public function substituteFor(DOMElement $element, string $value): array
    {
        return $this->substituteValue($element, $this->nodeKey($element), $value);
    }

    /**
     * substituteFor() with the element's node key.
     *
     * @return array{0: ?string, 1: bool}
     */
    private function substituteValue(DOMElement $element, string $key, string $value): array
    {
        [$resolved, $approximate] = $this->substituteIn($element, $key, $value, 0);

        return is_string($resolved) ? [$resolved, $approximate] : [null, true];
    }

    /**
     * Substitute the var() references of a value at nesting depth $depth in the element's context.
     *
     * @return array{0: string|null|false, 1: bool} the value (null: invalid at computed-value time, false: a limit
     *                                              was hit) and whether it is approximate
     */
    private function substituteIn(DOMElement $element, string $key, string $value, int $depth): array
    {
        if (stripos($value, 'var(') === false) {
            return [$value, false];
        }

        if ($depth > self::MAX_VAR_DEPTH) {
            return [false, true];
        }

        $resolved = '';
        $position = 0;
        $approximate = false;
        $references = 0;

        while (($start = stripos($value, 'var(', $position)) !== false) {
            $end = $this->closingParenthesis($value, $start + 3);

            if (++$references > self::MAX_VAR_REFERENCES || $end === null) {
                return [false, true];
            }

            $inner = substr($value, $start + 4, $end - $start - 4);
            $comma = strpos($inner, ',');
            $name = trim($comma === false ? $inner : substr($inner, 0, $comma));
            $fallback = $comma === false ? null : trim(substr($inner, $comma + 1));

            if (! str_starts_with($name, '--')) {
                return [false, true]; // invalid var(): the browser drops the declaration
            }

            [$replacement, $nested] = $this->customPropertyValue($element, $key, $name, $depth + 1);
            $nested = $nested || isset($this->uncertainNames[$name]);

            if ($replacement === null && $fallback !== null) {
                [$replacement, $fallbackApproximate] = $this->substituteIn($element, $key, $fallback, $depth + 1);
                $nested = $nested || $fallbackApproximate;
            }

            if (! is_string($replacement)) {
                return [$replacement, $replacement === false || $approximate || $nested];
            }

            $approximate = $approximate || $nested;
            $resolved .= substr($value, $position, $start - $position).$replacement;
            $position = $end + 1;

            if (strlen($resolved) > self::MAX_RESOLVED_LENGTH) {
                return [false, true];
            }
        }

        $resolved .= substr($value, $position);

        return strlen($resolved) > self::MAX_RESOLVED_LENGTH ? [false, true] : [$resolved, $approximate];
    }

    /**
     * The custom property $name on the element, its value substituted at nesting depth $depth. Names declared nowhere
     * are undefined at once. Names a universal rule declares are looked up from the element itself, other names from
     * the nearest owner (an element whose own rules or style attribute declare custom properties) upwards, so elements
     * that merely inherit add no cache entries. The result is cached at every owner visited; when the cache holds
     * MAX_CACHED_CUSTOM_PROPERTIES entries, a lookup that would add one is unresolvable.
     *
     * @return array{0: string|null|false, 1: bool} value (null: undefined, `initial` or invalid at computed-value time,
     *                                              false: unresolvable) and whether it is approximate
     */
    private function customPropertyValue(DOMElement $element, string $key, string $name, int $depth): array
    {
        $this->prepareCustomProperties();

        if (! isset($this->declaredNames[$name])) {
            return [null, false];
        }

        $universal = isset($this->universalNames[$name]);
        [$node, $nodeKey] = $universal ? [$element, $key] : $this->owner($this->nearestOwner($element, $key));
        $suffix = "\0".$depth.':'.$name;
        $inheriting = []; // cache slots of the visited elements that take the value of the next one, with their revert-layer flag
        $result = null;

        while ($node !== null) {
            $slot = $nodeKey.$suffix;

            if (array_key_exists($slot, $this->customPropertyCache)) {
                $result = [$this->customPropertyCache[$slot], isset($this->approximateProperties[$slot])];

                break;
            }

            $raw = $this->ownCustomProperty($node, $nodeKey, $name);
            $keyword = $raw === null ? null : strtolower(trim($raw));

            if ($raw !== null && ! in_array($keyword, self::INHERITING_KEYWORDS, true)) {
                $result = $keyword === 'initial' ? [null, false] : $this->declaredCustomProperty($node, $nodeKey, $nodeKey, $name, $raw, $depth);

                break;
            }

            if (count($this->customPropertyCache) + count($inheriting) >= self::MAX_CACHED_CUSTOM_PROPERTIES) {
                return [false, true];
            }

            $inheriting[] = [$slot, $keyword === 'revert-layer'];

            if ($universal) {
                $parent = $node->parentNode;
                [$node, $nodeKey] = $parent instanceof DOMElement ? [$parent, $this->nodeKey($parent)] : [null, ''];
            } else {
                [$node, $nodeKey] = $this->owner($this->nextOwner($nodeKey));
            }
        }

        [$value, $approximate] = $result ?? $this->rootCustomProperty($name, $depth);

        for ($i = count($inheriting) - 1; $i >= 0; $i--) {
            [$slot, $revertLayer] = $inheriting[$i];
            $approximate = $approximate || $revertLayer;
            $this->customPropertyCache[$slot] = $value;

            if ($approximate) {
                $this->approximateProperties[$slot] = true;
            }
        }

        return [$value, $approximate];
    }

    /**
     * The element's own cascaded declaration of $name: the heaviest among its matching rules (as in declarationsFor())
     * and its style attribute.
     */
    private function ownCustomProperty(DOMElement $element, string $key, string $name): ?string
    {
        $value = $weight = null;

        foreach ([$this->index[$key] ?? [], $this->customIndex[$key] ?? [], $this->universalCustomRules] as $positions) {
            foreach ($positions as $position) {
                $declaration = $this->rules[$position]['properties'][$name] ?? null;

                if ($declaration === null) {
                    continue;
                }

                $rule = $this->rules[$position];
                $candidate = [$declaration['important'] ? 1 : 0, 0, ...$rule['specificity'], $rule['order']];

                if ($weight === null || $candidate > $weight) {
                    [$value, $weight] = [$declaration['value'], $candidate];
                }
            }
        }

        $inline = $this->inlineCustomProperties[$key][$name] ?? null;

        if ($inline !== null && ($weight === null || [$inline['important'] ? 1 : 0, 1, 0, 0, 0, PHP_INT_MAX] > $weight)) {
            $value = $inline['value'];
        }

        return $value;
    }

    /**
     * A declared custom property value with its var() references substituted in the declaring element's context.
     * $owner names the declaration in the cache and the cycle guard.
     *
     * @return array{0: string|null|false, 1: bool}
     */
    private function declaredCustomProperty(DOMElement $element, string $key, string $owner, string $name, string $raw, int $depth): array
    {
        if (stripos($raw, 'var(') === false) {
            return [$raw, false];
        }

        $slot = $owner."\0".$depth.':'.$name;

        if (array_key_exists($slot, $this->customPropertyCache)) {
            return [$this->customPropertyCache[$slot], isset($this->approximateProperties[$slot])];
        }

        $guard = $owner."\0".$name;

        if (isset($this->computingProperties[$guard])) {
            return [null, true]; // a cycle: invalid at computed-value time
        }

        if (count($this->customPropertyCache) >= self::MAX_CACHED_CUSTOM_PROPERTIES) {
            return [false, true];
        }

        $this->computingProperties[$guard] = true;
        [$value, $approximate] = $this->substituteIn($element, $key, $raw, $depth);
        unset($this->computingProperties[$guard]);

        $this->customPropertyCache[$slot] = $value;

        if ($approximate) {
            $this->approximateProperties[$slot] = true;
        }

        return [$value, $approximate];
    }

    /**
     * $name when no element declares it: the root custom properties (customProperties()), which add :host
     * declarations and :root rules beyond the index budget, substituted in the document element's context.
     *
     * @return array{0: string|null|false, 1: bool}
     */
    private function rootCustomProperty(string $name, int $depth): array
    {
        $raw = $this->customProperties()[$name] ?? null;
        $keyword = $raw === null ? null : strtolower(trim($raw));
        [$root, $rootKey] = $this->rootContext ??= [$this->document?->root(), $this->document?->root() === null ? '' : $this->nodeKey($this->document->root())];

        if ($raw === null || $root === null || $keyword === 'initial' || in_array($keyword, self::INHERITING_KEYWORDS, true)) {
            return [null, false];
        }

        return $this->declaredCustomProperty($root, $rootKey, "\0root", $name, $raw, $depth);
    }

    /** Collect the declared names, the style-attribute custom properties and the names every element declares (once per parse). */
    private function prepareCustomProperties(): void
    {
        if ($this->declaredNames !== null) {
            return;
        }

        $this->buildIndex();
        $names = [];

        foreach ($this->rules as $rule) {
            foreach ($rule['properties'] as $name => $declaration) {
                if (str_starts_with((string) $name, '--')) {
                    $names[$name] = true;
                }
            }
        }

        foreach ($this->document?->query('//*[contains(@style, "--")]') ?? [] as $element) {
            $properties = array_filter($this->parseProperties($element->getAttribute('style')), fn (string $name): bool => str_starts_with($name, '--'), ARRAY_FILTER_USE_KEY);

            if ($properties !== []) {
                $key = $this->nodeKey($element);
                $this->inlineCustomProperties[$key] = $properties;
                $this->customOwners[$key] = true;
                $names += array_fill_keys(array_keys($properties), true);
            }
        }

        $winners = [];

        foreach ($this->universalRules as $position) {
            $rule = $this->rules[$position];

            foreach ($rule['properties'] as $name => $declaration) {
                if (str_starts_with((string) $name, '--')) {
                    $this->keepHeavier($winners, $name, $declaration, [$declaration['important'] ? 1 : 0, 0, ...$rule['specificity'], $rule['order']]);
                }
            }
        }

        foreach ($winners as $name => $winner) {
            if (! in_array(strtolower(trim($winner['declaration']['value'])), self::INHERITING_KEYWORDS, true)) {
                $this->universalNames[$name] = true; // a universal inherit is the same as no declaration
            }
        }

        $this->declaredNames = $names;
    }

    /** Node path of the nearest custom-property owner, the element itself or an ancestor; '' for none. One entry per element. */
    private function nearestOwner(DOMElement $element, string $key): string
    {
        $pending = [];
        $node = $element;
        $nodeKey = $key;

        while (true) {
            if (isset($this->ownerOf[$nodeKey])) {
                $owner = $this->ownerOf[$nodeKey];

                break;
            }

            if (isset($this->customOwners[$nodeKey])) {
                $owner = $nodeKey;
                $this->owners[$owner] ??= [$node, null];
                $pending[] = $nodeKey;

                break;
            }

            $pending[] = $nodeKey;
            $parent = $node->parentNode;

            if (! $parent instanceof DOMElement) {
                $owner = '';

                break;
            }

            $node = $parent;
            $nodeKey = $this->nodeKey($parent);
        }

        foreach ($pending as $pendingKey) {
            $this->ownerOf[$pendingKey] = $owner;
        }

        return $owner;
    }

    /** The owner above an owner; '' for none. */
    private function nextOwner(string $owner): string
    {
        if ($this->owners[$owner][1] === null) {
            $parent = $this->owners[$owner][0]->parentNode;
            $this->owners[$owner][1] = $parent instanceof DOMElement ? $this->nearestOwner($parent, $this->nodeKey($parent)) : '';
        }

        return $this->owners[$owner][1];
    }

    /** @return array{0: ?DOMElement, 1: string} the owner's element and node path, [null, ''] for none */
    private function owner(string $owner): array
    {
        return $owner === '' ? [null, ''] : [$this->owners[$owner][0], $owner];
    }

    /**
     * Whether an indexed rule takes part in the base cascade, which declarationsFor() and the custom properties share.
     * Every rule does; rules behind a media feature, @supports or @container count as if their condition held.
     *
     * @param  array{conditional?: bool}  $rule
     */
    private function inBaseCascade(array $rule): bool
    {
        return true;
    }

    /** @param  array{properties: array<string, array{value: string, important: bool}>}  $rule */
    private function declaresCustomProperties(array $rule): bool
    {
        foreach ($rule['properties'] as $name => $declaration) {
            if (str_starts_with((string) $name, '--')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The custom property names of a rule whose selector cannot be evaluated: the element may see their values, so
     * substituting them is approximate. Pseudo-element rules never style the element itself, and a selector whose only
     * unsupported parts are user-action states (:hover, :focus, …) does not apply to the base state that is measured;
     * :host declarations resolve through the root custom properties.
     *
     * @param  array{selector: string, properties: array<string, array{value: string, important: bool}>}  $rule
     */
    private function recordUncertainNames(array $rule): void
    {
        $selector = $rule['selector'];

        if (in_array(strtolower($selector), self::ROOT_SELECTORS, true)) {
            return; // :host resolves through the root custom properties
        }

        if (str_contains($selector, '::') || preg_match('/:(?:before|after|first-line|first-letter)(?![\w-])/i', $selector) === 1) {
            return;
        }

        $base = preg_replace('/:(?:hover|focus-visible|focus-within|focus|active|visited)(?![\w-])/i', '[_]', $selector) ?? $selector;

        if ($base !== $selector && $this->simpleSelectorToXpath($base) !== null) {
            return;
        }

        foreach ($rule['properties'] as $name => $declaration) {
            if (str_starts_with((string) $name, '--')) {
                $this->uncertainNames[$name] = true;
            }
        }
    }

    /**
     * Which index a rule belongs to: 'rule' when it carries one of INDEXED_PROPERTIES, 'custom' when it carries
     * custom properties but none of them, null when it is not indexed.
     *
     * @param  array{properties: array<string, array{value: string, important: bool}>}  $rule
     * @return 'rule'|'custom'|null
     */
    private function indexKind(array $rule): ?string
    {
        $custom = false;

        foreach ($rule['properties'] as $name => $declaration) {
            if (str_starts_with((string) $name, '--')) {
                $custom = true;
            } elseif (in_array($name, self::INDEXED_PROPERTIES, true)) {
                return 'rule';
            }
        }

        return $custom ? 'custom' : null;
    }

    /** @return list<array{selector: string, properties: array<string, array{value: string, important: bool}>, specificity: array{0: int, 1: int, 2: int}, order: int, sheet: int, conditional: bool}> */
    private function matchingRules(DOMElement $element): array
    {
        $this->buildIndex();

        return array_map(fn (int $position) => $this->rules[$position], $this->index[$this->nodeKey($element)] ?? []);
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

        [$value, $variablesApproximate] = $this->substituteValue($element, $key, $value);

        if ($value === null) {
            return $this->backgroundCache[$key] = [$parent, true];
        }

        [$own, $ownApproximate] = Color::fromBackground($value);
        $ownApproximate = $ownApproximate || $variablesApproximate;

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

        [$value, $variablesApproximate] = $this->substituteValue($element, $key, $value);
        $own = $value === null || Color::isUnresolvable($value) ? null : Color::parse($value);

        if ($own === null) {
            return $this->foregroundCache[$key] = [$parent, true];
        }

        return $this->foregroundCache[$key] = [$own, $approximate || $variablesApproximate];
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

    /** @param  array{id?: string, class?: string}  $tokens  receives the compound's first id and first class (decoded) */
    private function compoundToXpath(string $selector, int &$position, array &$tokens = []): ?string
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
            $predicate = $this->simplePredicate($selector, $position, $tokens);

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

    /** @param  array{id?: string, class?: string}  $tokens */
    private function simplePredicate(string $selector, int &$position, array &$tokens = []): ?string
    {
        $char = $selector[$position];
        $ident = '-?(?:[a-zA-Z_]|\\\\.|[^\x00-\x7F])(?:[\w-]|\\\\.|[^\x00-\x7F])*';

        if ($char === '#' || $char === '.') {
            if (preg_match('/'.$ident.'/A', $selector, $m, 0, $position + 1) !== 1) {
                return null;
            }

            $position += 1 + strlen($m[0]);
            $value = $this->unescape($m[0]);
            $tokens[$char === '#' ? 'id' : 'class'] ??= $value;

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

    /** Index of the parenthesis closing the one at $open (quotes respected), or null. */
    private function closingParenthesis(string $value, int $open): ?int
    {
        $depth = 0;
        $quote = null;
        $length = strlen($value);

        for ($i = $open; $i < $length; $i++) {
            $char = $value[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')' && --$depth === 0) {
                return $i;
            }
        }

        return null;
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
