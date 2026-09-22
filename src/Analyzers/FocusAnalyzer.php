<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use ItsJustVita\LaravelBfsg\Css\CssParser;
use ItsJustVita\LaravelBfsg\Dom\Element;
use ItsJustVita\LaravelBfsg\Severity;

class FocusAnalyzer extends BaseAnalyzer
{
    /** Values that do not paint anything. */
    private const NOTHING = ['none', 'hidden', '0', 'transparent', 'inherit', 'initial', 'unset'];

    /** border-* / background-* properties that do not produce a visible indicator. */
    private const NON_INDICATOR_PROPERTIES = [
        'border-radius', 'border-collapse', 'border-spacing', 'border-image', 'background-image', 'background-size',
        'background-position', 'background-repeat', 'background-clip', 'background-origin', 'background-attachment',
        'background-blend-mode',
    ];

    protected string $key = 'focus';

    protected string $description = 'Visible keyboard focus';

    protected array $rules = ['2.4.7'];

    public function __construct(private ?CssParser $cssParser = null) {}

    protected function inspect(): void
    {
        $parser = ($this->cssParser ?? new CssParser)->parse($this->document);

        $this->checkInlineStyles($parser);
        $this->checkStylesheets($parser);
    }

    protected function checkInlineStyles(CssParser $parser): void
    {
        foreach ($this->queryVisible('//*[@style]') as $element) {
            if (! Element::isFocusable($element)) {
                continue;
            }

            $declarations = $this->values($parser->inlineStyle($element));

            if ($this->removesOutline($declarations) && ! $this->hasAlternative($declarations)) {
                $this->report('outline_removed_inline', Severity::Error, '2.4.7', $element, ['tag' => Element::tag($element)]);
            }
        }
    }

    /**
     * Global resets (*:focus, :focus, :focus-visible) and per-selector resets (S:focus, S:focus-visible),
     * reported once per <style> element with the first offending selector as the parameter. A per-selector
     * reset is fine when a global rule, a rule with the same base selector, or — element by element — any
     * focus rule matching the same elements (utility classes: .focus\:outline-none + .focus\:ring-2)
     * paints an indicator. Resets whose base selector matches no element on the page are ignored.
     */
    protected function checkStylesheets(CssParser $parser): void
    {
        $removals = [];
        $indicators = [];

        foreach ($parser->rules() as $rule) {
            $parsed = $this->focusSelector($rule['selector']);

            if ($parsed === null) {
                continue;
            }

            $declarations = $this->values($rule['properties']);

            if ($this->hasIndicator($declarations)) {
                $indicators[] = $parsed['base'];
            } elseif (! $parsed['within'] && $this->removesOutline($declarations)) {
                $removals[] = ['base' => $parsed['base'], 'selector' => $parsed['selector'], 'sheet' => $rule['sheet']];
            }
        }

        if (in_array('', $indicators, true)) {
            return;
        }

        $styles = $this->document->styleElements();
        $reported = [];

        foreach ($removals as $removal) {
            $global = $removal['base'] === '';

            if (! $global && ! $this->uncoveredElementExists($parser, $removal['base'], $indicators)) {
                continue;
            }

            $style = $styles[$removal['sheet']] ?? null;
            $slot = ($global ? 'global' : 'specific').'|'.$removal['sheet'];

            if ($style === null || isset($reported[$slot])) {
                continue;
            }

            $reported[$slot] = true;

            if ($global) {
                $this->report('outline_removed_global', Severity::Error, '2.4.7', $style, ['selector' => $removal['selector']]);
            } else {
                $this->report('outline_removed', Severity::Error, '2.4.7', $style, ['selector' => $removal['selector']]);
            }
        }
    }

    /**
     * @param  list<string>  $indicators  base selectors of focus rules that paint an indicator
     */
    protected function uncoveredElementExists(CssParser $parser, string $base, array $indicators): bool
    {
        if (in_array($base, $indicators, true)) {
            return false;
        }

        $expression = $parser->simpleSelectorToXpath($base);

        if ($expression === null) {
            return true;
        }

        $covered = [];

        foreach ($indicators as $indicator) {
            $indicatorExpression = $parser->simpleSelectorToXpath($indicator);

            foreach ($indicatorExpression === null ? [] : $this->query($indicatorExpression) as $element) {
                $covered[spl_object_id($element)] = true;
            }
        }

        foreach ($this->query($expression) as $element) {
            if (! isset($covered[spl_object_id($element)])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Split "S:focus" / "S:focus-visible" / "S:focus-within" into base S ('' for *, :focus, :focus-visible).
     * Selectors where :focus is not on the subject, or :focus:not(:focus-visible) (mouse-only reset), are ignored.
     *
     * @return array{base: string, selector: string, within: bool}|null
     */
    protected function focusSelector(string $selector): ?array
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $selector) ?? $selector));

        if (str_contains($normalized, ':not(:focus-visible)')) {
            return null;
        }

        if (preg_match('/^(.*?):(focus(?:-visible|-within)?)$/', $normalized, $m) !== 1) {
            return null;
        }

        $base = trim($m[1]);

        return ['base' => $base === '*' ? '' : $base, 'selector' => $normalized, 'within' => $m[2] === 'focus-within'];
    }

    /**
     * @param  array<string, string>  $declarations
     */
    protected function removesOutline(array $declarations): bool
    {
        if (isset($declarations['outline']) && $this->paintsNothing($declarations['outline'], true)) {
            return true;
        }

        return (isset($declarations['outline-style']) && in_array($declarations['outline-style'], ['none', 'hidden'], true))
            || (isset($declarations['outline-width']) && $this->isZero($declarations['outline-width']))
            || (isset($declarations['outline-color']) && $declarations['outline-color'] === 'transparent');
    }

    /**
     * box-shadow other than none, or a border-* / background-* declaration that paints something.
     *
     * @param  array<string, string>  $declarations
     */
    protected function hasAlternative(array $declarations): bool
    {
        foreach ($declarations as $property => $value) {
            if ($property === 'box-shadow' && ! $this->paintsNothing($value)) {
                return true;
            }

            if ((str_starts_with($property, 'border') || str_starts_with($property, 'background'))
                && ! in_array($property, self::NON_INDICATOR_PROPERTIES, true)
                && ! $this->paintsNothing($value)) {
                return true;
            }
        }

        return false;
    }

    /** A real indicator: an outline that paints, or an alternative. @param  array<string, string>  $declarations */
    protected function hasIndicator(array $declarations): bool
    {
        if (isset($declarations['outline']) && ! $this->paintsNothing($declarations['outline'], true)) {
            return true;
        }

        return $this->hasAlternative($declarations);
    }

    /** Every token is none/0/transparent/… — or, for outlines, any token removes the outline. */
    protected function paintsNothing(string $value, bool $anyToken = false): bool
    {
        $tokens = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($tokens === []) {
            return true;
        }

        $nothing = array_filter($tokens, fn (string $token) => in_array($token, self::NOTHING, true) || $this->isZero($token));

        return $anyToken ? $nothing !== [] : count($nothing) === count($tokens);
    }

    protected function isZero(string $value): bool
    {
        return preg_match('/^0*\.?0+(px|em|rem|%)?$/', trim($value)) === 1;
    }

    /**
     * @param  array<string, array{value: string, important: bool}>  $properties
     * @return array<string, string> lower-cased values
     */
    protected function values(array $properties): array
    {
        return array_map(fn (array $declaration) => strtolower(trim($declaration['value'])), $properties);
    }
}
