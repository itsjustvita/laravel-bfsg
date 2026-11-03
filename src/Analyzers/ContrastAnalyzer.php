<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMElement;
use ItsJustVita\LaravelBfsg\Css\CssParser;
use ItsJustVita\LaravelBfsg\Dom\Element;
use ItsJustVita\LaravelBfsg\Severity;
use SplObjectStorage;

class ContrastAnalyzer extends BaseAnalyzer
{
    /** Hard cap of measured style chains (chainKey()) per document; text with an already measured chain reuses its verdict. */
    public const MAX_MEASURED = 500;

    /** Selectors of further occurrences listed in a grouped finding's `meta.selectors` (spec D3). */
    public const MAX_GROUPED_SELECTORS = 20;

    /** Wall-clock budget per document, in seconds (the same as an index build without a deadline). */
    public const TIME_BUDGET_SECONDS = CssParser::INDEX_TIME_BUDGET_SECONDS;

    /** Exempt style chains remembered per document (16-byte keys); beyond it, exemption is checked per element. */
    private const MAX_EXEMPT_CHAINS = 5000;

    /** Attributes always in the style chain: the cascade reads `class` and `style`, Element::isNotRendered() `hidden`. */
    private const CHAIN_ATTRIBUTES = ['class', 'style', 'hidden'];

    /** [normal, large] thresholds per level. */
    private const THRESHOLDS = ['AA' => [4.5, 3.0], 'AAA' => [7.0, 4.5]];

    private const SKIPPED_TAGS = ['script', 'style', 'noscript', 'template', 'title'];

    /** Uncertainties of resolveColors() that leave a colour unknown; `limit` alone (a truncated index) does not. */
    private const UNKNOWN_COLOUR = ['backdrop', 'colour'];

    /** Elements a label can label (HTML labelable elements; input unless type=hidden). */
    private const LABELABLE = ['button', 'input', 'meter', 'output', 'progress', 'select', 'textarea'];

    protected string $key = 'contrast';

    protected string $description = 'Colour contrast of text';

    protected array $rules = ['1.4.3', '1.4.6'];

    /** @var SplObjectStorage<DOMElement, bool> label => whether its labelled control is disabled, per document */
    private SplObjectStorage $inactiveLabels;

    /** @var list<array{0: DOMElement, 1: string, 2: int|false|null, 3: array{0: ?DOMElement, 1: int}|null}> the current path from the root: element, chain key, coveringChildIndex() (false: not looked up), the last child looked up and its element-child position */
    private array $chain = [];

    /** @var array<int, int> spl_object_id of an element on the path => its index in the path */
    private array $depths = [];

    /** @var array{id: bool, all: bool, attributes: array<string, bool>}|null CssParser::selectorAttributes(), per document */
    private ?array $selectorAttributes = null;

    /** @var array<string, array<string, mixed>|null> chain key => its finding (verdict(); null: passes), per document */
    private array $verdicts = [];

    /** @var array<string, true> chain keys of exempt text, per document (at most MAX_EXEMPT_CHAINS) */
    private array $exemptChains = [];

    /** @var array<string, array{element: DOMElement, finding: array<string, mixed>, occurrences: int, selectors: list<string>}> colour signature => group, in document order */
    private array $groups = [];

    public function __construct(private ?CssParser $cssParser = null, private ?string $level = null) {}

