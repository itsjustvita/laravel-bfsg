<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMElement;
use ItsJustVita\LaravelBfsg\Css\CssParser;
use ItsJustVita\LaravelBfsg\Dom\Element;
use ItsJustVita\LaravelBfsg\Severity;
use SplObjectStorage;

class ContrastAnalyzer extends BaseAnalyzer
{
    /** Hard cap of measured text elements per document. */
    public const MAX_MEASURED = 500;

    /** Wall-clock budget per document, in seconds (the same as an index build without a deadline). */
    public const TIME_BUDGET_SECONDS = CssParser::INDEX_TIME_BUDGET_SECONDS;

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

    public function __construct(private ?CssParser $cssParser = null, private ?string $level = null) {}

    protected function inspect(): void
    {
        $deadline = microtime(true) + self::TIME_BUDGET_SECONDS;
        $parser = ($this->cssParser?->parse($this->document) ?? $this->document->cssParser())->buildIndex($deadline);
        $level = $this->level();
        $measured = 0;
        $this->inactiveLabels = new SplObjectStorage;

        foreach ($this->query('//*[text()[normalize-space()]]') as $element) {
            if (in_array(Element::tag($element), self::SKIPPED_TAGS, true) || $this->ownText($element) === '' || $this->isExempt($parser, $element)) {
                continue;
            }

            if ($measured >= self::MAX_MEASURED || microtime(true) > $deadline) {
                $this->report('analysis_truncated', Severity::Notice, '1.4.3', null, ['limit' => $measured]);

                return;
            }

            $measured++;
            $this->measure($parser, $element, $level);
        }
    }

    /**
     * Text that is not measured: not rendered (aria-hidden text is visible and measured), hidden by the stylesheets, fully
     * transparent (opacity 0), or part of an inactive user interface component, which 1.4.3 exempts.
     */
    private function isExempt(CssParser $parser, DOMElement $element): bool
    {
        return Element::isNotRendered($element) || $parser->hidesElement($element) || $parser->opacity($element) <= 0.0 || $this->isInactive($element);
    }

    /**
     * The verdict (spec D2). A failure is an error when it is definite, else a warning tagged `approximate`: an unknown
     * colour or a truncated index (resolveColors()['uncertainty']), or an unknown size with a ratio that meets the
     * large-text threshold. A pass with an unknown colour is the notice `unverifiable`. At level AAA, an AA pass that
     * fails AAA adds the notice tagged `aaa`.
     */
    protected function measure(CssParser $parser, DOMElement $element, string $level): void
    {
        $colors = $parser->resolveColors($element);
        $ratio = $colors['foreground']->contrastRatio($colors['background']);
        $large = $this->isLarge($parser, $element);
        $hex = ['foreground' => $colors['foreground']->toHex(), 'background' => $colors['background']->toHex()];
        $failure = $this->failure($ratio, $large, 'AA', $colors['uncertainty']);
        $aaa = false;

        if ($failure === null) {
            if (array_intersect(self::UNKNOWN_COLOUR, $colors['uncertainty']) !== []) {
                $this->report('unverifiable', Severity::Notice, '1.4.3', $element, $hex, ['approximate' => true, 'ratio' => round($ratio, 2), 'large' => $large], tags: ['approximate']);
            }

            if ($level !== 'AAA' || ($failure = $this->failure($ratio, $large, 'AAA', $colors['uncertainty'])) === null) {
                return;
            }

            $aaa = true;
        }

        [$required, $approximate] = $failure;
        $tags = $aaa ? ['aaa'] : [];

        if ($approximate) {
            $tags[] = 'approximate';
        }

        $this->report(
            'insufficient',
            match (true) {
                $aaa => Severity::Notice,
                $approximate => Severity::Warning,
                default => Severity::Error,
            },
            $aaa ? '1.4.6' : '1.4.3',
            $element,
            ['ratio' => number_format($ratio, 2), 'required' => $required, ...$hex],
            ['approximate' => $approximate, 'ratio' => round($ratio, 2), 'large' => $large],
            tags: $tags,
        );
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
     * (Element::isDisabled()), inside an [aria-disabled="true"] subtree, or inside a label whose control is disabled.
     */
    private function isInactive(DOMElement $element): bool
    {
        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
            if (Element::enumAttr($node, 'aria-disabled') === 'true' || (Element::tag($node) === 'label' && $this->labelsDisabledControl($node))) {
                return true;
            }
        }

        return Element::isDisabled($element);
    }

    /** Whether the label's labelled control (its for= target if labelable, else its first labelable descendant) is disabled. */
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

        return $this->inactiveLabels[$label] = $control !== null && Element::isDisabled($control);
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
