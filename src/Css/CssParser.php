<?php

namespace ItsJustVita\LaravelBfsg\Css;

use DOMElement;
use DOMText;
use ItsJustVita\LaravelBfsg\Dom\Element;
use ItsJustVita\LaravelBfsg\Dom\HtmlDocument;
use Throwable;

/**
 * Small CSS engine for static analysis: tokenizes stylesheets, resolves the cascade for a
 * supported selector subset, and computes text/background colours and font metrics.
 *
 * A rule is an array{selector: string, properties: array<string, array{value: string, important: bool}>,
 * specificity: array{0: int, 1: int, 2: int}, order: int, sheet: int, conditional: bool, condition: 'preference'|'viewport'|null}.
 * `conditional`: the rule sits behind a media feature, @supports or @container. `condition` (from @media, <style media>
 * or an inlined <link media>; see HtmlDocument::mediaCondition()): 'preference' behind a media query that fails with the
 * default user preferences (PREFERENCE_DEFAULTS), else 'viewport' behind one that depends on another media feature and
 * behind @container, else null. Preference rules are not part of the base cascade.
 */
class CssParser
{
    /** Upper bound of indexed rules; only rules carrying one of INDEXED_PROPERTIES count. */
    public const MAX_INDEXED_RULES = 2000;

    /** Upper bound of rules indexed only for their custom properties (no INDEXED_PROPERTIES); a separate budget, so they never push colour rules out. */
    public const MAX_CUSTOM_PROPERTY_RULES = 4000;

    /** var() references followed per value; a value with more is unresolvable (approximate). */
    public const MAX_VAR_REFERENCES = 64;

    /** Seconds an index build may take when the caller passes no deadline; ContrastAnalyzer uses the same budget. */
    public const INDEX_TIME_BUDGET_SECONDS = 5.0;

    /**
     * Index entries (matched element × rule) of custom-property-only rules and maybe rules. The first rule that does not
     * fit fills the index: it and every later such rule are skipped without matching (truncated).
     */
    private const MAX_CUSTOM_INDEX_ENTRIES = 200000;

    /** User-action pseudo-classes: a selector that needs one outside parentheses never matches the base state that is measured. */
    private const USER_ACTION_STATE = '/:(?:hover|focus-visible|focus-within|focus|active|visited)(?![\w-])/Ai';

    /** Functional pseudo-classes whose selector-list arguments a maybe rule's reduction looks into. */
    private const MATCHES_ANY = ['is', 'where', 'matches', '-webkit-any', '-moz-any'];

    /** Longest rightmost compound a maybe rule's reduction looks into; a longer one covers every element. */
    private const MAX_REDUCED_COMPOUND_BYTES = 4096;

    /**
     * Colour and font properties (spec §6.1), display/visibility for CSS-hidden detection, and background-image, position
     * and opacity for the backdrop and the text alpha (resolveColors()).
     */
    public const INDEXED_PROPERTIES = [
        'color', 'background', 'background-color', 'background-image', 'font', 'font-size', 'font-weight', 'display', 'visibility',
        'position', 'opacity',
    ];

    /** Why a resolveColors() measurement is uncertain, in the order they are listed (see resolveColors()). */
    private const UNCERTAINTIES = ['backdrop', 'colour', 'limit'];

    /**
     * A function in a background layer that paints an image: url(), image(), image-set(), cross-fade(), element(),
     * paint() and every *-gradient(), with an optional vendor prefix. Possessive runs and the lookbehind keep it linear.
     */
    private const IMAGE_FUNCTION = '/(?<![\w-])(?:-[a-z]++-)?(?:url|image-set|image|cross-fade|element|paint|(?:[a-z]++-)*+gradient)\(/i';

    /** The first gradient function of a background value (see IMAGE_FUNCTION). */
    private const GRADIENT_FUNCTION = '/(?<![\w-])(?:-[a-z]++-)?(?:[a-z]++-)*+gradient\(/i';

    /** Elements that may cover a later sibling's backdrop when they are positioned out of flow (absolute or fixed). */
    private const COVERING_MEDIA = ['img', 'picture', 'video', 'canvas', 'svg'];

    /** Media that make an out-of-flow element containing them cover a later sibling's backdrop (an icon svg does not). */
    private const CONTAINED_MEDIA = ['img', 'picture', 'video', 'canvas'];

    /** Candidate siblings of one element that are looked up (see coveringSiblingIndex()); the next one counts as covering (never silent). */
    private const MAX_EXAMINED_SIBLINGS = 64;

    /** Nodes of an out-of-flow sibling searched for media and text (isImageLayer()); beyond them, a sibling without text so far counts (never silent). */
    private const MAX_MEDIA_DESCENDANTS = 1024;

    /** Elements whose content is not rendered text: isImageLayer() does not look into them. */
    private const UNRENDERED_CONTENT = ['script', 'style', 'template'];

    /** A layer whose alpha is at least this paints opaquely: the elements behind it do not show through. */
    private const OPAQUE_ALPHA = 1.0 - 1e-9;

    /** Bytes of the background-value memo (layers()): the values plus LAYER_ENTRY_BYTES each; it starts over when full. */
    private const MAX_LAYER_CACHE_BYTES = 8 * 1024 * 1024;

    /** Bookkeeping counted per memo entry on top of its value: the bucket and the nested result arrays with their colours. */
    private const LAYER_ENTRY_BYTES = 1024;

    /** At-rules whose body is unwrapped (their rules apply unconditionally for static analysis). */
    private const UNWRAPPED_AT_RULES = ['layer', 'supports', 'container', 'scope', 'document', '-moz-document'];

    /** Media features of user preferences: rules behind a query that fails with their defaults are not part of the base cascade (another theme). */
    public const PREFERENCE_FEATURES = ['prefers-color-scheme', 'prefers-contrast', 'forced-colors', 'inverted-colors', 'prefers-reduced-transparency'];

    /** The default value of each of PREFERENCE_FEATURES: the base cascade is the page as a user with these preferences sees it. */
    public const PREFERENCE_DEFAULTS = [
        'prefers-color-scheme' => 'light', 'prefers-contrast' => 'no-preference', 'forced-colors' => 'none',
        'inverted-colors' => 'none', 'prefers-reduced-transparency' => 'no-preference',
    ];

    /** Nesting levels of @media, unwrapped at-rules and nested style rules followed (one count); deeper blocks are dropped and the results marked truncated. */
    private const MAX_AT_RULE_DEPTH = 16;

    /** Selectors a nested style rule may expand to (each `&` once per parent selector); a rule with more is skipped (truncated). */
    public const MAX_NESTED_SELECTOR_COMBINATIONS = 32;

    /** Longest selector a nested style rule may expand to; a rule with a longer one is skipped (truncated). */
    private const MAX_NESTED_SELECTOR_BYTES = 4096;

    /**
     * What nesting may add per parse: the bytes of the selectors nested style rules expand to, and per rule entry of a
     * nested block (or of declarations after one, which repeat each selector of their rule) its selector bytes plus
     * NESTED_ENTRY_BYTES. A block that does not fit fills the budget; it and every later nested block are skipped (truncated).
     */
    private const MAX_NESTED_RULE_BYTES = 16 * 1024 * 1024;

    /** Memory of one rule entry besides its selector: the rule and specificity arrays and its properties (measured about 1.7 KB with one declaration). */
    private const NESTED_ENTRY_BYTES = 2048;

    private const FONT_KEYWORDS = [
        'xx-small' => 9.0, 'x-small' => 10.0, 'small' => 13.0, 'medium' => 16.0,
        'large' => 18.0, 'x-large' => 24.0, 'xx-large' => 32.0, 'xxx-large' => 48.0,
    ];

    /** Default font-size factor relative to the parent, per UA stylesheet. */
    private const TAG_FONT_FACTORS = ['h1' => 2.0, 'h2' => 1.5, 'h3' => 1.17, 'h4' => 1.0, 'h5' => 0.83, 'h6' => 0.67, 'small' => 0.83, 'sub' => 0.83, 'sup' => 0.83];

    /** Form controls: the UA font (-webkit-small-control, -moz-field) is 13.333px whatever the parent's size. */
    private const FORM_CONTROL_FONT_SIZES = ['button' => 13.333, 'input' => 13.333, 'select' => 13.333, 'textarea' => 13.333];