    protected function inspect(): void
    {
        $deadline = microtime(true) + self::TIME_BUDGET_SECONDS;
        $parser = ($this->cssParser?->parse($this->document) ?? $this->document->cssParser())->buildIndex($deadline);
        $level = $this->level();
        $measured = 0;
        $truncated = false;
        $this->inactiveLabels = new SplObjectStorage;

        foreach ($this->query('//*[text()[normalize-space()]]') as $element) {
            if (in_array(Element::tag($element), self::SKIPPED_TAGS, true) || $this->ownText($element) === '') {
                continue;
            }

            // Before the key, so exempt text is bounded by the budget too.
            if (microtime(true) > $deadline) {
                $truncated = true;

                break;
            }

            $key = $this->chainKey($parser, $element);

            if ($this->isExempt($parser, $element, $key) || $this->isInactive($element)) {
                continue;
            }

            if (! array_key_exists($key, $this->verdicts) && count($this->verdicts) >= self::MAX_MEASURED) {
                $truncated = true;

                break;
            }

            $measured++;
            $this->measure($parser, $element, $level);
        }

        foreach ($this->groups as ['element' => $element, 'finding' => $finding, 'occurrences' => $occurrences, 'selectors' => $selectors]) {
            $meta = [...$finding['meta'], 'occurrences' => $occurrences, 'selectors' => $selectors];

            if ($finding['unverifiable']) {
                $this->report('unverifiable', $finding['severity'], $finding['rule'], $element, $finding['params'], $meta, tags: $finding['tags']);
            } else {
                $this->report('insufficient', $finding['severity'], $finding['rule'], $element, $finding['params'], $meta, tags: $finding['tags']);
            }
        }

        if ($truncated) {
            $this->report('analysis_truncated', Severity::Notice, '1.4.3', null, ['limit' => $measured]);
        }

        // releases the document's elements
        $this->inactiveLabels = new SplObjectStorage;
        $this->chain = $this->depths = $this->verdicts = $this->exemptChains = $this->groups = [];
        $this->selectorAttributes = null;
    }

    /**
     * Text that is not measured because of its style chain: not rendered (aria-hidden text is visible and measured), hidden
     * by the stylesheets, or fully transparent (CssParser::isTransparent(): an exact opacity 0 at every viewport). Text of
     * an inactive component, which 1.4.3 exempts, depends on more than the chain (a label's control): isInactive().
     */
    private function isExempt(CssParser $parser, DOMElement $element, string $key): bool
    {
        if (isset($this->exemptChains[$key])) {
            return true;
        }

        if (array_key_exists($key, $this->verdicts)) {
            return false;
        }

        $exempt = Element::isNotRendered($element) || $parser->hidesElement($element) || $parser->isTransparent($element);

        if ($exempt && count($this->exemptChains) < self::MAX_EXEMPT_CHAINS) {
            $this->exemptChains[$key] = true;
        }

        return $exempt;
    }

    /**
     * Measures the text and adds it to the group of its colour signature (spec D3). The verdict is memoised per style chain
     * (chainKey()): text with the same chain has the same colours, size and verdict.
     */
    protected function measure(CssParser $parser, DOMElement $element, string $level): void
    {
        $key = $this->chainKey($parser, $element);

        if (! array_key_exists($key, $this->verdicts)) {
            $this->verdicts[$key] = $this->verdict($parser, $element, $level);
        }

        if ($this->verdicts[$key] === null) {
            return;
        }

        $finding = $this->verdicts[$key];
        $signature = implode('|', [
            $finding['unverifiable'] ? 'unverifiable' : 'insufficient',
            $finding['rule'],
            $finding['severity']->value,
            $finding['params']['foreground'],
            $finding['params']['background'],
            $finding['params']['required'] ?? '',
            $finding['meta']['approximate'] ? 'approximate' : 'exact',
        ]);

        if (! isset($this->groups[$signature])) {
            $this->groups[$signature] = ['element' => $element, 'finding' => $finding, 'occurrences' => 1, 'selectors' => []];

            return;
        }

        $this->groups[$signature]['occurrences']++;

        if (count($this->groups[$signature]['selectors']) < self::MAX_GROUPED_SELECTORS) {
            $this->groups[$signature]['selectors'][] = $this->document->selectorFor($element);
        }
    }

