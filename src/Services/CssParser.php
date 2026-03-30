<?php

namespace ItsJustVita\LaravelBfsg\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;

class CssParser
{
    protected array $rules = [];

    protected DOMXPath $xpath;

    /**
     * Parse CSS from a DOMDocument (extracts all <style> blocks)
     */
    public function parse(DOMDocument $dom): self
    {
        $this->xpath = new DOMXPath($dom);
        $this->rules = [];

        // Extract <style> blocks
        $styleNodes = $dom->getElementsByTagName('style');
        foreach ($styleNodes as $styleNode) {
            $css = $styleNode->textContent;
            $this->parseCss($css);
        }

        return $this;
    }

    /**
     * Parse raw CSS string into rules
     */
    protected function parseCss(string $css): void
    {
        // Remove comments
        $css = preg_replace('/\/\*.*?\*\//s', '', $css);

        // Skip @media, @import, @keyframes blocks (out of scope)
        $css = preg_replace('/@media\s*[^{]*\{(?:[^{}]*|\{[^{}]*\})*\}/s', '', $css);
        $css = preg_replace('/@import[^;]*;/', '', $css);
        $css = preg_replace('/@keyframes\s*[^{]*\{(?:[^{}]*|\{[^{}]*\})*\}/s', '', $css);
        $css = preg_replace('/@font-face\s*\{[^}]*\}/s', '', $css);

        // Match rule blocks: selector { properties }
        preg_match_all('/([^{]+)\{([^}]*)\}/s', $css, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $selectorGroup = trim($match[1]);
            $propertiesStr = trim($match[2]);

            // Parse properties
            $properties = $this->parseProperties($propertiesStr);

            if (empty($properties)) {
                continue;
            }

            // Handle comma-separated selectors
            $selectors = array_map('trim', explode(',', $selectorGroup));

            foreach ($selectors as $selector) {
                if (empty($selector)) {
                    continue;
                }

                $this->rules[] = [
                    'selector' => $selector,
                    'properties' => $properties,
                    'specificity' => $this->calculateSpecificity($selector),
                ];
            }
        }
    }