    /** Elements the UA stylesheet makes bold (700). */
    private const BOLD_TAGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'th'];

    /** Elements the UA stylesheet makes `bolder` than their parent. */
    private const BOLDER_TAGS = ['b', 'strong'];

    /** Nesting levels of math functions and parentheses a font-size lower bound follows; deeper is unknown. */
    private const MAX_CALC_DEPTH = 32;

    /** Absolute length units in px. */
    private const ABSOLUTE_UNITS = ['px' => 1.0, 'pt' => 4 / 3, 'pc' => 16.0, 'in' => 96.0, 'cm' => 96 / 2.54, 'mm' => 96 / 25.4, 'q' => 96 / 101.6];

    /** Viewport and container units: no bound, but at least 0 with a non-negative coefficient (a floor for sums). */
    private const VIEWPORT_UNITS = '/^(?:[sld]?v(?:w|h|i|b|min|max)|cq(?:w|h|i|b|min|max))$/';

    /** Font-relative units other than em and rem: valid, but without a bound. */
    private const FONT_RELATIVE_UNITS = '/^r?(?:ex|ch|lh|cap|ic)$/';

    /** larger / smaller as factors of the parent size: engines use 1.2 and 1 / 1.2, so both are lower bounds. */
    private const RELATIVE_FONT_SIZES = ['larger' => 1.2, 'smaller' => 0.83];

    /** System fonts of the `font` shorthand: their size and weight are platform-dependent. */
    private const SYSTEM_FONTS = ['caption', 'icon', 'menu', 'message-box', 'small-caption', 'status-bar'];

    /** Keywords that may precede the size in the `font` shorthand besides weights: font-style, font-variant-css2, font-width-css3. */
    private const FONT_PREFIX_KEYWORDS = [
        'normal', 'italic', 'oblique', 'small-caps', 'ultra-condensed', 'extra-condensed', 'condensed', 'semi-condensed',
        'semi-expanded', 'expanded', 'extra-expanded', 'ultra-expanded',
    ];

    /** `bolder` and `lighter` (CSS Fonts): [parent weight below, bolder, lighter] per row; null keeps the parent's weight. */
    private const RELATIVE_WEIGHTS = [[100, 400, null], [350, 400, 100], [550, 700, 100], [750, 900, 400], [900, 900, 700], [PHP_FLOAT_MAX, null, 700]];

    /** @var list<array{selector: string, properties: array<string, array{value: string, important: bool}>, specificity: array{0: int, 1: int, 2: int}, order: int, sheet: int, conditional: bool, condition: 'preference'|'viewport'|null}> */
    protected array $rules = [];

    /** @var array<string, list<int>> node path => positions in rules() of the matching rules that carry one of INDEXED_PROPERTIES */
    protected array $index = [];

    /** @var array<string, list<int>> node path => positions in rules() of the matching rules that carry custom properties but none of INDEXED_PROPERTIES */
    protected array $customIndex = [];

    /** @var list<int> custom-property-only rules with the selector `*`: they match every element, so they are not indexed per element */
    private array $universalCustomRules = [];

    /** @var list<int> every rule with the selector `*` that declares custom properties (both indexes) */
    private array $universalRules = [];

    /** @var array<string, true> node path => true for elements whose non-universal matching rules, maybe rules or style attribute declare custom properties */
    private array $customOwners = [];

    /**
     * @var array<string, list<int>> node path => positions of the "maybe" rules covering the element: rules with custom properties whose
     *                               selector cannot be evaluated, matched by the supported simple selectors of their rightmost compound
     */
    private array $maybeIndex = [];

    /** @var list<int> maybe rules whose reduced rightmost compound is empty: they may match every element */
    private array $universalMaybeRules = [];

    /** Entries in customIndex and maybeIndex, counted against MAX_CUSTOM_INDEX_ENTRIES. */
    private int $customIndexEntries = 0;

    /** Bytes counted for the rule entries nesting added, against MAX_NESTED_RULE_BYTES. */
    private int $nestedRuleBytes = 0;

    protected bool $indexed = false;

    protected bool $truncated = false;

    /** Number of times the element index was built (test probe; stays 1 per parse). */
    protected int $indexBuilds = 0;

    protected ?HtmlDocument $document = null;

    /** @var array<string, array{0: Color, 1: list<string>, 2: ?Color, 3: list<string>, 4: array{0: bool, 1: ?Color}}> node path => background() */
    private array $backgroundCache = [];

    /** @var array<string, array{0: Color, 1: list<string>}> node path => foreground() */
    private array $foregroundCache = [];

    /** @var array<string, array{0: float, 1: list<string>, 2: float}> node path => opacity product, its uncertainty, the element's own opacity */
    private array $opacityCache = [];

    /** @var array<string, ?int> node path of a parent => element-child index of its first covering child (see coveringSiblingIndex()) */
    private array $coveringMedia = [];

    /** @var array<string, true> node path => true for the elements an indexed rule declaring `position` matches */
    private array $positioned = [];

    /** @var array<string, array<'image'|'colour'|'final', array{0: mixed, 1: mixed}>> background value => its analysis (see layers()) */
    private array $layerCache = [];

    /** Bytes counted for layerCache. */
    private int $layerCacheBytes = 0;

    /** @var array<string, float> */
    private array $fontSizeCache = [];

    /** @var array<string, ?float> node path => lower bound of the font size in px (null: unknown) */
    private array $fontSizeBoundCache = [];

    /** @var array<string, ?float> node path => computed font weight (null: unknown) */
    private array $fontWeightCache = [];

    /** Whether a viewport rule declares display:none or visibility:hidden|collapse (hidesElement() then leaves them out). */
    private bool $viewportHiding = false;

    /** @var array<string, array<string, array{value: string, important: bool}>> node path => display and visibility without hiding viewport declarations */
    private array $visibilityCache = [];

    /** @var array<string, array<string, array{value: string, important: bool}>> */
    private array $declarationCache = [];

    /** @var array<string, array<string, list<int>>> node path => property => cascade weight of the winner */
    private array $weightCache = [];

    /** @var array<string, string>|null custom property name => value, from :root / html / :host */
    private ?array $customProperties = null;

    /** @var array<string, true> custom property names also declared by non-root rules of the base cascade (substitution is approximate) */
    private array $overriddenProperties = [];

    /** @var array<string, array{0: ?string, 1: bool}> "depth:name" => substituted value (null: unresolvable) and whether it is approximate */
    private array $resolvedProperties = [];

    /** Substituted values longer than this are given up (approximate): guards against exponential var() nesting. */
    private const MAX_RESOLVED_LENGTH = 4096;

    /** Nesting levels of var() references followed before a value counts as unresolvable. */
    private const MAX_VAR_DEPTH = 8;

    /** CSS-wide keywords that make a custom property take its parent's value (custom properties inherit). */
    private const INHERITING_KEYWORDS = ['inherit', 'unset', 'revert', 'revert-layer'];

    /** Entries of the custom-property cache; a lookup that would add one beyond it is unresolvable (approximate). */
    private const MAX_CACHED_CUSTOM_PROPERTIES = 200000;

    /** Bytes of the custom-property cache (keys, values and CACHE_ENTRY_BYTES per entry); beyond it a lookup that would add an entry is unresolvable. */
    private const MAX_CACHED_CUSTOM_PROPERTY_BYTES = 16 * 1024 * 1024;

    /** PHP bookkeeping counted per cache entry on top of its key and value bytes: hash bucket and slot at power-of-two capacity, key string, approximate flag (measured about 150 bytes). */
    private const CACHE_ENTRY_BYTES = 160;

    /** An identifier as in CSS selectors: escapes and non-ASCII characters allowed. */
    private const IDENTIFIER = '-?(?:[a-zA-Z_]|\\\\.|[^\x00-\x7F])(?:[\w-]|\\\\.|[^\x00-\x7F])*';

    /** @var array<string, true>|null every custom property name a rule or a style attribute declares; null until prepared */
    private ?array $declaredNames = null;

    /** @var array<string, list<int>> name => positions of the rules that declare it, ascending */
    private array $declarers = [];

    /** @var array<string, list<int>> name => positions of the custom-property-only universal rules that declare it */
    private array $universalDeclarers = [];

    /** @var array<string, list<int>> name => positions of the universal maybe rules that declare it */
    private array $universalMaybe = [];

    /** @var array<string, true> names every element may declare (a universal value or a universal maybe rule): looked up from the element itself */
    private array $universalNames = [];

    /** @var array<string, int> name => compact id used in cache keys */
    private array $nameIds = [];

    /** @var array<string, int> node path => compact id of the element, for the elements the custom-property lookups visit */
    private array $elementIds = [];

    /** @var array<int, array{0: DOMElement, 1: string, 2: ?int, 3: ?int}> id => element, node path, id of the next owner above and of the parent (0: none, null: not known yet) */
    private array $elements = [];

    /** @var array<string, int> node path => id of the nearest custom-property owner (the element or an ancestor), 0 for none */
    private array $ownerOf = [];

    /**
     * @var array<string, string|null|false> "d|i<element id>:<depth>:<name id>" => a declared value with var() substituted (d) or the value an owner
     *                                       inherits (i); null: invalid at computed-value time, false: unresolvable
     */
    private array $customPropertyCache = [];

    /** @var array<string, true> customPropertyCache keys whose value is approximate */
    private array $approximateProperties = [];

    /** Bytes counted for the custom-property cache. */
    private int $cachedBytes = 0;

    /** @var array<string, array<string, array{value: string, important: bool}>> node path => custom properties of its style attribute */
    private array $inlineCustomProperties = [];

    /** @var array<string, true> "<element id>:<name id>" of the declarations being substituted (cycle detection) */
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
        $this->index = $this->customIndex = $this->universalCustomRules = $this->universalRules = $this->customOwners = $this->maybeIndex = $this->universalMaybeRules = $this->positioned = [];
        $this->customIndexEntries = $this->nestedRuleBytes = 0;
        $this->indexed = false;
        $this->indexBuilds = 0;
        $this->truncated = false;
        $this->backgroundCache = $this->foregroundCache = $this->fontSizeCache = $this->declarationCache = $this->weightCache = [];
        $this->fontSizeBoundCache = $this->fontWeightCache = $this->visibilityCache = $this->opacityCache = $this->coveringMedia = $this->layerCache = [];
        $this->layerCacheBytes = 0;
        $this->viewportHiding = false;
        $this->customProperties = null;
        $this->overriddenProperties = $this->resolvedProperties = [];
        $this->declaredNames = $this->rootContext = null;
        $this->declarers = $this->universalDeclarers = $this->universalMaybe = $this->universalNames = $this->nameIds = [];
        $this->elementIds = $this->elements = $this->ownerOf = $this->customPropertyCache = $this->approximateProperties = [];
        $this->inlineCustomProperties = $this->computingProperties = [];
        $this->cachedBytes = 0;

        $media = $document->styleSheetMedia();

        foreach ($this->stylesheetsFor($document) as $sheet => $css) {
            $condition = HtmlDocument::mediaCondition($media[$sheet] ?? '');

            foreach ($this->parseStylesheet($css, count($this->rules), $condition === false ? null : $condition) as $rule) {
                $rule['sheet'] = $sheet;
                $this->rules[] = $rule;
            }
        }

        $counts = ['rule' => 0, 'custom' => 0];

        foreach ($this->rules as $rule) {
            $kind = $this->indexKind($rule);

            if ($kind !== null && $this->inBaseCascade($rule)) {
                $counts[$kind]++;
            }

            if ($rule['condition'] === 'viewport' && $this->hidingDeclarations($rule['properties']) !== []) {
                $this->viewportHiding = true;
            }
        }

        // parseStylesheet() may already have marked the rules truncated (at-rules nested too deep).
        $this->truncated = $this->truncated || $counts['rule'] > self::MAX_INDEXED_RULES || $counts['custom'] > self::MAX_CUSTOM_PROPERTY_RULES;

        return $this;
    }

    /**
     * Build the element index unless it exists. Rules carrying one of INDEXED_PROPERTIES and rules carrying only
     * custom properties have separate budgets (MAX_INDEXED_RULES, MAX_CUSTOM_PROPERTY_RULES), and custom-property and
     * maybe rules together index at most MAX_CUSTOM_INDEX_ENTRIES matches: rules beyond a budget are skipped. The build
     * stops when the deadline (microtime(true) value; INDEX_TIME_BUDGET_SECONDS from the call when none is given)
     * passes. Each of these marks the results truncated (approximate).
     */
    public function buildIndex(?float $deadline = null): static
    {
        if ($this->indexed) {
            return $this;
        }

        $this->indexBuilds++;
        $deadline ??= microtime(true) + self::INDEX_TIME_BUDGET_SECONDS;
        $counts = ['rule' => 0, 'custom' => 0];
        $limits = ['rule' => self::MAX_INDEXED_RULES, 'custom' => self::MAX_CUSTOM_PROPERTY_RULES];
        $tokens = null;
        $keys = []; // spl_object_id => [element, node path] of the elements custom-property and maybe rules matched

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

            if (microtime(true) > $deadline) {
                $this->truncated = true;

                break;
            }

            $custom = $kind === 'custom' || $this->declaresCustomProperties($rule);

            if ($kind === 'custom' && $this->customIndexEntries >= self::MAX_CUSTOM_INDEX_ENTRIES) {
                $this->truncated = true; // the custom-property index is full: skip without matching

                continue;
            }

            $expression = $this->document === null ? null : $this->simpleSelectorToXpath($rule['selector']);

            if ($expression === null) {
                if ($custom) {
                    $this->indexMaybeRule($position, $rule, $tokens, $keys);
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

            if ($kind === 'custom') {
                $this->indexCustomMatches('customIndex', $position, $this->elementsMatching($rule['selector'], $expression, $tokens), $keys);

                continue;
            }

            $positioning = isset($rule['properties']['position']);

            foreach ($this->elementsMatching($rule['selector'], $expression, $tokens) as $element) {
                $key = $this->nodeKey($element);
                $this->index[$key][] = $position;

                if ($custom) {
                    $this->customOwners[$key] = true;
                }

                if ($positioning) {
                    $this->positioned[$key] = true;
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

    /** @return list<array{selector: string, properties: array<string, array{value: string, important: bool}>, specificity: array{0: int, 1: int, 2: int}, order: int, sheet: int, conditional: bool, condition: 'preference'|'viewport'|null}> */
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
     * Nested style rules are flattened (see parseRuleBody()).
     * $condition is the condition of the whole sheet (its media attribute, see HtmlDocument::mediaCondition());
     * blocks nested more than MAX_AT_RULE_DEPTH levels deep are dropped and mark the parser truncated.
     *
     * @param  'preference'|'viewport'|null  $condition
     * @return list<array{selector: string, properties: array<string, array{value: string, important: bool}>, specificity: array{0: int, 1: int, 2: int}, order: int, sheet: int, conditional: bool, condition: 'preference'|'viewport'|null}>
     */
    public function parseStylesheet(string $css, int $orderOffset = 0, ?string $condition = null): array
    {
        $rules = [];
        $position = 0;
        $this->parseBlock(self::stripComments($css), $position, $rules, $orderOffset, $condition !== null, $condition);

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
        return $this->declarations($element, $this->nodeKey($element));
    }

    /**
     * declarationsFor() with the element's node key.
     *
     * @return array<string, array{value: string, important: bool}>
     */
    private function declarations(DOMElement $element, string $key): array
    {
        if (isset($this->declarationCache[$key])) {
            return $this->declarationCache[$key];
        }

        $winners = [];

        foreach ($this->matchingRules($element, $key) as $rule) {
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
     * Effective text and background colour of an element. The text colour's alpha is scaled by opacity() before it is
     * composited over the background. `uncertainty` lists why the measurement is not exact, in this order:
     * - `backdrop`: from the element up to the element that supplies the opaque background used, a background layer
     *   (`background-image` or `background`) paints an image (IMAGE_FUNCTION: url(), image-set(), *-gradient(), …), or a
     *   rendered previous element sibling of one of these elements is positioned absolute or fixed and is an img,
     *   picture, video, canvas or svg, or an image layer: one that contains an img, picture, video or canvas and no
     *   text (coveringSiblingIndex()). Gradients
     *   are estimated by their first stop; a url() without a colour by the backdrop behind it;
     * - `colour`: an unresolvable or approximate var() substitution (substituteFor()), or a colour value Color can only
     *   approximate, in the text colour, the background or the opacity; or an element of that range paints its own
     *   background inside an opacity group (opacity() below 1), whose compositing is not modelled;
     * - `limit`: the index is truncated (isTruncated()).
     * `approximate` is true exactly when `uncertainty` is not empty.
     *
     * @return array{foreground: Color, background: Color, approximate: bool, uncertainty: list<'backdrop'|'colour'|'limit'>}
     */
    public function resolveColors(DOMElement $element): array
    {
        $key = $this->nodeKey($element);
        [$background, $backgroundUncertainty] = $this->background($element, $key);
        [$foreground, $foregroundUncertainty] = $this->foreground($element, $key);
        [$opacity, $opacityUncertainty] = $this->opacityOf($element, $key);

        if ($opacity < 1) {
            $foreground = new Color($foreground->r, $foreground->g, $foreground->b, $foreground->a * $opacity);
        }

        if ($foreground->a < 1) {
            $foreground = $foreground->over($background);
        }

        $uncertainty = $this->uncertainty($backgroundUncertainty, $foregroundUncertainty, $opacityUncertainty, $this->truncated ? ['limit'] : []);

        return [
            'foreground' => $foreground,
            'background' => $background,
            'approximate' => $uncertainty !== [],
            'uncertainty' => $uncertainty,
        ];
    }

    /**
     * The product of the cascaded `opacity` of the element and its ancestors, clamped to 0..1. Numbers and percentages
     * count, var() substituted; inherit takes the parent's own opacity; initial, unset, revert and revert-layer are 1. Any
     * other value counts as 1 and makes resolveColors() uncertain (`colour`).
     */
    public function opacity(DOMElement $element): float
    {
        return $this->opacityOf($element, $this->nodeKey($element))[0];
    }

    /**
     * opacity() with its uncertainty and the element's own opacity.
     *
     * @return array{0: float, 1: list<string>, 2: float}
     */
    private function opacityOf(DOMElement $element, string $key): array
    {
        if (isset($this->opacityCache[$key])) {
            return $this->opacityCache[$key];
        }

        $node = $element->parentNode;
        [$parent, $inherited, $parentOwn] = $node instanceof DOMElement ? $this->opacityOf($node, $this->parentKey($key, $node)) : [1.0, [], 1.0];
        $declared = $this->declarations($element, $key)['opacity']['value'] ?? null;

        if ($declared === null) {
            return $this->opacityCache[$key] = [$parent, $inherited, 1.0];
        }

        [$value, $approximate] = $this->substituteValue($element, $key, $declared);
        $value = $value === null || strlen($value) > 64 ? '' : strtolower(trim($value)); // longer is no opacity: unknown
        $own = match (true) {
            $value === 'inherit' => $parentOwn,
            in_array($value, ['initial', 'unset', 'revert', 'revert-layer'], true) => 1.0,
            preg_match('/^[+-]?+(?:\d++(?:\.\d*+)?+|\.\d++)(?:e[+-]?+\d++)?+(%?+)$/D', $value, $m) === 1 => (float) $value / ($m[1] === '%' ? 100 : 1),
            default => null,
        };
        $uncertainty = $this->uncertainty($inherited, $approximate || $own === null || ! is_finite($own) ? ['colour'] : []);
        $own = $own === null || ! is_finite($own) ? 1.0 : max(0.0, min(1.0, $own));

        return $this->opacityCache[$key] = [max(0.0, min(1.0, $parent * $own)), $uncertainty, $own];
    }

    /**
     * The uncertainties named in the lists, once each, in the order of UNCERTAINTIES.
     *
     * @param  list<string>  ...$lists
     * @return list<'backdrop'|'colour'|'limit'>
     */
    private function uncertainty(array ...$lists): array
    {
        return array_values(array_intersect(self::UNCERTAINTIES, array_merge(...$lists)));
    }

    /** Font size in px: the lower bound (fontSizeLowerBoundPx()) where there is one, else an estimate. */
    public function fontSizePx(DOMElement $element): float
    {
        $key = $this->nodeKey($element);

        if (isset($this->fontSizeCache[$key])) {
            return $this->fontSizeCache[$key];
        }

        $bound = $this->fontSizeLowerBoundPx($element);

        if ($bound !== null) {
            return $this->fontSizeCache[$key] = $bound;
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
     * A guaranteed lower bound of the element's font size in px, null when there is none (unknown size). The winning
     * `font-size` or `font` shorthand declaration, var() substituted, is evaluated: absolute units exactly, rem against
     * the root's bound (16px when the root has none), em and % against the parent's bound, keywords per FONT_KEYWORDS;
     * viewport, container and font-relative units have no bound. calc(), min(), max() and clamp() combine the bounds of
     * their arguments (sums, products with a positive number); any other form, nesting deeper than MAX_CALC_DEPTH, a value
     * longer than MAX_RESOLVED_LENGTH, a non-finite number and an approximate substitution have none. Without a
     * declaration the parent's bound applies, scaled for headings and <small> as in the UA stylesheet.
     */
    public function fontSizeLowerBoundPx(DOMElement $element): ?float
    {
        $key = $this->nodeKey($element);

        if (array_key_exists($key, $this->fontSizeBoundCache)) {
            return $this->fontSizeBoundCache[$key];
        }

        $parent = $element->parentNode instanceof DOMElement ? $this->fontSizeLowerBoundPx($element->parentNode) : 16.0;
        $declared = $this->winningDeclaration($element, $key, 'font-size', 'font');

        if ($declared === null) {
            return $this->fontSizeBoundCache[$key] = $this->defaultFontSize($element, $parent);
        }

        [$value, $approximate] = $this->substituteValue($element, $key, $declared[0]);

        if ($value === null || $approximate || strlen($value) > self::MAX_RESOLVED_LENGTH) {
            return $this->fontSizeBoundCache[$key] = null;
        }

        $value = $declared[1] ? $this->fontShorthand($value)['size'] ?? null : strtolower(trim($value));

        return $this->fontSizeBoundCache[$key] = $value === null ? null : $this->fontSizeBound($value, $parent, $element);
    }

    /**
     * Whether the stylesheets hide the element: cascaded display:none on self or an ancestor, or an inherited
     * visibility:hidden/collapse that no closer element resets to visible. The hiding declarations of viewport-conditional
     * rules never win, their revealing ones do (`md:hidden` is measured, and so is `hidden md:block lg:hidden`).
     */
    public function hidesElement(DOMElement $element): bool
    {
        // Leaving declarations out can only reveal: only a result hidden in declarationsFor()'s cascade is checked again.
        return $this->hiddenIn($element, false) && (! $this->viewportHiding || $this->hiddenIn($element, true));
    }

    /** hidesElement() in declarationsFor()'s cascade, or ($revealing) in the one without hiding viewport declarations. */
    private function hiddenIn(DOMElement $element, bool $revealing): bool
    {
        $visibility = null;

        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
            $declarations = $revealing ? $this->revealingVisibility($node) : $this->declarationsFor($node);

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

    /**
     * The element's cascaded display and visibility declarations without the hiding declarations of viewport rules.
     *
     * @return array<string, array{value: string, important: bool}>
     */
    private function revealingVisibility(DOMElement $element, ?string $key = null): array
    {
        $key ??= $this->nodeKey($element);

        if (isset($this->visibilityCache[$key])) {
            return $this->visibilityCache[$key];
        }

        $winners = [];

        foreach ($this->matchingRules($element, $key) as $rule) {
            $declarations = array_intersect_key($rule['properties'], ['display' => true, 'visibility' => true]);

            if ($rule['condition'] === 'viewport') {
                $declarations = array_diff_key($declarations, $this->hidingDeclarations($declarations));
            }

            foreach ($declarations as $name => $declaration) {
                $this->keepHeavier($winners, $name, $declaration, [$declaration['important'] ? 1 : 0, 0, ...$rule['specificity'], $rule['order']]);
            }
        }

        foreach (array_intersect_key($this->inlineStyle($element), ['display' => true, 'visibility' => true]) as $name => $declaration) {
            $this->keepHeavier($winners, $name, $declaration, [$declaration['important'] ? 1 : 0, 1, 0, 0, 0, PHP_INT_MAX]);
        }

        return $this->visibilityCache[$key] = array_map(fn (array $winner) => $winner['declaration'], $winners);
    }

    /**
     * The hiding declarations among a rule's properties: display:none, visibility:hidden or visibility:collapse.
     *
     * @param  array<string, array{value: string, important: bool}>  $properties
     * @return array<string, array{value: string, important: bool}>
     */
    private function hidingDeclarations(array $properties): array
    {
        $display = strtolower(trim($properties['display']['value'] ?? ''));
        $visibility = strtolower(trim($properties['visibility']['value'] ?? ''));

        return array_intersect_key($properties, array_filter([
            'display' => $display === 'none',
            'visibility' => $visibility === 'hidden' || $visibility === 'collapse',
        ]));
    }

    /** Whether the element's computed font weight is at least 700 (boldness() === true): an unknown weight is not bold. */
    public function isBold(DOMElement $element): bool
    {
        return $this->boldness($element) === true;
    }

    /**
     * Whether the element's computed font weight is at least 700; null when the weight is unknown. The winning
     * `font-weight` or `font` shorthand declaration (which sets `normal` unless it names a weight) counts, var()
     * substituted. inherit, unset, an undefined reference and no declaration take the parent's weight; without a
     * declaration h1–h6 and th are 700 and b and strong `bolder` than the parent (UA stylesheet); bolder and lighter
     * follow the CSS Fonts table. Unknown: an approximate substitution, a substitution limit, a system-font shorthand,
     * revert, revert-layer, and anything inheriting from or relative to an unknown weight.
     */
    public function boldness(DOMElement $element): ?bool
    {
        $weight = $this->fontWeight($element);

        return $weight === null ? null : $weight >= 700;
    }

    /** Computed font weight of the element (null: unknown); see boldness(). */
    private function fontWeight(DOMElement $element): ?float
    {
        $key = $this->nodeKey($element);

        if (array_key_exists($key, $this->fontWeightCache)) {
            return $this->fontWeightCache[$key];
        }

        $parent = fn (): ?float => $element->parentNode instanceof DOMElement ? $this->fontWeight($element->parentNode) : 400.0;
        $declared = $this->winningDeclaration($element, $key, 'font-weight', 'font');

        if ($declared === null) {
            return $this->fontWeightCache[$key] = $this->defaultFontWeight($element, $parent);
        }

        [$value, $approximate] = $this->substituteIn($element, $key, $declared[0], 0);

        if ($value === false || $approximate || (is_string($value) && strlen($value) > self::MAX_RESOLVED_LENGTH)) {
            return $this->fontWeightCache[$key] = null; // a limit, a cycle, or a maybe rule could win
        }

        if ($value === null) {
            return $this->fontWeightCache[$key] = $parent(); // invalid at computed-value time: as unset, which inherits
        }

        $value = $declared[1] ? $this->fontShorthand($value)['weight'] ?? null : strtolower(trim($value));
        $weight = match (true) {
            $value === null, $value === 'revert', $value === 'revert-layer' => null,
            in_array($value, ['inherit', 'unset'], true) => $parent(),
            $value === 'initial', $value === 'normal' => 400.0,
            $value === 'bold' => 700.0,
            $value === 'bolder', $value === 'lighter' => $this->relativeWeight($parent(), $value),
            is_numeric($value) && (float) $value >= 1 && (float) $value <= 1000 => (float) $value,
            // Invalid: a literal is dropped at parse time (approximated by the UA weight), a substituted one is invalid at computed-value time.
            default => stripos($declared[0], 'var(') === false ? $this->defaultFontWeight($element, $parent) : $parent(),
        };

        return $this->fontWeightCache[$key] = $weight;
    }

    /**
     * The UA weight without a declaration: 700 for BOLD_TAGS, `bolder` than the parent for BOLDER_TAGS, else inherited.
     *
     * @param  callable(): ?float  $parent
     */
    private function defaultFontWeight(DOMElement $element, callable $parent): ?float
    {
        $tag = Element::tag($element);

        return match (true) {
            in_array($tag, self::BOLD_TAGS, true) => 700.0,
            in_array($tag, self::BOLDER_TAGS, true) => $this->relativeWeight($parent(), 'bolder'),
            default => $parent(),
        };
    }

    /** `bolder` or `lighter` of the parent's weight, per the CSS Fonts table. */
    private function relativeWeight(?float $parent, string $keyword): ?float
    {
        if ($parent === null) {
            return null;
        }

        foreach (self::RELATIVE_WEIGHTS as [$below, $bolder, $lighter]) {
            if ($parent < $below) {
                return (float) (($keyword === 'bolder' ? $bolder : $lighter) ?? $parent);
            }
        }

        return $parent;
    }

    /**
     * Parse the rules from $position to the end of $css or, inside a group rule ($depth > 0), through its closing brace.
     * One pass: every block is read by the level that parses it (or skips it), so nesting adds no re-reading.
     *
     * @param  list<array{selector: string, properties: array<string, array{value: string, important: bool}>, specificity: array{0: int, 1: int, 2: int}, order: int, sheet: int, conditional: bool, condition: 'preference'|'viewport'|null}>  $rules
     * @param  'preference'|'viewport'|null  $condition
     */
    private function parseBlock(string $css, int &$position, array &$rules, int $orderOffset, bool $conditional = false, ?string $condition = null, int $depth = 0): void
    {
        $length = strlen($css);

        while (true) {
            $this->skipWhitespace($css, $position);

            if ($position >= $length) {
                return;
            }

            if ($depth > 0 && $css[$position] === '}') {
                $position++;

                return;
            }

            if ($css[$position] === '@') {
                $group = $this->groupRule($css, $position, $conditional, $condition, $depth, $depth > 0);

                if ($group !== null) {
                    $this->parseBlock($css, $position, $rules, $orderOffset, $group[0], $group[1], $depth + 1);
                }

                continue;
            }

            [$prelude, $terminator] = $this->readUntil($css, $position, $depth > 0 ? ['{', '}'] : ['{']);

            if ($terminator !== '{') {
                return; // the end of the input or of the group rule
            }

            $this->parseRuleBody($css, $position, $prelude, $rules, $orderOffset, $conditional, $condition, $depth, false);
        }
    }

    /**
     * Read the at-rule at $position. A group rule whose rules apply (@media for all/screen, UNWRAPPED_AT_RULES) leaves
     * $position inside its block and returns its [conditional, condition]; any other at-rule is skipped, and so is a group
     * rule nested MAX_AT_RULE_DEPTH levels deep (truncated). Inside a block ($inBlock) a prelude ends at its closing brace,
     * which is left for the block's parser.
     *
     * @param  'preference'|'viewport'|null  $condition
     * @return array{0: bool, 1: 'preference'|'viewport'|null}|null
     */
    private function groupRule(string $css, int &$position, bool $conditional, ?string $condition, int $depth, bool $inBlock): ?array
    {
        preg_match('/@([\w-]++)/A', $css, $m, 0, $position);
        $name = strtolower($m[1] ?? '');
        $position += strlen($m[0] ?? '@');
        [$prelude, $terminator] = $this->readUntil($css, $position, $inBlock ? ['{', ';', '}'] : ['{', ';']);

        if ($terminator === '}') {
            $position--;
        }

        if ($terminator !== '{') {
            return null; // statement at-rule: @charset, @import, @layer a, b; @namespace
        }

        $media = $name === 'media' ? HtmlDocument::mediaCondition($prelude) : false;

        if ($media === false && ! in_array($name, self::UNWRAPPED_AT_RULES, true)) {
            $this->skipBlock($css, $position);

            return null; // @font-face, @keyframes, @page, @property, @starting-style, print media, …
        }

        if ($depth >= self::MAX_AT_RULE_DEPTH) {
            $this->truncated = true;
            $this->skipBlock($css, $position);

            return null;
        }

        // Rules behind a media feature, @supports or @container apply only sometimes (`conditional`); media
        // features and container queries also put a condition on them (see the class description).
        $inner = match (true) {
            $name === 'media' => $this->strongerCondition($condition, $media),
            $name === 'container' => $this->strongerCondition($condition, 'viewport'),
            default => $condition,
        };

        return [$conditional || ($name === 'media' ? str_contains($prelude, '(') : in_array($name, ['supports', 'container'], true)), $inner];
    }

    /**
     * Parse a style rule's block from $position (after its opening brace) through its closing brace. Its declarations
     * apply to $selectors (a prelude is split only when they are used); a nested style rule applies to nestedSelectors(),
     * the declarations in a nested group rule (@media, @supports, …) to $selectors under the group's condition, and
     * declarations after a nested block form a later rule, as in browsers. The entries of nested blocks ($nested) and of
     * declarations after a nested block count against MAX_NESTED_RULE_BYTES.
     *
     * @param  list<string>|string  $selectors
     * @param  list<array{selector: string, properties: array<string, array{value: string, important: bool}>, specificity: array{0: int, 1: int, 2: int}, order: int, sheet: int, conditional: bool, condition: 'preference'|'viewport'|null}>  $rules
     * @param  'preference'|'viewport'|null  $condition
     */
    private function parseRuleBody(string $css, int &$position, array|string $selectors, array &$rules, int $orderOffset, bool $conditional, ?string $condition, int $depth, bool $nested): void
    {
        $length = strlen($css);
        $stop = $position + strcspn($css, '{}"\'', $position);

        if ($stop >= $length || $css[$stop] === '}') { // declarations only
            $this->addRules(substr($css, $position, $stop - $position), $selectors, $rules, $orderOffset, $conditional, $condition, $nested);
            $position = min($stop + 1, $length);

            return;
        }

        $selectors = is_string($selectors) ? $this->selectorList($selectors) : $selectors;
        $segment = $position; // the declarations not added yet start here

        while (true) {
            $this->skipWhitespace($css, $position);
            $begin = $position;

            if (($css[$position] ?? '') === '@') {
                $group = $this->groupRule($css, $position, $conditional, $condition, $depth, true);

                if ($group === null) {
                    continue; // a statement at-rule (parseProperties() ignores it) or a skipped block
                }

                $this->addRules(substr($css, $segment, $begin - $segment), $selectors, $rules, $orderOffset, $conditional, $condition, $nested);
                $nested = true;

                if ($this->nestedRuleBytes >= self::MAX_NESTED_RULE_BYTES) {
                    $this->truncated = true;
                    $this->skipBlock($css, $position);
                } else {
                    $this->parseRuleBody($css, $position, $selectors, $rules, $orderOffset, $group[0], $group[1], $depth + 1, true);
                }

                $segment = $position;

                continue;
            }

            [$prelude, $terminator] = $this->readUntil($css, $position, ['{', ';', '}']);

            if ($terminator === ';') {
                continue;
            }

            if ($terminator !== '{') { // the closing brace or the end of the input
                $end = $terminator === '}' ? $position - 1 : $position;
                $this->addRules(substr($css, $segment, $end - $segment), $selectors, $rules, $orderOffset, $conditional, $condition, $nested);

                return;
            }

            $this->addRules(substr($css, $segment, $begin - $segment), $selectors, $rules, $orderOffset, $conditional, $condition, $nested);
            $nested = true;
            $inner = $depth < self::MAX_AT_RULE_DEPTH && $this->nestedRuleBytes < self::MAX_NESTED_RULE_BYTES ? $this->nestedSelectors($selectors, $prelude) : null;

            if ($inner === null) {
                $this->truncated = true;
                $this->skipBlock($css, $position);
            } else {
                $this->parseRuleBody($css, $position, $inner, $rules, $orderOffset, $conditional, $condition, $depth + 1, true);
            }

            $segment = $position;
        }
    }

    /**
     * Add a rule per selector for the declarations. Entries added for nesting ($nested) count against
     * MAX_NESTED_RULE_BYTES: the first block that does not fit fills the budget and is dropped (truncated).
     *
     * @param  list<string>|string  $selectors  a prelude is split only when there are declarations
     * @param  list<array{selector: string, properties: array<string, array{value: string, important: bool}>, specificity: array{0: int, 1: int, 2: int}, order: int, sheet: int, conditional: bool, condition: 'preference'|'viewport'|null}>  $rules
     * @param  'preference'|'viewport'|null  $condition
     */
    private function addRules(string $declarations, array|string $selectors, array &$rules, int $orderOffset, bool $conditional, ?string $condition, bool $nested): void
    {
        $properties = str_contains($declarations, ':') ? $this->parseProperties($declarations) : [];

        if ($properties === []) {
            return;
        }

        $selectors = is_string($selectors) ? $this->selectorList($selectors) : $selectors;

        if ($nested) {
            $bytes = $this->nestedRuleBytes;

            foreach ($selectors as $selector) {
                $bytes += strlen($selector) + self::NESTED_ENTRY_BYTES;

                if ($bytes > self::MAX_NESTED_RULE_BYTES) {
                    $this->truncated = true;
                    $this->nestedRuleBytes = self::MAX_NESTED_RULE_BYTES;

                    return;
                }
            }

            $this->nestedRuleBytes = $bytes;
        }

        foreach ($selectors as $selector) {
            $rules[] = [
                'selector' => $selector,
                'properties' => $properties,
                'specificity' => $this->calculateSpecificity($selector),
                'order' => $orderOffset + count($rules),
                'sheet' => 0,
                'conditional' => $conditional,
                'condition' => $condition,
            ];
        }
    }

    /**
     * The selectors of a nested style rule: each selector of its list with every `&` replaced by a parent selector, in
     * every combination; an `&` inside parentheses (an argument such as :not(&)) stands for the whole parent list
     * instead. A selector without `&`, or starting with a combinator, is relative to the parent (`& .b`, `& > .b`). Null
     * when they would be more than MAX_NESTED_SELECTOR_COMBINATIONS, one longer than MAX_NESTED_SELECTOR_BYTES, or their
     * bytes do not fit into MAX_NESTED_RULE_BYTES (which they count against; the budget is then full): all three are known
     * before any selector is built.
     *
     * @param  list<string>  $parents
     * @return list<string>|null
     */
    private function nestedSelectors(array $parents, string $prelude): ?array
    {
        $count = count($parents);

        if ($count > self::MAX_NESTED_SELECTOR_COMBINATIONS) {
            return null; // each selector of the list expands to at least one per parent
        }

        if ($count === 0) {
            return [];
        }

        $lengths = array_map('strlen', $parents);
        $longest = max($lengths);
        $listBytes = $count === 1 ? $lengths[0] : array_sum($lengths) + 2 * $count + 3; // ":is(a, b)"
        $combinations = $cost = 0;
        $inArgument = false;
        $lists = [];

        foreach ($this->selectorList($prelude, self::MAX_NESTED_SELECTOR_COMBINATIONS) as $selector) {
            [$parts, $arguments] = $this->nestingParts($selector);

            if ($arguments === [] || str_contains('>+~', $selector[0])) {
                $parts[0] = ' '.$parts[0];
                array_unshift($parts, '');
                array_unshift($arguments, false);
            }

            $expansions = 1;
            $bytes = strlen(implode('', $parts));

            foreach ($arguments as $argument) {
                $expansions *= $argument ? 1 : $count;
                $bytes += $argument ? $listBytes : $longest;

                if ($combinations + $expansions > self::MAX_NESTED_SELECTOR_COMBINATIONS) {
                    return null;
                }
            }

            if ($bytes > self::MAX_NESTED_SELECTOR_BYTES) {
                return null;
            }

            $combinations += $expansions;
            $cost += $expansions * $bytes;
            $inArgument = $inArgument || in_array(true, $arguments, true);
            $lists[] = [$parts, $arguments];
        }

        if ($this->nestedRuleBytes + $cost > self::MAX_NESTED_RULE_BYTES) {
            $this->nestedRuleBytes = self::MAX_NESTED_RULE_BYTES;

            return null;
        }

        $this->nestedRuleBytes += $cost;
        $list = $count > 1 && $inArgument ? [':is('.implode(', ', $parents).')'] : [$parents[0]];
        $selectors = [];

        foreach ($lists as [$parts, $arguments]) {
            $built = [array_shift($parts)];

            foreach ($parts as $index => $part) {
                $next = [];

                foreach ($built as $prefix) {
                    foreach ($arguments[$index] ? $list : $parents as $parent) {
                        $next[] = $prefix.$parent.$part;
                    }
                }

                $built = $next;
            }

            array_push($selectors, ...$built);
        }

        return $selectors;
    }

    /**
     * The text of a selector around each nesting selector `&` (outside strings, unescaped), and for each `&` whether it
     * is inside parentheses.
     *
     * @return array{0: non-empty-list<string>, 1: list<bool>}
     */
    private function nestingParts(string $selector): array
    {
        if (! str_contains($selector, '&')) {
            return [[$selector], []];
        }

        $parts = $arguments = [];
        $start = $depth = 0;
        $quote = null;
        $length = strlen($selector);

        for ($i = 0; $i < $length; $i++) {
            $char = $selector[$i];

            if ($char === '\\') {
                $i++;
            } elseif ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($char === '&') {
                $parts[] = substr($selector, $start, $i - $start);
                $arguments[] = $depth > 0;
                $start = $i + 1;
            }
        }

        $parts[] = substr($selector, $start);

        return [$parts, $arguments];
    }

    /**
     * The selectors of a prelude, whitespace collapsed; when the list is longer than $limit, only $limit + 1 of them.
     *
     * @return list<string>
     */
    private function selectorList(string $prelude, int $limit = PHP_INT_MAX): array
    {
        $selectors = [];

        foreach ($this->splitTopLevel($prelude, ',', false, $limit) as $selector) {
            $selector = trim(preg_replace('/\s+/', ' ', $selector) ?? $selector);

            if ($selector !== '') {
                $selectors[] = $selector;
            }
        }

        return $selectors;
    }

    /**
     * The stronger of two conditions: 'preference' over 'viewport' over none.
     *
     * @param  'preference'|'viewport'|null  $outer
     * @param  'preference'|'viewport'|null  $inner
     * @return 'preference'|'viewport'|null
     */
    private function strongerCondition(?string $outer, ?string $inner): ?string
    {
        return $outer === 'preference' || $inner === 'preference' ? 'preference' : ($outer ?? $inner);
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
     * resolveVariables() plus whether the result is approximate: a substituted name is also declared outside the root
     * rules (`.dark { --fg: … }`), so the element may see another value.
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
     * Custom properties declared on :root, html or :host by rules of the base cascade (inBaseCascade(); cascade
     * winner), and on <html style>. Names that other rules of the base cascade also declare are recorded as overridden.
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
            if (! $this->inBaseCascade($rule)) {
                continue;
            }

            $root = in_array(strtolower($rule['selector']), self::ROOT_SELECTORS, true);

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
     * MAX_VAR_REFERENCES, the cache budget).
     */
    public function customProperty(DOMElement $element, string $name): ?string
    {
        $value = $this->customPropertyValue($element, $this->nodeKey($element), $name, 1)[0];

        return is_string($value) ? $value : null;
    }

    /**
     * Substitute every var(--name[, fallback]) in a value with the element's custom properties (customProperty()). The
     * fallback is used when the property is undefined, `initial` or invalid at computed-value time (approximate for a
     * cycle). Approximate where a rule whose selector cannot be evaluated could win the element's cascade for a
     * substituted name, or the element inherits from such an element. [null, true] when a reference cannot be
     * resolved: undefined without a fallback, a cycle without a fallback, nesting deeper than 8 levels, more than
     * MAX_VAR_REFERENCES references in one value, a result longer than MAX_RESOLVED_LENGTH, or a full cache.
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
     * are undefined at once. Names every element may declare are looked up from the element itself, other names from
     * the nearest owner (an element whose own rules, maybe rules or style attribute declare custom properties) upwards,
     * so elements that merely inherit add no cache entries. The result is cached at every element visited, keyed by
     * compact ids; a lookup that would grow the cache beyond MAX_CACHED_CUSTOM_PROPERTIES entries or
     * MAX_CACHED_CUSTOM_PROPERTY_BYTES is unresolvable.
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
        $id = $universal ? $this->elementId($element, $key) : $this->nearestOwner($element, $key);
        $suffix = ':'.$depth.':'.$this->nameId($name);
        $inheriting = []; // cache slots of the visited elements that take the value of the next one, with their own uncertainty
        $result = null;

        while ($id !== 0) {
            [$node, $nodeKey] = $this->elements[$id];
            $slot = 'i'.$id.$suffix;

            if (array_key_exists($slot, $this->customPropertyCache)) {
                $result = [$this->customPropertyCache[$slot], isset($this->approximateProperties[$slot])];

                break;
            }

            [$raw, $maybe] = $this->ownCustomProperty($node, $nodeKey, $name);
            $keyword = $raw === null ? null : strtolower(trim($raw));

            if ($raw !== null && ! in_array($keyword, self::INHERITING_KEYWORDS, true)) {
                [$value, $nested] = $keyword === 'initial' ? [null, false] : $this->declaredCustomProperty($node, $nodeKey, $id, $name, $raw, $depth);
                $result = [$value, $nested || $maybe];

                break;
            }

            if ($this->cacheIsFull()) {
                return [false, true];
            }

            $inheriting[] = [$slot, $maybe || $keyword === 'revert-layer'];
            $id = $universal ? $this->parentId($id) : $this->nextOwner($id);
        }

        [$value, $approximate] = $result ?? $this->rootCustomProperty($name, $depth);

        for ($i = count($inheriting) - 1; $i >= 0; $i--) {
            [$slot, $uncertain] = $inheriting[$i];
            $approximate = $approximate || $uncertain;

            if (! $this->remember($slot, $value, $approximate)) {
                return [false, true];
            }
        }

        return [$value, $approximate];
    }

    /**
     * The element's own cascaded declaration of $name among its matching rules (as in declarationsFor()) and its style
     * attribute, and whether a maybe rule covering the element declares $name with more weight.
     *
     * @return array{0: ?string, 1: bool}
     */
    private function ownCustomProperty(DOMElement $element, string $key, string $name): array
    {
        $declarers = $this->declarers[$name] ?? [];
        $value = $weight = $maybe = null;
        $definite = [
            ...$this->declaring($this->index[$key] ?? [], $declarers),
            ...$this->declaring($this->customIndex[$key] ?? [], $declarers),
            ...$this->universalDeclarers[$name] ?? [],
        ];

        foreach ($definite as $position) {
            $candidate = $this->declarationWeight($position, $name);

            if ($candidate !== null && ($weight === null || $candidate > $weight)) {
                [$value, $weight] = [$this->rules[$position]['properties'][$name]['value'], $candidate];
            }
        }

        $inline = $this->inlineCustomProperties[$key][$name] ?? null;

        if ($inline !== null && ($weight === null || [$inline['important'] ? 1 : 0, 1, 0, 0, 0, PHP_INT_MAX] > $weight)) {
            [$value, $weight] = [$inline['value'], [$inline['important'] ? 1 : 0, 1, 0, 0, 0, PHP_INT_MAX]];
        }

        foreach ([...$this->declaring($this->maybeIndex[$key] ?? [], $declarers), ...$this->universalMaybe[$name] ?? []] as $position) {
            $candidate = $this->declarationWeight($position, $name);
            $maybe = $candidate !== null && ($maybe === null || $candidate > $maybe) ? $candidate : $maybe;
        }

        return [$value, $maybe !== null && ($weight === null || $maybe > $weight)];
    }

    /** @return list<int>|null cascade weight of the rule's declaration of $name, null when it does not declare it */
    private function declarationWeight(int $position, string $name): ?array
    {
        $rule = $this->rules[$position];
        $declaration = $rule['properties'][$name] ?? null;

        return $declaration === null ? null : [$declaration['important'] ? 1 : 0, 0, ...$rule['specificity'], $rule['order']];
    }

    /**
     * The element's rule positions that may declare the name: binary search of the declarers when they are few, else all
     * positions (the caller checks each declaration). Both lists are ascending.
     *
     * @param  list<int>  $positions
     * @param  list<int>  $declarers
     * @return list<int>
     */
    private function declaring(array $positions, array $declarers): array
    {
        if ($positions === [] || $declarers === []) {
            return [];
        }

        if (count($declarers) * 8 >= count($positions)) {
            return $positions;
        }

        $found = [];

        foreach ($declarers as $position) {
            for ($low = 0, $high = count($positions) - 1; $low <= $high;) {
                $middle = ($low + $high) >> 1;

                if ($positions[$middle] === $position) {
                    $found[] = $position;

                    break;
                }

                [$low, $high] = $positions[$middle] < $position ? [$middle + 1, $high] : [$low, $middle - 1];
            }
        }

        return $found;
    }

    /**
     * A declared custom property value with its var() references substituted in the declaring element's context;
     * $id names the declaring element in the cache and the cycle guard (-1: the root custom properties).
     *
     * @return array{0: string|null|false, 1: bool}
     */
    private function declaredCustomProperty(DOMElement $element, string $key, int $id, string $name, string $raw, int $depth): array
    {
        if (stripos($raw, 'var(') === false) {
            return [$raw, false];
        }

        $nameId = $this->nameId($name);
        $slot = 'd'.$id.':'.$depth.':'.$nameId;

        if (array_key_exists($slot, $this->customPropertyCache)) {
            return [$this->customPropertyCache[$slot], isset($this->approximateProperties[$slot])];
        }

        $guard = $id.':'.$nameId;

        if (isset($this->computingProperties[$guard])) {
            return [null, true]; // a cycle: invalid at computed-value time
        }

        if ($this->cacheIsFull()) {
            return [false, true];
        }

        $this->computingProperties[$guard] = true;
        [$value, $approximate] = $this->substituteIn($element, $key, $raw, $depth);
        unset($this->computingProperties[$guard]);

        return $this->remember($slot, $value, $approximate) ? [$value, $approximate] : [false, true];
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

        return $this->declaredCustomProperty($root, $rootKey, -1, $name, $raw, $depth);
    }

    /** Store a cache entry within MAX_CACHED_CUSTOM_PROPERTIES and MAX_CACHED_CUSTOM_PROPERTY_BYTES; false when it does not fit. */
    private function remember(string $slot, string|null|false $value, bool $approximate): bool
    {
        $length = is_string($value) ? strlen($value) : 0;
        // A string longer than 2048 bytes takes whole 4 KB allocator pages, its 24-byte header and terminator included.
        $bytes = strlen($slot) + ($length > 2048 ? (int) ceil(($length + 25) / 4096) * 4096 : $length) + self::CACHE_ENTRY_BYTES;

        if (count($this->customPropertyCache) >= self::MAX_CACHED_CUSTOM_PROPERTIES || $this->cachedBytes + $bytes > self::MAX_CACHED_CUSTOM_PROPERTY_BYTES) {
            return false;
        }

        $this->customPropertyCache[$slot] = $value;
        $this->cachedBytes += $bytes;

        if ($approximate) {
            $this->approximateProperties[$slot] = true;
        }

        return true;
    }

    private function cacheIsFull(): bool
    {
        return count($this->customPropertyCache) >= self::MAX_CACHED_CUSTOM_PROPERTIES || $this->cachedBytes >= self::MAX_CACHED_CUSTOM_PROPERTY_BYTES;
    }

    /** Collect the declared names and their rules, the style-attribute custom properties and the names every element may declare (once per parse). */
    private function prepareCustomProperties(): void
    {
        if ($this->declaredNames !== null) {
            return;
        }

        $this->buildIndex();
        $names = [];

        foreach ($this->rules as $position => $rule) {
            foreach ($this->inBaseCascade($rule) ? $rule['properties'] : [] as $name => $declaration) {
                if (str_starts_with((string) $name, '--')) {
                    $names[$name] = true;
                    $this->declarers[$name][] = $position;
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
            foreach ($this->rules[$position]['properties'] as $name => $declaration) {
                if (str_starts_with((string) $name, '--')) {
                    $this->keepHeavier($winners, $name, $declaration, $this->declarationWeight($position, $name));
                }
            }
        }

        foreach ($winners as $name => $winner) {
            if (! in_array(strtolower(trim($winner['declaration']['value'])), self::INHERITING_KEYWORDS, true)) {
                $this->universalNames[$name] = true; // a universal inherit is the same as no declaration
            }
        }

        foreach (['universalDeclarers' => $this->universalCustomRules, 'universalMaybe' => $this->universalMaybeRules] as $map => $positions) {
            foreach ($positions as $position) {
                foreach ($this->rules[$position]['properties'] as $name => $declaration) {
                    if (str_starts_with((string) $name, '--')) {
                        $this->{$map}[$name][] = $position;
                    }
                }
            }
        }

        $this->universalNames += array_fill_keys(array_keys($this->universalMaybe), true);
        $this->declaredNames = $names;
    }

    /** Compact id of an element the lookups visit, assigned on first use. */
    private function elementId(DOMElement $element, string $key): int
    {
        if (! isset($this->elementIds[$key])) {
            $this->elementIds[$key] = count($this->elements) + 1;
            $this->elements[$this->elementIds[$key]] = [$element, $key, null, null];
        }

        return $this->elementIds[$key];
    }

    private function nameId(string $name): int
    {
        return $this->nameIds[$name] ??= count($this->nameIds) + 1;
    }

    /** Id of the nearest custom-property owner, the element itself or an ancestor; 0 for none. One entry per element. */
    private function nearestOwner(DOMElement $element, string $key): int
    {
        $pending = [];
        $node = $element;
        $nodeKey = $key;

        while (true) {
            if (isset($this->ownerOf[$nodeKey])) {
                $owner = $this->ownerOf[$nodeKey];

                break;
            }

            $pending[] = $nodeKey;

            if (isset($this->customOwners[$nodeKey])) {
                $owner = $this->elementId($node, $nodeKey);

                break;
            }

            $parent = $node->parentNode;

            if (! $parent instanceof DOMElement) {
                $owner = 0;

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

    /** The owner above an owner; 0 for none. */
    private function nextOwner(int $id): int
    {
        if ($this->elements[$id][2] === null) {
            $parent = $this->elements[$id][0]->parentNode;
            $this->elements[$id][2] = $parent instanceof DOMElement ? $this->nearestOwner($parent, $this->nodeKey($parent)) : 0;
        }

        return $this->elements[$id][2];
    }

    /** The parent of a visited element; 0 for none. */
    private function parentId(int $id): int
    {
        if ($this->elements[$id][3] === null) {
            $parent = $this->elements[$id][0]->parentNode;
            $this->elements[$id][3] = $parent instanceof DOMElement ? $this->elementId($parent, $this->nodeKey($parent)) : 0;
        }

        return $this->elements[$id][3];
    }

    /**
     * Whether a rule takes part in the base cascade, which declarationsFor(), the custom properties (per element and the
     * root map) and the index budgets share: every rule except those behind a user-preference media query (dark mode,
     * forced colours, …: another theme, not the default one). Rules behind other media features, behind @supports and
     * behind @container count as if their condition held.
     *
     * @param  array{condition?: 'preference'|'viewport'|null}  $rule
     */
    private function inBaseCascade(array $rule): bool
    {
        return ($rule['condition'] ?? null) !== 'preference';
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
     * Index the matches of a custom-property-only or maybe rule within MAX_CUSTOM_INDEX_ENTRIES; a rule that does not fit
     * is skipped, marks the index truncated and fills it, so later rules are skipped before any matching. Node paths are
     * computed once per element ($keys keeps the element alive, so its object id stays unique).
     *
     * @param  'customIndex'|'maybeIndex'  $map
     * @param  list<DOMElement>  $elements
     * @param  array<int, array{0: DOMElement, 1: string}>  $keys
     */
    private function indexCustomMatches(string $map, int $position, array $elements, array &$keys): void
    {
        if ($this->customIndexEntries + count($elements) > self::MAX_CUSTOM_INDEX_ENTRIES) {
            $this->truncated = true;
            $this->customIndexEntries = self::MAX_CUSTOM_INDEX_ENTRIES; // full: later custom and maybe rules are skipped unmatched

            return;
        }

        $this->customIndexEntries += count($elements);

        foreach ($elements as $element) {
            $key = ($keys[spl_object_id($element)] ??= [$element, $this->nodeKey($element)])[1];
            $this->{$map}[$key][] = $position;
            $this->customOwners[$key] = true;
        }
    }

    /**
     * Index a rule with custom properties whose selector cannot be evaluated as a "maybe" rule: it covers the elements
     * matched by the supported simple selectors of its rightmost compound, looking into :is() and :where() arguments
     * (every element when nothing is left). Left out: pseudo-element rules, which never style the element itself;
     * selectors with a user-action state outside parentheses (:hover, :focus, …), which never match the base state that
     * is measured; and :host, which resolves through the root custom properties.
     *
     * @param  array{selector: string, properties: array<string, array{value: string, important: bool}>}  $rule
     * @param  array{class: array<string, list<DOMElement>>, id: array<string, list<DOMElement>>}|null  $tokens
     * @param  array<int, array{0: DOMElement, 1: string}>  $keys
     */
    private function indexMaybeRule(int $position, array $rule, ?array &$tokens, array &$keys): void
    {
        $selector = $rule['selector'];

        if (in_array(strtolower($selector), self::ROOT_SELECTORS, true)
            || str_contains($selector, '::')
            || preg_match('/:(?:before|after|first-line|first-letter)(?![\w-])/i', $selector) === 1
            || $this->hasUserActionState($selector)) {
            return;
        }

        if ($this->customIndexEntries >= self::MAX_CUSTOM_INDEX_ENTRIES) {
            $this->truncated = true;

            return;
        }

        $elements = [];

        foreach ($this->reducedRightmostCompound($selector) as $compound) {
            $expression = $compound === '' ? null : $this->simpleSelectorToXpath($compound);

            if ($expression === null || $expression === '//*') {
                $this->universalMaybeRules[] = $position;

                return;
            }

            foreach ($this->elementsMatching($compound, $expression, $tokens) as $element) {
                $elements[spl_object_id($element)] = $element;
            }
        }

        $this->indexCustomMatches('maybeIndex', $position, array_values($elements), $keys);
    }

    /** Whether the selector needs a user-action state outside parentheses and brackets (it never matches at rest). */
    private function hasUserActionState(string $selector): bool
    {
        $depth = 0;
        $length = strlen($selector);

        for ($i = 0; $i < $length; $i++) {
            $char = $selector[$i];

            if ($char === '\\') {
                $i++;
            } elseif ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth = max(0, $depth - 1);
            } elseif ($char === ':' && $depth === 0 && preg_match(self::USER_ACTION_STATE, $selector, $m, 0, $i) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The rightmost compound of a selector reduced to the simple selectors the parser supports (type, class, id,
     * attribute, :root, :first-child, :last-child, :link, :not(compound)), as alternatives: :is() and :where()
     * contribute the reduced rightmost compound of each argument. [''] (every element) when an alternative is empty,
     * the compound is longer than MAX_REDUCED_COMPOUND_BYTES, the nesting is deeper than 4 levels, a list has more than
     * 16 arguments or there would be more than 16 alternatives (checked while they are built); [] when none can match at
     * rest.
     *
     * @return list<string>
     */
    private function reducedRightmostCompound(string $selector, int $nesting = 0): array
    {
        $start = 0;
        $depth = 0;
        $quote = null;
        $length = strlen($selector);

        for ($i = 0; $i < $length; $i++) {
            $char = $selector[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '\\') {
                $i++;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && ($char === '>' || $char === '+' || $char === '~' || ctype_space($char))) {
                $start = $i + 1;
            }
        }

        $compound = substr($selector, $start);
        $length = strlen($compound);

        if ($length > self::MAX_REDUCED_COMPOUND_BYTES) {
            return [''];
        }

        $type = preg_match('/[a-zA-Z][a-zA-Z0-9-]*|\*/A', $compound, $m) === 1 ? $m[0] : '';
        $position = strlen($type);
        $alternatives = [[$type === '*' ? '' : strtolower($type), '']]; // [type, simple selectors]

        while ($position < $length) {
            $begin = $position;
            $char = $compound[$position];
            $arguments = null;

            if (($char === '.' || $char === '#') && preg_match('/'.self::IDENTIFIER.'/A', $compound, $m, 0, $position + 1) === 1) {
                $position += 1 + strlen($m[0]);
            } elseif ($char === '[' && ($end = $this->closingBracket($compound, $position)) !== null) {
                $position = $end + 1;
            } elseif ($char === ':' && preg_match('/::?(-?[\w-]+)/A', $compound, $m, 0, $position) === 1) {
                $position += strlen($m[0]);

                if (($compound[$position] ?? '') === '(') {
                    $end = $this->closingParenthesis($compound, $position);

                    if ($end === null) {
                        break;
                    }

                    if (in_array(strtolower($m[1]), self::MATCHES_ANY, true)) {
                        $arguments = $this->splitTopLevel(substr($compound, $position + 1, $end - $position - 1), ',');
                    }

                    $position = $end + 1;
                }
            } else {
                break;
            }

            if ($arguments === null) {
                $token = substr($compound, $begin, $position - $begin);

                if ($this->simpleSelectorToXpath('*'.$token) !== null) { // supported: every alternative keeps it
                    foreach (array_keys($alternatives) as $index) {
                        $alternatives[$index][1] .= $token;
                    }
                }

                continue;
            }

            if ($nesting >= 4 || count($arguments) > 16) {
                return [''];
            }

            $combined = []; // deduplicated while it is built, so it never holds more than 17 alternatives

            foreach ($arguments as $argument) {
                if ($this->hasUserActionState($argument)) {
                    continue; // this argument never matches at rest
                }

                foreach ($this->reducedRightmostCompound($argument, $nesting + 1) as $reduced) {
                    preg_match('/[a-zA-Z][a-zA-Z0-9-]*/A', $reduced, $m);
                    $reducedType = strtolower($m[0] ?? '');
                    $rest = substr($reduced, strlen($m[0] ?? ''));

                    foreach ($alternatives as [$alternativeType, $simple]) {
                        if ($alternativeType === '' || $reducedType === '' || $alternativeType === $reducedType) {
                            $type = $alternativeType === '' ? $reducedType : $alternativeType;
                            $simple .= $rest;
                            $combined[$type."\0".$simple] = [$type, $simple];

                            if (count($combined) > 16) {
                                return [''];
                            }
                        }
                    }
                }
            }

            $alternatives = array_values($combined);
        }

        $compounds = array_map(fn (array $alternative): string => $alternative[0].$alternative[1], $alternatives);

        return in_array('', $compounds, true) ? [''] : $compounds;
    }

    /** Index of the bracket closing the one at $open (quotes respected), or null. */
    private function closingBracket(string $value, int $open): ?int
    {
        $quote = null;
        $length = strlen($value);

        for ($i = $open + 1; $i < $length; $i++) {
            $char = $value[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === ']') {
                return $i;
            }
        }

        return null;
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

    /** @return list<array{selector: string, properties: array<string, array{value: string, important: bool}>, specificity: array{0: int, 1: int, 2: int}, order: int, sheet: int, conditional: bool, condition: 'preference'|'viewport'|null}> */
    private function matchingRules(DOMElement $element, ?string $key = null): array
    {
        $this->buildIndex();

        return array_map(fn (int $position) => $this->rules[$position], $this->index[$key ?? $this->nodeKey($element)] ?? []);
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

    /**
     * The backdrop of the element's text and its uncertainty (see resolveColors()). The element's own paint is the first
     * stop of its first gradient over its own background colour. When that paint is opaque the element supplies the
     * background used and nothing behind it counts; otherwise it is composited over the parent's backdrop (white at the
     * root) and the parent's uncertainty carries over. Also returns the element's own background colour (null: none)
     * with its uncertainty and its own image layers, which inherit takes.
     *
     * @return array{0: Color, 1: list<string>, 2: ?Color, 3: list<string>, 4: array{0: bool, 1: ?Color}}
     */
    private function background(DOMElement $element, string $key): array
    {
        if (isset($this->backgroundCache[$key])) {
            return $this->backgroundCache[$key];
        }

        $node = $element->parentNode;
        [$parent, $parentUncertainty, $parentColor, $parentColorUncertainty, $parentImage] = $node instanceof DOMElement
            ? $this->background($node, $this->parentKey($key, $node))
            : [new Color(255, 255, 255), [], null, [], [false, null]];

        [$color, $colorUncertainty] = $this->backgroundColor($element, $key, $parentColor, $parentColorUncertainty);
        [$image, $imageUncertainty] = $this->backgroundImage($element, $key, $parentImage);
        $backdrop = $image[0] || $this->hasCoveringSibling($element, $key) ? ['backdrop'] : [];
        $uncertainty = $this->uncertainty($backdrop, $colorUncertainty, $imageUncertainty);
        $paint = $color !== null && $color->a > 0 ? $color : null;

        if ($image[1] !== null && $image[1]->a > 0) {
            $paint = $paint === null ? $image[1] : $image[1]->over($paint);
        }

        // Paint inside an opacity group is composited with the group (not modelled): only the text alpha is scaled (R33).
        if ($paint !== null && $this->opacityOf($element, $key)[0] < 1) {
            $uncertainty = $this->uncertainty($uncertainty, ['colour']);
        }

        if ($paint === null || $paint->a < self::OPAQUE_ALPHA) {
            $paint = $paint === null ? $parent : $paint->over($parent);
            $uncertainty = $this->uncertainty($uncertainty, $parentUncertainty);
        }

        return $this->backgroundCache[$key] = [$paint, $uncertainty, $color, $colorUncertainty, $image];
    }

    /**
     * The element's own background colour: the winning `background-color`, or the colour in the final layer of a winning
     * `background` shorthand, var() substituted. inherit takes the parent's own colour, initial and unset none,
     * currentcolor the text colour; an unresolvable substitution is unknown.
     *
     * @param  list<string>  $parentUncertainty
     * @return array{0: ?Color, 1: list<string>} the colour (null: none or unknown) and its uncertainty
     */
    private function backgroundColor(DOMElement $element, string $key, ?Color $parentColor, array $parentUncertainty): array
    {
        $declared = $this->winningDeclaration($element, $key, 'background-color', 'background');

        if ($declared === null) {
            return [null, []];
        }

        [$value, $approximate] = $this->substituteValue($element, $key, $declared[0]);

        if ($value === null) {
            return [null, ['colour']];
        }

        $substitution = $approximate ? ['colour'] : [];
        [$color, $unparsed, $keyword] = $this->layers($value, $declared[1] ? 'final' : 'colour');

        // CSS-wide keywords and currentcolor.
        switch ($keyword) {
            case 'inherit':
                return [$parentColor, $this->uncertainty($parentUncertainty, $substitution)];
            case 'initial':
            case 'unset':
                return [null, $substitution];
            case 'currentcolor':
                [$foreground, $foregroundUncertainty] = $this->foreground($element, $key);

                return [$foreground, $this->uncertainty($foregroundUncertainty, $substitution)];
        }

        return [$color, $this->uncertainty($substitution, $unparsed ? ['colour'] : [])];
    }

    /**
     * The element's own image layers: whether the winning `background-image` or `background` paints an image
     * (IMAGE_FUNCTION) and the first stop of its first gradient (null: none), var() substituted; when the substitution
     * is unresolvable, the functions of the declared value still count. inherit takes the parent's layers.
     *
     * @param  array{0: bool, 1: ?Color}  $parentImage
     * @return array{0: array{0: bool, 1: ?Color}, 1: list<string>} the layers and their uncertainty
     */
    private function backgroundImage(DOMElement $element, string $key, array $parentImage): array
    {
        $declared = $this->winningDeclaration($element, $key, 'background-image', 'background');

        if ($declared === null) {
            return [[false, null], []];
        }

        [$value, $approximate] = $this->substituteValue($element, $key, $declared[0]);
        $uncertainty = $approximate ? ['colour'] : [];

        if ($value !== null && strlen($value) <= 32 && strtolower(trim($value)) === 'inherit') {
            return [$parentImage, $uncertainty];
        }

        return [$this->layers($value ?? $declared[0], 'image'), $uncertainty];
    }

    /**
     * The analysis of a background value, memoised by the value: 'image' is imageLayers(); 'colour' is the colour of a
     * `background-color` value and 'final' the colour in the final layer of a `background` shorthand (image functions
     * left out), each with whether Color only approximates it and the text lowercased when it may be a keyword (else '').
     * Elements that share a rule share its value, so a value is analysed about once. The memo holds at most
     * MAX_LAYER_CACHE_BYTES and starts over when full; a longer value is not kept.
     *
     * @param  'image'|'colour'|'final'  $part
     * @return array{0: mixed, 1: mixed, 2?: string}
     */
    private function layers(string $value, string $part): array
    {
        if (isset($this->layerCache[$value][$part])) {
            return $this->layerCache[$value][$part];
        }

        $text = $part === 'final' ? $this->withoutImages($this->finalLayer($value)) : $value;
        $result = $part === 'image' ? $this->imageLayers($value) : [...Color::fromBackground($text), strlen($text) > 64 ? '' : strtolower(trim($text))];

        if (! isset($this->layerCache[$value])) {
            $bytes = strlen($value) + self::LAYER_ENTRY_BYTES;

            if ($bytes > self::MAX_LAYER_CACHE_BYTES) {
                return $result;
            }

            if ($this->layerCacheBytes + $bytes > self::MAX_LAYER_CACHE_BYTES) {
                $this->layerCache = [];
                $this->layerCacheBytes = 0;
            }

            $this->layerCacheBytes += $bytes;
        }

        $this->layerCache[$value][$part] = $result;

        return $result;
    }

    /**
     * Whether a background value paints an image (IMAGE_FUNCTION), and the first stop of its first gradient (null: none,
     * or a gradient longer than MAX_RESOLVED_LENGTH). Two linear regex scans and one bounded parenthesis scan.
     *
     * @return array{0: bool, 1: ?Color}
     */
    private function imageLayers(string $value): array
    {
        if (preg_match(self::IMAGE_FUNCTION, $value) !== 1) {
            return [false, null];
        }

        if (preg_match(self::GRADIENT_FUNCTION, $value, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return [true, null];
        }

        $call = substr($value, $m[0][1], self::MAX_RESOLVED_LENGTH);
        $end = $this->closingParenthesis($call, strlen($m[0][0]) - 1);

        return [true, $end === null ? null : Color::fromBackground(substr($call, 0, $end + 1))[0]];
    }

    /** The final layer of a background value, the only one that may carry a colour: the text after its last comma outside parentheses and quotes (one backward scan). */
    private function finalLayer(string $value): string
    {
        if (! str_contains($value, ',')) {
            return $value;
        }

        $depth = 0;
        $quote = null;

        for ($i = strlen($value) - 1; $i >= 0; $i--) {
            $char = $value[$i];

            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === ')') {
                $depth++;
            } elseif ($char === '(') {
                $depth = max(0, $depth - 1);
            } elseif ($char === ',' && $depth === 0) {
                return substr($value, $i + 1);
            }
        }

        return $value;
    }

    /** The value with every image function call (IMAGE_FUNCTION through its closing parenthesis) replaced by a space; linear. */
    private function withoutImages(string $value): string
    {
        $kept = '';
        $position = 0;

        while (preg_match(self::IMAGE_FUNCTION, $value, $m, PREG_OFFSET_CAPTURE, $position) === 1) {
            $start = $m[0][1];
            $end = $this->closingParenthesis($value, $start + strlen($m[0][0]) - 1);
            $kept .= substr($value, $position, $start - $position).' ';

            if ($end === null) {
                return $kept;
            }

            $position = $end + 1;
        }

        return $kept.substr($value, $position);
    }

    /**
     * Whether a previous element sibling of the element may cover its backdrop (see coveringSiblingIndex()). The parent's
     * children are looked up once; the positional step then stops after that index.
     */
    private function hasCoveringSibling(DOMElement $element, string $key): bool
    {
        $parent = $element->parentNode;

        if (! $parent instanceof DOMElement || $this->document === null || ! $parent->ownerDocument?->isSameNode($this->document->dom())) {
            return false;
        }

        $parentKey = $this->parentKey($key, $parent);

        if (! array_key_exists($parentKey, $this->coveringMedia)) {
            $this->coveringMedia[$parentKey] = $this->coveringSiblingIndex($parent, $parentKey);
        }

        $index = $this->coveringMedia[$parentKey];

        return $index !== null && $this->document->xpath()->evaluate('boolean(preceding-sibling::*['.($index + 1).'])', $element) === true;
    }

    /**
     * Element-child index of the parent's first child that covers (covers()), in one pass over its children; null when
     * none does. The candidates are the media children (COVERING_MEDIA) and the children an indexed `position` rule or
     * their style attribute may position; after MAX_EXAMINED_SIBLINGS candidates the next one counts as covering.
     */
    private function coveringSiblingIndex(DOMElement $parent, string $parentKey): ?int
    {
        $examined = 0;

        foreach ($this->elementChildren($parent, $parentKey) as $index => [$child, $key]) {
            $media = in_array(Element::tag($child), self::COVERING_MEDIA, true);

            if (! $media && ! isset($this->positioned[$key]) && stripos($child->getAttribute('style'), 'position') === false) {
                continue;
            }

            if (++$examined > self::MAX_EXAMINED_SIBLINGS || $this->covers($child, $key, $media)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The element children of an element with their node keys, in order. The names are counted in a first pass, so each
     * key is the parent's node path plus the child's name, with `[n]` when the name occurs more than once: the format of
     * getNodePath(), at O(children) for all of them. Without a node path for the parent, or with namespaced children,
     * every key is nodeKey().
     *
     * @return \Generator<int, array{0: DOMElement, 1: string}>
     */
    private function elementChildren(DOMElement $parent, string $parentKey): \Generator
    {
        $derived = $parentKey !== '' && $parentKey[0] === '/';
        $names = [];

        for ($node = $parent->firstChild; $node !== null; $node = $node->nextSibling) {
            if ($node instanceof DOMElement) {
                $derived = $derived && $node->namespaceURI === null;
                $names[$node->nodeName] = ($names[$node->nodeName] ?? 0) + 1;
            }
        }

        $seen = [];
        $index = 0;

        for ($node = $parent->firstChild; $node !== null; $node = $node->nextSibling) {
            if ($node instanceof DOMElement) {
                $name = $node->nodeName;
                $seen[$name] = ($seen[$name] ?? 0) + 1;

                yield $index++ => [$node, $derived ? $parentKey.'/'.$name.($names[$name] > 1 ? '['.$seen[$name].']' : '') : $this->nodeKey($node)];
            }
        }
    }

    /**
     * Whether a previous sibling may cover the backdrop: it is rendered (its own display is not none and its visibility
     * not hidden or collapse; hiding viewport declarations never win, as in hidesElement()), positioned out of flow
     * (isOutOfFlow()), and a media element or an image layer (isImageLayer(), R34/R35).
     */
    private function covers(DOMElement $sibling, string $key, bool $media): bool
    {
        $visibility = $this->revealingVisibility($sibling, $key);

        if (strtolower(trim($visibility['display']['value'] ?? '')) === 'none'
            || in_array(strtolower(trim($visibility['visibility']['value'] ?? '')), ['hidden', 'collapse'], true)
            || ! $this->isOutOfFlow($sibling, $key)) {
            return false;
        }

        return $media || $this->isImageLayer($sibling);
    }

    /**
     * Whether an element is a decorative image layer (R34, R35): it contains an img, picture, video or canvas and no text
     * other than whitespace (script, style and template content is not looked into), so a positioned header or sidebar
     * with a logo is not one. At most MAX_MEDIA_DESCENDANTS nodes are searched; a larger subtree without text among
     * them counts (never silent).
     */
    private function isImageLayer(DOMElement $element): bool
    {
        $budget = self::MAX_MEDIA_DESCENDANTS;
        $media = false;
        $node = $element->firstChild;

        while ($node !== null) {
            if (--$budget < 0) {
                return true;
            }

            if ($node instanceof DOMText) {
                $text = $node->data;

                if (strspn($text, " \t\n\r\f") < strlen($text)) {
                    return false;
                }
            } elseif ($node instanceof DOMElement) {
                $tag = Element::tag($node);
                $media = $media || in_array($tag, self::CONTAINED_MEDIA, true);

                if ($node->firstChild !== null && ! in_array($tag, self::UNRENDERED_CONTENT, true)) {
                    $node = $node->firstChild;

                    continue;
                }
            }

            while ($node->nextSibling === null) {
                $node = $node->parentNode;

                if ($node === null || $node->isSameNode($element)) {
                    return $media;
                }
            }

            $node = $node->nextSibling;
        }

        return $media;
    }

    /** Whether the element's cascaded position is absolute or fixed, var() substituted; an unresolvable value counts. */
    private function isOutOfFlow(DOMElement $element, string $key): bool
    {
        $declared = $this->declarations($element, $key)['position']['value'] ?? null;

        if ($declared === null) {
            return false;
        }

        $value = $this->substituteValue($element, $key, $declared)[0];

        return $value === null || (strlen($value) <= 64 && in_array(strtolower(trim($value)), ['absolute', 'fixed'], true));
    }

    /**
     * The element's text colour: its winning `color` declaration, var() substituted, else the parent's (black at the
     * root), with its uncertainty: `colour` for an unresolvable or approximate substitution or a colour Color cannot
     * parse exactly (the parent's colour is then the estimate). Only an inherited colour carries the parent's uncertainty.
     *
     * @return array{0: Color, 1: list<string>}
     */
    private function foreground(DOMElement $element, string $key): array
    {
        if (isset($this->foregroundCache[$key])) {
            return $this->foregroundCache[$key];
        }

        $node = $element->parentNode;
        [$parent, $inherited] = $node instanceof DOMElement
            ? $this->foreground($node, $this->parentKey($key, $node))
            : [new Color(0, 0, 0), []];

        $value = $this->declarations($element, $key)['color']['value'] ?? null;

        if ($value === null) {
            return $this->foregroundCache[$key] = [$parent, $inherited];
        }

        [$value, $approximate] = $this->substituteValue($element, $key, $value);
        $substitution = $approximate ? ['colour'] : [];
        $keyword = $value === null ? null : strtolower(trim($value));

        // inherit, unset (color inherits) and currentcolor (on `color` itself the inherited colour) are the parent's colour.
        if ($keyword === 'inherit' || $keyword === 'unset' || $keyword === 'currentcolor') {
            return $this->foregroundCache[$key] = [$parent, $this->uncertainty($inherited, $substitution)];
        }

        if ($keyword === 'initial') {
            return $this->foregroundCache[$key] = [new Color(0, 0, 0), $substitution];
        }

        $own = $value === null || Color::isUnresolvable($value) ? null : Color::parse($value);

        if ($own === null) {
            return $this->foregroundCache[$key] = [$parent, $this->uncertainty($inherited, ['colour'])];
        }

        return $this->foregroundCache[$key] = [$own, $substitution];
    }

    /**
     * The value of whichever of a longhand and its shorthand wins the cascade for the element (the longhand on a tie),
     * and whether it is the shorthand; null when neither is declared.
     *
     * @return array{0: string, 1: bool}|null
     */
    private function winningDeclaration(DOMElement $element, string $key, string $longhand, string $shorthand): ?array
    {
        $declarations = $this->declarations($element, $key);
        $long = $declarations[$longhand] ?? null;
        $short = $declarations[$shorthand] ?? null;

        if ($short === null) {
            return $long === null ? null : [$long['value'], false];
        }

        $weights = $this->weightCache[$key] ?? [];

        return $long !== null && $weights[$longhand] >= $weights[$shorthand] ? [$long['value'], false] : [$short['value'], true];
    }

    /**
     * Size and weight of a `font` shorthand value (lowercased): a CSS-wide keyword stands for both; the weight is
     * `normal` unless the value names one. Null for system fonts and values that are not a valid shorthand.
     *
     * @return array{size: string, weight: string}|null
     */
    private function fontShorthand(string $value): ?array
    {
        $value = strtolower(trim($value));

        if (strlen($value) > self::MAX_RESOLVED_LENGTH || in_array($value, self::SYSTEM_FONTS, true)) {
            return null;
        }

        if (in_array($value, ['inherit', 'initial', 'unset', 'revert', 'revert-layer'], true)) {
            return ['size' => $value, 'weight' => $value];
        }

        $tokens = $this->splitTopLevel(preg_replace('/\s+/', ' ', $value) ?? $value, ' ');
        $weight = 'normal';
        $count = count($tokens);

        // [ <font-style> || <font-variant-css2> || <font-weight> || <font-width-css3> ]? <font-size> [ / <line-height> ]? <font-family>
        for ($i = 0; $i < $count && $i < 5; $i++) {
            $token = $tokens[$i];

            if (in_array($token, ['bold', 'bolder', 'lighter'], true) || (is_numeric($token) && (float) $token >= 1 && (float) $token <= 1000)) {
                $weight = $token;
            } elseif ($token === 'oblique' && preg_match('/^[+-]?(?:\d+(?:\.\d+)?|\.\d+)(?:deg|grad|rad|turn)$/', $tokens[$i + 1] ?? '') === 1) {
                $i++; // oblique <angle>
            } elseif (! in_array($token, self::FONT_PREFIX_KEYWORDS, true)) {
                break;
            }
        }

        // The size, an optional `/ <line-height>` (the slash may touch either side) and a family are required.
        $token = $tokens[$i] ?? '';
        $slash = $this->topLevelSlash($token);
        $size = $slash === null ? $token : substr($token, 0, $slash);
        $family = $i + 1;

        if ($slash !== null) {
            $family += $slash === strlen($token) - 1 ? 1 : 0; // `20px/ 1.2`: the line height is the next token
        } elseif (str_starts_with($tokens[$family] ?? '', '/')) {
            $family += $tokens[$family] === '/' ? 2 : 1;
        }

        return $size === '' || $family >= $count ? null : ['size' => $size, 'weight' => $weight];
    }

    /** Offset of the first `/` outside parentheses, or null. */
    private function topLevelSlash(string $token): ?int
    {
        $depth = 0;
        $length = strlen($token);

        for ($i = 0; $i < $length; $i++) {
            $char = $token[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($char === '/' && $depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /** The UA font size without a declaration: 13.333px for form controls, else the parent's (null: unknown), scaled for headings, <small>, <sub> and <sup>. */
    private function defaultFontSize(DOMElement $element, ?float $parent): ?float
    {
        $tag = Element::tag($element);

        if (isset(self::FORM_CONTROL_FONT_SIZES[$tag])) {
            return self::FORM_CONTROL_FONT_SIZES[$tag];
        }

        return $parent === null ? null : $parent * (self::TAG_FONT_FACTORS[$tag] ?? 1.0);
    }

    /** Lower bound in px of a lowercased font-size value, given the parent's bound (null: unknown). */
    private function fontSizeBound(string $value, ?float $parent, DOMElement $element): ?float
    {
        if (strlen($value) > self::MAX_RESOLVED_LENGTH) {
            return null;
        }

        if (isset(self::FONT_KEYWORDS[$value])) {
            return self::FONT_KEYWORDS[$value];
        }

        if (isset(self::RELATIVE_FONT_SIZES[$value])) {
            return $parent === null ? null : $parent * self::RELATIVE_FONT_SIZES[$value];
        }

        switch ($value) {
            case 'inherit':
            case 'unset':
                return $parent;
            case 'initial':
                return self::FONT_KEYWORDS['medium'];
            case 'revert':
                return $this->defaultFontSize($element, $parent);
        }

        if ($value === '' || $value[0] === '-') {
            return null; // empty, revert-layer, a negative length
        }

        $rem = str_contains($value, 'rem') ? $this->rootFontSize($element) : null;
        $position = 0;
        $result = $this->mathFactor($value, $position, 0, $rem, $parent);

        if ($result === null || $position !== strlen($value)) {
            return null;
        }

        [$length, $bound] = $result; // a floor alone (a lone viewport term) is no bound

        if (! $length) {
            return $value === '0' ? 0.0 : null; // a bare number is a length only when it is zero
        }

        return $bound === null ? null : max(0.0, $bound); // math results are clamped to the property's range
    }

    /**
     * The root's font-size bound for rem: 16px for the root itself and for a root without a font-size declaration,
     * null when the root's declared size has no bound.
     */
    private function rootFontSize(DOMElement $element): ?float
    {
        $root = $element->ownerDocument?->documentElement;

        if ($root === null || $root->isSameNode($element)) {
            return self::FONT_KEYWORDS['medium'];
        }

        return $this->fontSizeLowerBoundPx($root);
    }

    /**
     * One operand of a font-size expression at $position: a number, a dimension, a percentage, or calc(), min(), max(),
     * clamp() or a parenthesised sum (inside a function only). Recursive descent without backtracking; each call
     * consumes what it reads. A negative coefficient on rem, em, % or a viewport unit has no bound (its base is only a
     * lower bound, or unknown); a viewport or container term with a non-negative coefficient has the floor 0, which only
     * a sum uses.
     *
     * @return array{0: bool, 1: ?float, 2: ?float}|null whether it is a length (else a number), its lower bound in px
     *                                                   or exact number (null: unknown), and its floor as a sum term
     *                                                   (the bound where there is one); null when the expression is
     *                                                   invalid, nests deeper than MAX_CALC_DEPTH or overflows
     */
    private function mathFactor(string $value, int &$position, int $depth, ?float $rem, ?float $em): ?array
    {
        if (preg_match('/[+-]?(?:\d+(?:\.\d+)?|\.\d+)(?:e[+-]?\d+)?/A', $value, $m, 0, $position) === 1) {
            $position += strlen($m[0]);
            $number = (float) $m[0];

            if (! is_finite($number)) {
                return null;
            }

            $relative = fn (?float $base): array => $number < 0 || $base === null ? [true, null, null] : [true, $number * $base, $number * $base];

            if (($value[$position] ?? '') === '%') {
                $position++;

                return $relative($em === null ? null : $em / 100);
            }

            if (preg_match('/[a-z]+/A', $value, $unit, 0, $position) !== 1) {
                return [false, $number, $number];
            }

            $position += strlen($unit[0]);

            return match (true) {
                isset(self::ABSOLUTE_UNITS[$unit[0]]) => [true, $number * self::ABSOLUTE_UNITS[$unit[0]], $number * self::ABSOLUTE_UNITS[$unit[0]]],
                $unit[0] === 'rem' => $relative($rem),
                $unit[0] === 'em' => $relative($em),
                preg_match(self::VIEWPORT_UNITS, $unit[0]) === 1 => [true, null, $number < 0 ? null : 0.0],
                preg_match(self::FONT_RELATIVE_UNITS, $unit[0]) === 1 => [true, null, null],
                default => null,
            };
        }

        if ($depth >= self::MAX_CALC_DEPTH || preg_match('/(calc|min|max|clamp)?\(/A', $value, $m, 0, $position) !== 1) {
            return null;
        }

        $function = $m[1] ?? '';

        if ($function === '' && $depth === 0) {
            return null; // parentheses only group inside a math function
        }

        $position += strlen($m[0]);
        $arguments = [];

        do {
            $this->skipWhitespace($value, $position);
            $argument = $this->mathSum($value, $position, $depth + 1, $rem, $em);

            if ($argument === null) {
                return null;
            }

            $arguments[] = $argument;
            $this->skipWhitespace($value, $position);
            $separator = $value[$position++] ?? '';
        } while ($separator === ',');

        return $separator === ')' ? $this->mathFunction($function, $arguments) : null;
    }

    /**
     * Operands joined by + and - (surrounded by whitespace, as CSS requires). A sum of lengths is bounded by the sum of
     * its terms' bounds, or floors where a term has no bound; a difference has no bound.
     *
     * @return array{0: bool, 1: ?float, 2: ?float}|null
     */
    private function mathSum(string $value, int &$position, int $depth, ?float $rem, ?float $em): ?array
    {
        $left = $this->mathProduct($value, $position, $depth, $rem, $em);

        while ($left !== null) {
            $start = $position;
            $operator = $this->skipWhitespace($value, $position) ? $value[$position] ?? '' : '';

            if (($operator !== '+' && $operator !== '-') || ! ctype_space($value[$position + 1] ?? '')) {
                $position = $start;

                break;
            }

            $position++;
            $this->skipWhitespace($value, $position);
            $right = $this->mathProduct($value, $position, $depth, $rem, $em);

            if ($right === null || $right[0] !== $left[0]) {
                return null; // a length and a number cannot be added
            }

            $a = $left[1] ?? $left[2];
            $b = $right[1] ?? $right[2];
            $sum = $operator === '+' && $a !== null && $b !== null ? $a + $b : null;

            if ($sum !== null && ! is_finite($sum)) {
                return null;
            }

            $left = [$left[0], $sum, $sum];
        }

        return $left;
    }

    /**
     * Operands joined by * and /. A positive number times a length scales its bound and floor; a quotient has none.
     *
     * @return array{0: bool, 1: ?float, 2: ?float}|null
     */
    private function mathProduct(string $value, int &$position, int $depth, ?float $rem, ?float $em): ?array
    {
        $left = $this->mathFactor($value, $position, $depth, $rem, $em);

        while ($left !== null) {
            $start = $position;
            $this->skipWhitespace($value, $position);
            $operator = $value[$position] ?? '';

            if ($operator !== '*' && $operator !== '/') {
                $position = $start;

                break;
            }

            $position++;
            $this->skipWhitespace($value, $position);
            $right = $this->mathFactor($value, $position, $depth, $rem, $em);

            if ($right === null || ($right[0] && ($left[0] || $operator === '/'))) {
                return null; // a length times or divided by a length
            }

            if ($operator === '/') {
                $left = [$left[0], null, null];

                continue;
            }

            [$number, $operand] = $left[0] ? [$right[1], $left] : [$left[1], $right];
            $scale = $number !== null && ($number > 0 || ! $operand[0]);
            $left = [
                $operand[0],
                $scale && $operand[1] !== null ? $number * $operand[1] : null,
                $scale && $operand[2] !== null ? $number * $operand[2] : null,
            ];

            if (($left[1] !== null && ! is_finite($left[1])) || ($left[2] !== null && ! is_finite($left[2]))) {
                return null;
            }
        }

        return $left;
    }

    /**
     * calc() and parentheses (one argument, floor kept), min() (every bound known), max() (the known bounds) and
     * clamp(): at least its minimum, and at least the smaller of its value and maximum. Numbers are exact, so they need
     * every argument.
     *
     * @param  list<array{0: bool, 1: ?float, 2: ?float}>  $arguments
     * @return array{0: bool, 1: ?float, 2: ?float}|null
     */
    private function mathFunction(string $function, array $arguments): ?array
    {
        $length = $arguments[0][0];
        $bounds = [];

        foreach ($arguments as [$isLength, $bound]) {
            if ($isLength !== $length) {
                return null; // lengths and numbers mixed
            }

            $bounds[] = $bound;
        }

        $arity = match ($function) {
            '', 'calc' => count($bounds) === 1,
            'clamp' => count($bounds) === 3,
            default => true,
        };

        if (! $arity) {
            return null;
        }

        if ($function === '' || $function === 'calc') {
            return $arguments[0];
        }

        $known = array_values(array_filter($bounds, fn (?float $bound): bool => $bound !== null));

        if (! $length && count($known) !== count($bounds)) {
            return [false, null, null];
        }

        $bound = match ($function) {
            'min' => count($known) === count($bounds) ? min($known) : null,
            'max' => $known === [] ? null : max($known),
            'clamp' => match (true) {
                $bounds[1] !== null && $bounds[2] !== null => max($bounds[0] ?? -INF, min($bounds[1], $bounds[2])),
                default => $bounds[0],
            },
        };

        return [$length, $bound, $bound];
    }

    /** Today's estimate of a font-size value, used where there is no lower bound. */
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

        if (preg_match('/^(\d+(?:\.\d+)?|\.\d+)(px|pt|em|rem|%)$/', $value, $m) !== 1) {
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
        $ident = self::IDENTIFIER;

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
     * Read up to (and consume) the first of $terminators outside strings: braces anywhere (as in skipBlock()), others
     * outside parentheses.
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
            } elseif (($depth === 0 || $char === '{' || $char === '}') && in_array($char, $terminators, true)) {
                $text = substr($css, $start, $position - $start);
                $position++;

                return [$text, $char];
            }
        }

        return [substr($css, $start), null];
    }

    /** Skip a balanced block body; $position is just after the opening brace and ends after the closing one. */
    private function skipBlock(string $css, int &$position): void
    {
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
                $position++;

                return;
            }
        }

        $position = $length;
    }

    /**
     * Split on $separator outside strings, parentheses and brackets; optionally drop nested {…} blocks. After $limit
     * non-empty parts the rest is one more part.
     *
     * @return list<string>
     */
    private function splitTopLevel(string $text, string $separator, bool $skipBlocks = false, int $limit = PHP_INT_MAX): array
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
                if (trim($current) !== '') {
                    $parts[] = $current;
                }

                $current = '';

                if (count($parts) >= $limit) {
                    $current = substr($text, $i + 1);

                    break;
                }

                continue;
            }

            $current .= $char;
        }

        $parts[] = $current;

        return array_values(array_filter(array_map('trim', $parts), fn (string $part) => $part !== ''));
    }

    /**
     * The node key of the element's parent. A node path is the parent's path plus one step without a slash, so the parent's
     * key is read off the element's own key; a key that is no node path falls back to nodeKey().
     */
    private function parentKey(string $key, DOMElement $parent): string
    {
        $slash = strrpos($key, '/');

        return $slash !== false && $slash > 0 && $key[0] === '/' ? substr($key, 0, $slash) : $this->nodeKey($parent);
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