    /**
     * The verdict (spec D2), null when the text passes. A failure is an error when it is definite, else a warning tagged
     * `approximate`: an unknown colour or a truncated index (resolveColors()['uncertainty']), or an unknown size with a ratio
     * that meets the large-text threshold. A pass with an unknown colour is only the notice `unverifiable`, at any level
     * (R36). At level AAA, any other AA pass that fails AAA is the notice tagged `aaa`.
     *
     * @return array{unverifiable: bool, severity: Severity, rule: string, params: array<string, scalar>, meta: array<string, mixed>, tags: list<string>}|null
     */
    private function verdict(CssParser $parser, DOMElement $element, string $level): ?array
    {
        $colors = $parser->resolveColors($element);
        $ratio = $colors['foreground']->contrastRatio($colors['background']);
        $large = $this->isLarge($parser, $element);
        $hex = ['foreground' => $colors['foreground']->toHex(), 'background' => $colors['background']->toHex()];
        $failure = $this->failure($ratio, $large, 'AA', $colors['uncertainty']);
        $aaa = false;

        if ($failure === null) {
            if (array_intersect(self::UNKNOWN_COLOUR, $colors['uncertainty']) !== []) {
                return [
                    'unverifiable' => true,
                    'severity' => Severity::Notice,
                    'rule' => '1.4.3',
                    'params' => $hex,
                    'meta' => ['approximate' => true, 'ratio' => round($ratio, 2), 'large' => $large],
                    'tags' => ['approximate'],
                ];
            }

            if ($level !== 'AAA' || ($failure = $this->failure($ratio, $large, 'AAA', $colors['uncertainty'])) === null) {
                return null;
            }

            $aaa = true;
        }

        [$required, $approximate] = $failure;
        $tags = $aaa ? ['aaa'] : [];

        if ($approximate) {
            $tags[] = 'approximate';
        }

        return [
            'unverifiable' => false,
            'severity' => match (true) {
                $aaa => Severity::Notice,
                $approximate => Severity::Warning,
                default => Severity::Error,
            },
            'rule' => $aaa ? '1.4.6' : '1.4.3',
            'params' => ['ratio' => number_format($ratio, 2), 'required' => $required, ...$hex],
            'meta' => ['approximate' => $approximate, 'ratio' => round($ratio, 2), 'large' => $large],
            'tags' => $tags,
        ];
    }

    /**
     * The style chain key (R40): a 16-byte hash over every level from the root to the element of its tag, its attributes
     * that matter sorted by name, whether it is its parent's first and last element child, and whether a previous sibling
     * may cover its backdrop (CssParser::coveringChildIndex()). The attributes that matter are CHAIN_ATTRIBUTES and those a
     * selector sees (CssParser::selectorAttributes(): `id` when a selector has an id selector, others by value or only by
     * presence; all of them when a name could not be read). Nothing else can change the cascade or the exemption, so text
     * with the same key has the same colours, size and verdict, and a unique href or wire:key does not split a listing.
     * The path's keys are kept while the document-order walk stays below it, so each element is hashed once and memory
     * stays O(depth).
     */
    private function chainKey(CssParser $parser, DOMElement $element): string
    {
        $new = [];

        for ($node = $element; $node instanceof DOMElement && ! isset($this->depths[spl_object_id($node)]); $node = $node->parentNode) {
            $new[] = $node;
        }

        $depth = $node instanceof DOMElement ? $this->depths[spl_object_id($node)] + 1 : 0;

        while (count($this->chain) > $depth) {
            unset($this->depths[spl_object_id(array_pop($this->chain)[0])]);
        }

        foreach (array_reverse($new) as $node) {
            $attributes = [];
            $seen = $this->selectorAttributes ??= $parser->selectorAttributes();

            foreach ($node->attributes as $attribute) {
                $name = strtolower($attribute->nodeName);

                if ($seen['all'] || in_array($name, self::CHAIN_ATTRIBUTES, true) || ($name === 'id' && $seen['id'])) {
                    $attributes[$name] = $attribute->value;
                } elseif (isset($seen['attributes'][$name])) {
                    $attributes[$name] = $seen['attributes'][$name] ? $attribute->value : '';
                }
            }

            ksort($attributes, SORT_STRING);
            $level = [$node->nodeName, $attributes, $node->previousElementSibling === null, $node->nextElementSibling === null, $this->isCovered($parser, $node)];
            $this->depths[spl_object_id($node)] = count($this->chain);
            $this->chain[] = [$node, hash('xxh128', ($this->chain === [] ? '' : $this->chain[array_key_last($this->chain)][1]).serialize($level), true), false, null];
        }

        return $this->chain[array_key_last($this->chain)][1];
    }

