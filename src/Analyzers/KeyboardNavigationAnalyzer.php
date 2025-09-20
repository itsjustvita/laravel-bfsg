<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMDocument;
use DOMXPath;

class KeyboardNavigationAnalyzer
{
    protected array $violations = [];

    // Interactive elements that should be keyboard accessible
    protected const INTERACTIVE_ELEMENTS = [
        'a', 'button', 'input', 'select', 'textarea',
        'audio', 'video', 'iframe', 'embed', 'object',
    ];

    // Elements that can receive tabindex
    protected const FOCUSABLE_ROLES = [
        'button', 'link', 'textbox', 'menuitem', 'tab',
        'checkbox', 'radio', 'combobox', 'slider',
    ];

    public function analyze(DOMDocument $dom): array
    {
        $this->violations = [];
        $xpath = new DOMXPath($dom);

        // Check for skip links
        $this->checkSkipLinks($xpath);

        // Check tab order
        $this->checkTabOrder($xpath);

        // Check for focus traps
        $this->checkFocusTraps($xpath);

        // Check interactive elements
        $this->checkInteractiveElements($xpath);

        // Check for positive tabindex (anti-pattern)
        $this->checkPositiveTabindex($xpath);

        // Check for click handlers on non-interactive elements
        $this->checkClickHandlers($xpath);

        return ['issues' => $this->violations];
    }

    protected function checkSkipLinks(DOMXPath $xpath): void
    {
        // Check if there's a skip link at the beginning of the page
        $firstLinks = $xpath->query('//body//a[position() <= 3]');
        $hasSkipLink = false;

        foreach ($firstLinks as $link) {
            $href = $link->getAttribute('href');
            $text = strtolower(trim($link->textContent));

            // Common skip link patterns
            if (strpos($href, '#') === 0 &&
                (strpos($text, 'skip') !== false ||
                 strpos($text, 'jump') !== false ||
                 strpos($text, 'main') !== false)) {
                $hasSkipLink = true;
                break;
            }
        }

        if (! $hasSkipLink) {
            $this->violations[] = [
                'type' => 'warning',
                'rule' => 'WCAG 2.4.1',
                'element' => 'navigation',
                'message' => 'No skip link found at the beginning of the page',
                'suggestion' => 'Add a skip link to main content for keyboard users',
                'auto_fixable' => true,
                'fix_example' => '<a href="#main" class="skip-link">Skip to main content</a>',
            ];
        }
    }

    protected function checkTabOrder(DOMXPath $xpath): void
    {
        // Find all elements with tabindex
        $elementsWithTabindex = $xpath->query('//*[@tabindex]');
        $tabOrders = [];

        foreach ($elementsWithTabindex as $element) {
            $tabindex = $element->getAttribute('tabindex');
            $tabOrders[] = [
                'element' => $element->nodeName,
                'tabindex' => (int) $tabindex,
            ];
        }

        // Check for logical tab order
        $positiveTabindexes = array_filter($tabOrders, fn ($item) => $item['tabindex'] > 0);

        if (count($positiveTabindexes) > 0 && count($positiveTabindexes) < count($tabOrders)) {
            $this->violations[] = [
                'type' => 'warning',
                'rule' => 'WCAG 2.4.3',
                'element' => 'various',
                'message' => 'Mixed tabindex values can create confusing navigation order',
                'count' => count($positiveTabindexes),
                'suggestion' => 'Use tabindex="0" for natural flow or tabindex="-1" to remove from tab order',
                'auto_fixable' => false,
            ];
        }
    }

    protected function checkFocusTraps(DOMXPath $xpath): void
    {
        // Check for modals/dialogs without proper focus management
        $modals = $xpath->query('//*[@role="dialog" or @role="alertdialog" or contains(@class, "modal")]');

        foreach ($modals as $modal) {
            // Check if modal has proper ARIA attributes
            $hasAriaModal = $modal->getAttribute('aria-modal') === 'true';
            $hasAriaLabel = $modal->hasAttribute('aria-label') || $modal->hasAttribute('aria-labelledby');

            if (! $hasAriaModal) {
                $this->violations[] = [
                    'type' => 'error',
                    'rule' => 'WCAG 2.1.2',
                    'element' => $modal->nodeName,
                    'message' => 'Modal/dialog without aria-modal="true" may create keyboard trap',
                    'suggestion' => 'Add aria-modal="true" and implement focus management',
                    'auto_fixable' => true,
                ];
            }

            if (! $hasAriaLabel) {
                $this->violations[] = [
                    'type' => 'error',
                    'rule' => 'WCAG 4.1.2',
                    'element' => $modal->nodeName,
                    'message' => 'Modal/dialog without accessible name',
                    'suggestion' => 'Add aria-label or aria-labelledby to identify the modal',
                    'auto_fixable' => false,
                ];
            }
        }
    }

