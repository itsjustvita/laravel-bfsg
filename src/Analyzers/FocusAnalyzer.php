<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMDocument;
use DOMXPath;

class FocusAnalyzer
{
    protected array $violations = [];

    protected array $interactiveElements = [
        'a',
        'button',
        'input',
        'select',
        'textarea',
    ];

    public function analyze(DOMDocument $dom): array
    {
        $this->violations = [];
        $xpath = new DOMXPath($dom);

        $this->checkInlineStyles($xpath);
        $this->checkStyleBlocks($xpath);

        return ['issues' => $this->violations];
    }

    protected function checkInlineStyles(DOMXPath $xpath): void
    {
        // Check interactive elements with inline styles removing focus outline
        $selectors = [];
        foreach ($this->interactiveElements as $el) {
            $selectors[] = "//{$el}[@style]";
        }
        // Also check elements with tabindex
        $selectors[] = '//*[@tabindex][@style]';

        $query = implode('|', $selectors);
        $elements = $xpath->query($query);

        foreach ($elements as $element) {
            $style = $element->getAttribute('style');

            if ($this->removesOutline($style)) {
                $this->violations[] = [
                    'type' => 'error',
                    'rule' => 'WCAG 2.4.7',
                    'element' => $element->nodeName,
                    'message' => 'Focus indicator removed via inline style',
                    'suggestion' => 'Do not remove the outline on interactive elements, or provide an alternative focus indicator',
                    'auto_fixable' => false,
                ];
            }
        }
    }

    protected function checkStyleBlocks(DOMXPath $xpath): void
    {
        $styles = $xpath->query('//style');

        foreach ($styles as $style) {
            $css = $style->textContent;

            if (empty(trim($css))) {
                continue;
            }

            $this->analyzeStyleBlock($css);
        }
    }

    protected function analyzeStyleBlock(string $css): void
    {
        // Check for global focus resets like *:focus { outline: none }
        if (preg_match('/\*\s*:focus\s*\{[^}]*(?:outline\s*:\s*(?:none|0(?:px)?)\s*;?|outline-style\s*:\s*none\s*;?)[^}]*\}/i', $css)) {
            // Check if there's an alternative focus indicator in the same block
            if (! $this->hasAlternativeFocusIndicator($css)) {
                $this->violations[] = [
                    'type' => 'warning',
                    'rule' => 'WCAG 2.4.7',
                    'element' => 'style',
                    'message' => 'Global focus outline reset detected (*:focus { outline: none })',
                    'suggestion' => 'Provide an alternative focus indicator such as box-shadow, border, or background-color',
                    'auto_fixable' => false,
                ];
            }

            return;
        }

        // Check for focus rules on specific elements removing outline without alternatives
        if (preg_match_all('/([\w\s.*#\[\]=",>+~:-]+):focus\s*\{([^}]*)\}/i', $css, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $ruleBody = $match[2];

                if ($this->removesOutline($ruleBody)) {
                    // Check if the same rule provides an alternative
                    if (! $this->hasAlternativeInRule($ruleBody)) {
                        $this->violations[] = [
                            'type' => 'error',
                            'rule' => 'WCAG 2.4.7',
                            'element' => 'style',
                            'message' => 'Focus outline removed in stylesheet without alternative indicator',
                            'selector' => trim($match[1]).':focus',
                            'suggestion' => 'Provide an alternative focus indicator such as box-shadow, border, or background-color',
                            'auto_fixable' => false,
                        ];
                    }
                }
            }
        }
    }

    protected function removesOutline(string $css): bool
    {
        return (bool) preg_match('/outline\s*:\s*(?:none|0(?:px)?)\s*[;!}]?/i', $css)
            || (bool) preg_match('/outline-style\s*:\s*none/i', $css);
    }

    protected function hasAlternativeFocusIndicator(string $css): bool
    {
        // Check if :focus rules exist with alternative indicators
        if (preg_match_all('/:focus\s*\{([^}]*)\}/i', $css, $matches)) {
            foreach ($matches[1] as $ruleBody) {
                if ($this->hasAlternativeInRule($ruleBody)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function hasAlternativeInRule(string $ruleBody): bool
    {
        return (bool) preg_match('/box-shadow\s*:/i', $ruleBody)
            || (bool) preg_match('/border\s*:/i', $ruleBody)
            || (bool) preg_match('/border-color\s*:/i', $ruleBody)
            || (bool) preg_match('/background\s*:/i', $ruleBody)
            || (bool) preg_match('/background-color\s*:/i', $ruleBody);
    }
}