    /**
     * Parse CSS property string into key-value pairs
     */
    protected function parseProperties(string $propertiesStr): array
    {
        $properties = [];
        $declarations = array_filter(array_map('trim', explode(';', $propertiesStr)));

        foreach ($declarations as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) === 2) {
                $property = trim($parts[0]);
                $value = trim($parts[1]);

                // Remove !important flag (note it but strip it)
                $value = str_replace('!important', '', $value);
                $value = trim($value);

                if ($property !== '' && $value !== '') {
                    $properties[$property] = $value;
                }
            }
        }

        return $properties;
    }

    /**
     * Calculate CSS specificity as [id, class, element] tuple
     */
    public function calculateSpecificity(string $selector): array
    {
        $ids = 0;
        $classes = 0;
        $elements = 0;

        // Remove :not() wrapper but keep contents
        $selector = preg_replace('/:not\(([^)]*)\)/', '$1', $selector);

        // Count ID selectors
        $ids = preg_match_all('/#[a-zA-Z_][\w-]*/', $selector);

        // Count class selectors, attribute selectors, pseudo-classes
        $classes = preg_match_all('/\.[a-zA-Z_][\w-]*/', $selector);
        $classes += preg_match_all('/\[[^\]]+\]/', $selector);
        $classes += preg_match_all('/:[a-zA-Z][\w-]*(?!\()/', $selector);

        // Count element selectors and pseudo-elements
        // Remove IDs, classes, attributes, pseudo-classes first
        $cleaned = preg_replace('/#[a-zA-Z_][\w-]*/', '', $selector);
        $cleaned = preg_replace('/\.[a-zA-Z_][\w-]*/', '', $cleaned);
        $cleaned = preg_replace('/\[[^\]]+\]/', '', $cleaned);
        $cleaned = preg_replace('/:[a-zA-Z][\w-]*/', '', $cleaned);
        $cleaned = preg_replace('/::[\w-]+/', '', $cleaned);

        // Remove combinators and whitespace
        $cleaned = preg_replace('/[>+~\s]+/', ' ', trim($cleaned));
        $parts = array_filter(explode(' ', $cleaned));

        foreach ($parts as $part) {
            if ($part !== '*' && preg_match('/^[a-zA-Z][\w-]*$/', $part)) {
                $elements++;
            }
        }

        // Count pseudo-elements
        $elements += preg_match_all('/::[\w-]+/', $selector);

        return [$ids, $classes, $elements];
    }

    /**
     * Get the resolved color and background-color for a DOM element
     */
    public function getResolvedColors(DOMElement $element): array
    {
        $color = null;
        $backgroundColor = null;
        $approximate = false;

        // 1. Collect matching CSS rules sorted by specificity
        $matchingRules = $this->getMatchingRules($element);

        // Sort by specificity (ascending — last wins)
        usort($matchingRules, function ($a, $b) {
            return $this->compareSpecificity($a['specificity'], $b['specificity']);
        });

        // Apply CSS rules (lowest specificity first, higher overwrites)
        foreach ($matchingRules as $rule) {
            if (isset($rule['properties']['color'])) {
                $value = $rule['properties']['color'];
                if ($this->isResolvableColor($value)) {
                    $color = $value;
                } else {
                    $approximate = true;
                }
            }
            if (isset($rule['properties']['background-color'])) {
                $value = $rule['properties']['background-color'];
                if ($this->isResolvableColor($value)) {
                    $backgroundColor = $value;
                } else {
                    $approximate = true;
                }
            }
            if (isset($rule['properties']['background'])) {
                // Extract color from shorthand background
                $bgColor = $this->extractColorFromBackground($rule['properties']['background']);
                if ($bgColor !== null) {
                    $backgroundColor = $bgColor;
                }
            }
        }

        // 2. Inline styles override everything
        $inlineStyle = $element->getAttribute('style');
        if ($inlineStyle) {
            $inlineProps = $this->parseProperties($inlineStyle);
            if (isset($inlineProps['color']) && $this->isResolvableColor($inlineProps['color'])) {
                $color = $inlineProps['color'];
            }
            if (isset($inlineProps['background-color']) && $this->isResolvableColor($inlineProps['background-color'])) {
                $backgroundColor = $inlineProps['background-color'];
            }
            if (isset($inlineProps['background'])) {
                $bgColor = $this->extractColorFromBackground($inlineProps['background']);
                if ($bgColor !== null) {
                    $backgroundColor = $bgColor;
                }
            }
        }

        // 3. Inheritance — walk up DOM tree
        if ($color === null || $backgroundColor === null) {
            $parent = $element->parentNode;
            while ($parent instanceof DOMElement) {
                $parentColors = $this->getDirectColors($parent);

                if ($color === null && $parentColors['color'] !== null) {
                    $color = $parentColors['color'];
                    $approximate = true; // inherited
                }
                if ($backgroundColor === null && $parentColors['backgroundColor'] !== null) {
                    $backgroundColor = $parentColors['backgroundColor'];
                    $approximate = true; // inherited
                }

                if ($color !== null && $backgroundColor !== null) {
                    break;
                }

                $parent = $parent->parentNode;
            }
        }

        // 4. Browser defaults
        if ($color === null) {
            $color = '#000000';
            $approximate = true;
        }
        if ($backgroundColor === null) {
            $backgroundColor = '#ffffff';
            $approximate = true;
        }

        return [
            'color' => $color,
            'backgroundColor' => $backgroundColor,
            'approximate' => $approximate,
        ];
    }

    /**
     * Get direct (non-inherited) colors for an element
     */
    protected function getDirectColors(DOMElement $element): array
    {
        $color = null;
        $backgroundColor = null;

        // Check CSS rules
        $matchingRules = $this->getMatchingRules($element);
        usort($matchingRules, fn ($a, $b) => $this->compareSpecificity($a['specificity'], $b['specificity']));

        foreach ($matchingRules as $rule) {
            if (isset($rule['properties']['color'])) {
                $color = $rule['properties']['color'];
            }
            if (isset($rule['properties']['background-color'])) {
                $backgroundColor = $rule['properties']['background-color'];
            }
            if (isset($rule['properties']['background'])) {
                $bgColor = $this->extractColorFromBackground($rule['properties']['background']);
                if ($bgColor !== null) {
                    $backgroundColor = $bgColor;
                }
            }
        }

        // Inline overrides
        $inlineStyle = $element->getAttribute('style');
        if ($inlineStyle) {
            $inlineProps = $this->parseProperties($inlineStyle);
            if (isset($inlineProps['color'])) {
                $color = $inlineProps['color'];
            }
            if (isset($inlineProps['background-color'])) {
                $backgroundColor = $inlineProps['background-color'];
            }
            if (isset($inlineProps['background'])) {
                $bgColor = $this->extractColorFromBackground($inlineProps['background']);
                if ($bgColor !== null) {
                    $backgroundColor = $bgColor;
                }
            }
        }

        return ['color' => $color, 'backgroundColor' => $backgroundColor];
    }

    /**
     * Get all CSS rules matching a DOM element
     */
    protected function getMatchingRules(DOMElement $element): array
    {
        $matching = [];

        foreach ($this->rules as $rule) {
            if ($this->selectorMatchesElement($rule['selector'], $element)) {
                $matching[] = $rule;
            }
        }

        return $matching;
    }

    /**
     * Check if a CSS selector matches a DOM element
     */
    public function selectorMatchesElement(string $selector, DOMElement $element): bool
    {
        // Try to convert CSS selector to XPath and use it
        try {
            $xpath = $this->cssToXpath($selector);
            if ($xpath === null) {
                return false;
            }

            $results = $this->xpath->query($xpath);
            if ($results === false) {
                return false;
            }

            foreach ($results as $node) {
                if ($node->isSameNode($element)) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            return false;
        }

        return false;
    }

    /**
     * Convert simple CSS selector to XPath
     */
    protected function cssToXpath(string $selector): ?string
    {
        $selector = trim($selector);

        // Skip pseudo-classes/elements we can't evaluate
        if (preg_match('/:(hover|focus|active|visited|focus-within|focus-visible)\b/', $selector)) {
            return null;
        }

        // Handle child combinator: parent > child
        $parts = preg_split('/\s*>\s*/', $selector);
        if (count($parts) > 1) {
            $xpaths = [];
            foreach ($parts as $part) {
                $x = $this->simpleSelectorToXpath(trim($part));
                if ($x === null) {
                    return null;
                }
                $xpaths[] = $x;
            }

            return '//'.implode('/', $xpaths);
        }

        // Handle descendant combinator
        $parts = preg_split('/\s+/', $selector);
        if (count($parts) > 1) {
            $xpaths = [];
            foreach ($parts as $part) {
                $x = $this->simpleSelectorToXpath(trim($part));
                if ($x === null) {
                    return null;
                }
                $xpaths[] = $x;
            }

            return '//'.implode('//', $xpaths);
        }

        $x = $this->simpleSelectorToXpath($selector);

        return $x ? '//'.$x : null;
    }

    /**
     * Convert a single simple selector to XPath
     */
    protected function simpleSelectorToXpath(string $selector): ?string
    {
        $element = '*';
        $predicates = [];

        // Extract element name
        if (preg_match('/^([a-zA-Z][\w-]*)/', $selector, $m)) {
            $element = $m[1];
            $selector = substr($selector, strlen($m[0]));
        } elseif (str_starts_with($selector, '*')) {
            $selector = substr($selector, 1);
        }

        // Process remaining selector parts
        while ($selector !== '' && $selector !== false) {
            // ID selector
            if (preg_match('/^#([a-zA-Z_][\w-]*)/', $selector, $m)) {
                $predicates[] = "@id='{$m[1]}'";
                $selector = substr($selector, strlen($m[0]));
            }
            // Class selector
            elseif (preg_match('/^\.([a-zA-Z_][\w-]*)/', $selector, $m)) {
                $predicates[] = "contains(concat(' ', normalize-space(@class), ' '), ' {$m[1]} ')";
                $selector = substr($selector, strlen($m[0]));
            }
            // Attribute selector
            elseif (preg_match('/^\[([a-zA-Z_][\w-]*)(?:([~|^$*]?=)"?([^"\]]*)"?)?\]/', $selector, $m)) {
                $attr = $m[1];
                $op = $m[2] ?? '';
                $val = $m[3] ?? '';

                if ($op === '' && $val === '') {
                    $predicates[] = "@{$attr}";
                } elseif ($op === '=') {
                    $predicates[] = "@{$attr}='{$val}'";
                } elseif ($op === '~=') {
                    $predicates[] = "contains(concat(' ', @{$attr}, ' '), ' {$val} ')";
                } elseif ($op === '*=') {
                    $predicates[] = "contains(@{$attr}, '{$val}')";
                } elseif ($op === '^=') {
                    $predicates[] = "starts-with(@{$attr}, '{$val}')";
                } else {
                    $predicates[] = "@{$attr}='{$val}'";
                }
                $selector = substr($selector, strlen($m[0]));
            } else {
                // Unknown selector part, bail
                break;
            }
        }

        if (empty($predicates)) {
            return $element;
        }

        return $element.'['.implode(' and ', $predicates).']';
    }

    /**
     * Compare two specificity tuples
     */
    protected function compareSpecificity(array $a, array $b): int
    {
        for ($i = 0; $i < 3; $i++) {
            if ($a[$i] !== $b[$i]) {
                return $a[$i] <=> $b[$i];
            }
        }

        return 0;
    }

    /**
     * Check if a color value can be resolved (not a CSS variable or calc)
     */
    protected function isResolvableColor(string $value): bool
    {
        if (str_contains($value, 'var(')) {
            return false;
        }
        if (str_contains($value, 'calc(')) {
            return false;
        }
        if (str_contains($value, 'currentColor')) {
            return false;
        }
        if ($value === 'inherit' || $value === 'initial' || $value === 'unset') {
            return false;
        }

        return true;
    }

    /**
     * Extract a color value from a CSS background shorthand
     */
    protected function extractColorFromBackground(string $value): ?string
    {
        // Try to find a color in the background shorthand
        // Match hex colors
        if (preg_match('/(#[0-9a-fA-F]{3,8})\b/', $value, $m)) {
            return $m[1];
        }
        // Match rgb/rgba
        if (preg_match('/(rgba?\([^)]+\))/', $value, $m)) {
            return $m[1];
        }
        // Match hsl/hsla
        if (preg_match('/(hsla?\([^)]+\))/', $value, $m)) {
            return $m[1];
        }
        // Match named colors (basic ones)
        $namedColors = ['white', 'black', 'red', 'green', 'blue', 'yellow', 'gray', 'grey',
            'transparent', 'orange', 'purple', 'pink', 'brown', 'navy', 'teal'];
        foreach ($namedColors as $name) {
            if (preg_match('/\b'.$name.'\b/i', $value)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Get all parsed rules (for testing/debugging)
     */
    public function getRules(): array
    {
        return $this->rules;
    }
}