    protected function checkInteractiveElements(DOMXPath $xpath): void
    {
        // Check for interactive elements that might not be keyboard accessible
        foreach (self::INTERACTIVE_ELEMENTS as $tag) {
            $elements = $xpath->query("//{$tag}");

            foreach ($elements as $element) {
                // Check if element is disabled
                if ($element->hasAttribute('disabled')) {
                    continue;
                }

                // Check for negative tabindex on interactive elements (removes from tab order)
                if ($element->getAttribute('tabindex') === '-1') {
                    $this->violations[] = [
                        'type' => 'warning',
                        'rule' => 'WCAG 2.1.1',
                        'element' => $element->nodeName,
                        'message' => "Interactive {$element->nodeName} removed from tab order",
                        'suggestion' => 'Ensure element is still keyboard accessible via other means',
                        'auto_fixable' => true,
                    ];
                }

                // Check links without href
                if ($element->nodeName === 'a' && ! $element->hasAttribute('href')) {
                    $this->violations[] = [
                        'type' => 'error',
                        'rule' => 'WCAG 2.1.1',
                        'element' => 'a',
                        'message' => 'Link without href is not keyboard accessible',
                        'suggestion' => 'Add href attribute or use button element for actions',
                        'auto_fixable' => false,
                    ];
                }
            }
        }
    }

    protected function checkPositiveTabindex(DOMXPath $xpath): void
    {
        // Positive tabindex is generally an anti-pattern
        $positiveTabindex = $xpath->query('//*[@tabindex and @tabindex > 0]');

        if ($positiveTabindex->length > 0) {
            $this->violations[] = [
                'type' => 'warning',
                'rule' => 'WCAG 2.4.3',
                'element' => 'various',
                'message' => "Found {$positiveTabindex->length} element(s) with positive tabindex",
                'suggestion' => 'Avoid positive tabindex values; use DOM order for natural tab flow',
                'auto_fixable' => true,
            ];
        }
    }

    protected function checkClickHandlers(DOMXPath $xpath): void
    {
        // Check for click handlers on non-interactive elements
        $elementsWithOnclick = $xpath->query('//*[@onclick]');

        foreach ($elementsWithOnclick as $element) {
            $tagName = strtolower($element->nodeName);
            $role = $element->getAttribute('role');

            // Check if it's not a naturally interactive element
            if (! in_array($tagName, self::INTERACTIVE_ELEMENTS)) {
                // Check if it has an interactive role
                if (! in_array($role, self::FOCUSABLE_ROLES)) {
                    // Check if it has tabindex to make it focusable
                    if (! $element->hasAttribute('tabindex') || $element->getAttribute('tabindex') === '-1') {
                        $this->violations[] = [
                            'type' => 'error',
                            'rule' => 'WCAG 2.1.1',
                            'element' => $element->nodeName,
                            'message' => 'Non-interactive element with click handler is not keyboard accessible',
                            'suggestion' => 'Add tabindex="0" and keyboard event handlers (onkeydown/onkeyup)',
                            'auto_fixable' => true,
                        ];
                    }
                }
            }
        }

        // Check for elements with only mouse events
        $mouseOnlyElements = $xpath->query('//*[@onmouseover or @onmouseout or @onmousedown or @onmouseup]');

        foreach ($mouseOnlyElements as $element) {
            // Check if it also has keyboard equivalents
            $hasKeyboardEvents = $element->hasAttribute('onkeydown') ||
                                $element->hasAttribute('onkeyup') ||
                                $element->hasAttribute('onkeypress') ||
                                $element->hasAttribute('onfocus') ||
                                $element->hasAttribute('onblur');

            if (! $hasKeyboardEvents) {
                $this->violations[] = [
                    'type' => 'warning',
                    'rule' => 'WCAG 2.1.1',
                    'element' => $element->nodeName,
                    'message' => 'Element with mouse events lacks keyboard event handlers',
                    'suggestion' => 'Add equivalent keyboard event handlers for all mouse interactions',
                    'auto_fixable' => false,
                ];
            }
        }
    }
}