    /**
     * Whether a previous element sibling may cover the node's backdrop: the node comes after the parent's covering child.
     * The parent (on the path) is looked up once, and the node's position is counted forward from the child looked up
     * before it, so in document order all children of a parent cost O(children) together.
     */
    private function isCovered(CssParser $parser, DOMElement $node): bool
    {
        if ($this->chain === [] || ! $node->parentNode instanceof DOMElement) {
            return false;
        }

        $parent = array_key_last($this->chain);

        if ($this->chain[$parent][2] === false) {
            $this->chain[$parent][2] = $parser->coveringChildIndex($this->chain[$parent][0]);
        }

        if ($this->chain[$parent][2] === null) {
            return false;
        }

        [$sibling, $position] = $this->chain[$parent][3] ?? [$this->chain[$parent][0]->firstElementChild, 0];

        while ($sibling !== null && $sibling !== $node) {
            $sibling = $sibling->nextElementSibling;
            $position++;
        }

        if ($sibling === null) { // before the cursor (not in document order): count from the first child
            for ($sibling = $this->chain[$parent][0]->firstElementChild, $position = 0; $sibling !== null && $sibling !== $node; $sibling = $sibling->nextElementSibling) {
                $position++;
            }
        }

        $this->chain[$parent][3] = [$node, $position];

        return $position > $this->chain[$parent][2];
    }

    /**
     * The requirement the ratio fails at the level and whether that failure is approximate; null when it passes. An
     * unknown size is held to the normal threshold.
     *
     * @param  list<string>  $uncertainty
     * @return array{0: float, 1: bool}|null
     */
    private function failure(float $ratio, ?bool $large, string $level, array $uncertainty): ?array
    {
        [$normal, $largeThreshold] = self::THRESHOLDS[$level];
        $required = $large === true ? $largeThreshold : $normal;

        if ($ratio >= $required) {
            return null;
        }

        return [$required, $uncertainty !== [] || ($large === null && $ratio >= $largeThreshold)];
    }

    /**
     * Whether the text is large (at least 24px, or 18.66px and bold) by its guaranteed size (fontSizeLowerBoundPx()); null
     * when unknown: no lower bound, or a bound that is large only if bold with an unknown weight (boldness()).
     */
    private function isLarge(CssParser $parser, DOMElement $element): ?bool
    {
        $size = $parser->fontSizeLowerBoundPx($element);

        return match (true) {
            $size === null => null,
            $size >= 24.0 => true,
            $size >= 18.66 => $parser->boldness($element),
            default => false,
        };
    }

    /**
     * Text of an inactive user interface component, which 1.4.3 exempts: inside a disabled control or fieldset
     * (Element::isDisabled()), inside an [aria-disabled="true"] subtree, or inside a label whose control is inactive.
     */
    private function isInactive(DOMElement $element, bool $labels = true): bool
    {
        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
            if (Element::enumAttr($node, 'aria-disabled') === 'true' || ($labels && Element::tag($node) === 'label' && $this->labelsDisabledControl($node))) {
                return true;
            }
        }

        return Element::isDisabled($element);
    }

    /** Whether the label's labelled control (its for= target if labelable, else its first labelable descendant) is inactive. */
    private function labelsDisabledControl(DOMElement $label): bool
    {
        if (isset($this->inactiveLabels[$label])) {
            return $this->inactiveLabels[$label];
        }

        $control = null;

        if ($label->hasAttribute('for')) {
            $target = $this->document->elementsById()[$label->getAttribute('for')] ?? null;
            $control = $target !== null && $this->isLabelable($target) ? $target : null;
        } else {
            foreach ($this->query('descendant::*[self::button or self::input or self::meter or self::output or self::progress or self::select or self::textarea]', $label) as $candidate) {
                if ($this->isLabelable($candidate)) {
                    $control = $candidate;

                    break;
                }
            }
        }

        return $this->inactiveLabels[$label] = $control !== null && $this->isInactive($control, labels: false);
    }

    private function isLabelable(DOMElement $element): bool
    {
        $tag = Element::tag($element);

        return in_array($tag, self::LABELABLE, true) && ($tag !== 'input' || Element::enumAttr($element, 'type') !== 'hidden');
    }

    protected function level(): string
    {
        $level = $this->level ?? (function_exists('config') ? (string) config('bfsg.compliance_level', 'AA') : 'AA');

        return strtoupper(trim($level)) === 'AAA' ? 'AAA' : 'AA';
    }
}
