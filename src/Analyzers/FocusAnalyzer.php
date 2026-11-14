<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMElement;
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

    /** Outline style keywords (none and hidden are handled separately). */
    private const OUTLINE_STYLES = ['auto', 'solid', 'dashed', 'dotted', 'double', 'groove', 'ridge', 'inset', 'outset'];

    protected string $key = 'focus';

    protected string $description = 'Visible keyboard focus';

    protected array $rules = ['2.4.7'];

    public function __construct(private ?CssParser $cssParser = null) {}

    protected function inspect(): void
    {
        $parser = $this->cssParser?->parse($this->document) ?? $this->document->cssParser();

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
        $outlines = [];

        foreach ($parser->rules() as $rule) {
            $parsed = $this->focusSelector($rule['selector']);

            if ($parsed === null) {
                continue;
            }

            $declarations = $this->values($rule['properties']);
            $parts = $this->outlineParts($declarations);

            if ($parts !== null) {
                $outlines[] = ['base' => $parsed['base'], 'parts' => $parts];
            }

            if ($this->hasIndicator($declarations)) {
                $indicators[] = $parsed['base'];
            } elseif (! $parsed['within'] && $this->removesOutline($declarations)) {
                $removals[] = ['base' => $parsed['base'], 'selector' => $parsed['selector'], 'sheet' => $rule['sheet']];
            }
        }

        if (in_array('', $indicators, true)) {
            return;
        }

        $matches = [];

        $styles = $this->document->styleElements();
        $reported = [];

        foreach ($removals as $removal) {
            $global = $removal['base'] === '';

            if ($global) {
                $severity = $this->outlineVisible($this->mergeParts(array_column(array_filter($outlines, fn (array $o) => $o['base'] === ''), 'parts'))) ? null : Severity::Error;
            } else {
                $severity = $this->uncoveredSeverity($parser, $removal['base'], $indicators, $outlines, $matches);
            }

            if ($severity === null) {
                continue;
            }

            $style = $styles[$removal['sheet']] ?? null;
            $slot = ($global ? 'global' : 'specific').'|'.$severity->value.'|'.$removal['sheet'];

            if ($style === null || isset($reported[$slot])) {
                continue;
            }

            $reported[$slot] = true;

            if ($global) {
                $this->report('outline_removed_global', Severity::Error, '2.4.7', $style, ['selector' => $removal['selector']]);
            } else {
                $this->report('outline_removed', $severity, '2.4.7', $style, ['selector' => $removal['selector']]);
            }
        }
    }

    /**
     * Error when a focusable element matching $base has no indicator, Notice when only elements that take
     * focus by script (tabindex="-1") are uncovered, null when nothing keyboard-reachable is left uncovered.
     *
     * @param  list<string>  $indicators  base selectors of focus rules that paint an indicator
     * @param  list<array{base: string, parts: array{style: ?string, width: ?string, color: ?string}}>  $outlines
     * @param  array<string, array<int, DOMElement>>  $matches  element ids per base selector (cache)
     */
    protected function uncoveredSeverity(CssParser $parser, string $base, array $indicators, array $outlines, array &$matches): ?Severity
    {
        if (in_array($base, $indicators, true)) {
            return null;
        }

        $expression = $parser->simpleSelectorToXpath($base);

        if ($expression === null) {
            return Severity::Error;
        }

        $matching = function (string $selector) use ($parser, &$matches): array {
            if (! isset($matches[$selector])) {
                $ids = [];
                $selectorExpression = $parser->simpleSelectorToXpath($selector);

                foreach ($selectorExpression === null ? [] : $this->query($selectorExpression) as $element) {
                    $ids[spl_object_id($element)] = $element; // kept alive so the object id stays this node's
                }

                $matches[$selector] = $ids;
            }

            return $matches[$selector];
        };

        $covered = [];

        foreach ($indicators as $indicator) {
            $covered += $matching($indicator);
        }

        $severity = null;

        foreach ($this->query($expression) as $element) {
            $id = spl_object_id($element);

            if (isset($covered[$id])) {
                continue;
            }

            $focusable = Element::isFocusable($element);

            if (! $focusable && ! Element::isFocusableOnlyByScript($element)) {
                continue;
            }

            $parts = [];

            foreach ($outlines as $outline) {
                if ($outline['base'] === '' || isset($matching($outline['base'])[$id])) {
                    $parts[] = $outline['parts'];
                }
            }

            if ($this->outlineVisible($this->mergeParts($parts))) {
                continue;
            }

            if ($focusable) {
                return Severity::Error;
            }

            $severity = Severity::Notice;
        }

        return $severity;
    }

    /**
     * What a rule says about the outline, per longhand: 'set' (paints), 'off' (none/hidden, zero, transparent)
     * or null (silent). Null when the rule does not mention the outline at all.
     *
     * @param  array<string, string>  $declarations
     * @return array{style: ?string, width: ?string, color: ?string}|null
     */
    protected function outlineParts(array $declarations): ?array
    {
        $parts = ['style' => null, 'width' => null, 'color' => null];
        $found = false;

        if (isset($declarations['outline'])) {
            $found = true;

            foreach (preg_split('/\s+/', $declarations['outline'], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                if (in_array($token, ['none', 'hidden'], true)) {
                    $parts['style'] = 'off';
                } elseif (in_array($token, self::OUTLINE_STYLES, true)) {
                    $parts['style'] = 'set';
                } elseif ($this->isZero($token)) {
                    $parts['width'] = 'off';
                } elseif ($token === 'transparent') {
                    $parts['color'] = 'off';
                } elseif (preg_match('/^(\d|\.\d)/', $token) === 1 || in_array($token, ['thin', 'medium', 'thick'], true)) {
                    $parts['width'] = 'set';
                } elseif (! in_array($token, self::NOTHING, true) && ! str_starts_with($token, 'var(')) {
                    $parts['color'] = 'set';
                }
            }
        }

        if (isset($declarations['outline-style'])) {
            $found = true;
            $parts['style'] = in_array($declarations['outline-style'], ['none', 'hidden'], true) ? 'off' : 'set';
        }

        if (isset($declarations['outline-width'])) {
            $found = true;
            $parts['width'] = $this->isZero($declarations['outline-width']) ? 'off' : 'set';
        }

        if (isset($declarations['outline-color'])) {
            $found = true;
            $parts['color'] = $declarations['outline-color'] === 'transparent' ? 'off' : 'set';
        }

        return $found ? $parts : null;
    }

    /**
     * Per longhand a value that paints wins over one that does not (utility classes are separate rules).
     *
     * @param  list<array{style: ?string, width: ?string, color: ?string}>  $parts
     * @return array{style: ?string, width: ?string, color: ?string}
     */
    protected function mergeParts(array $parts): array
    {
        $merged = ['style' => null, 'width' => null, 'color' => null];

        foreach ($parts as $part) {
            foreach ($merged as $longhand => $value) {
                if ($part[$longhand] === 'set' || ($part[$longhand] !== null && $value === null)) {
                    $merged[$longhand] = $part[$longhand];
                }
            }
        }

        return $merged;
    }

    /**
     * Visible when no longhand is switched off and the style was set: a lone outline-color or outline-width
     * does not paint over a removed (none) style. A rule set that never mentions a style cannot add one.
     *
     * @param  array{style: ?string, width: ?string, color: ?string}  $merged
     */
    protected function outlineVisible(array $merged): bool
    {
        return $merged['style'] === 'set' && $merged['width'] !== 'off' && $merged['color'] !== 'off';
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
