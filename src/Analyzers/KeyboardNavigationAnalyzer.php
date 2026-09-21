<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMElement;
use ItsJustVita\LaravelBfsg\Dom\Element;
use ItsJustVita\LaravelBfsg\Severity;

class KeyboardNavigationAnalyzer extends BaseAnalyzer
{
    protected string $key = 'keyboard';

    protected string $description = 'Keyboard operability, focus order and bypass blocks';

    protected array $rules = ['2.1.1', '2.4.1', '2.4.3', '4.1.2'];

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

    /**
     * Skip link text patterns (matched case-insensitive via mb_stripos).
     * English + German variants — extend for other languages as needed.
     */
    protected const SKIP_LINK_PATTERNS = [
        // English
        'skip',
        'jump',
        'main',
        // German
        'überspringen',
        'zum inhalt',
        'zum hauptinhalt',
        'zur navigation',
        'zum menü',
        'inhalt springen',
    ];

    protected function inspect(): void
    {
        $this->checkSkipLinks();
        $this->checkFocusTraps();
        $this->checkInteractiveElements();
        $this->checkPositiveTabindex();
        $this->checkClickHandlers();
    }

    protected function checkSkipLinks(): void
    {
        // Check if there's a skip link at the beginning of the page
        foreach ($this->query('//body//a[position() <= 3]') as $link) {
            $href = $link->getAttribute('href');
            $text = $this->text($link);

            if (! str_starts_with($href, '#') || $text === '') {
                continue;
            }

            foreach (self::SKIP_LINK_PATTERNS as $pattern) {
                // mb_stripos handles umlauts (ü, ö, ä) safely, case-insensitive.
                if (mb_stripos($text, $pattern) !== false) {
                    return;
                }
            }
        }

        $this->report('missing_skip_link', Severity::Warning, '2.4.1');
    }

    protected function checkFocusTraps(): void
    {
        // Modals/dialogs without proper focus management
        foreach ($this->query('//*[@role="dialog" or @role="alertdialog" or contains(@class, "modal")]') as $modal) {
            if ($modal->getAttribute('aria-modal') !== 'true') {
                $this->report('dialog_missing_aria_modal', Severity::Error, '2.1.2', $modal);
            }

            if (! $modal->hasAttribute('aria-label') && ! $modal->hasAttribute('aria-labelledby')) {
                $this->report('dialog_missing_name', Severity::Error, '4.1.2', $modal);
            }
        }
    }

    protected function checkInteractiveElements(): void
    {
        foreach (self::INTERACTIVE_ELEMENTS as $tag) {
            foreach ($this->query('//'.$tag) as $element) {
                if ($element->hasAttribute('disabled')) {
                    continue;
                }

                // Negative tabindex on interactive elements removes them from the tab order
                if ($element->getAttribute('tabindex') === '-1') {
                    $this->report('negative_tabindex_on_interactive', Severity::Warning, '2.1.1', $element, [
                        'tag' => Element::tag($element),
                    ]);
                }

                // Check links without href — many modern interactive <a> elements use
                // tabindex="0" + keyboard handlers and ARE keyboard-accessible.
                if ($tag === 'a' && ! $element->hasAttribute('href') && ! $this->isKeyboardAccessibleAnchor($element)) {
                    $this->report('anchor_not_focusable', Severity::Warning, '2.1.1', $element);
                }
            }
        }
    }

    /**
     * Is an <a> without href wired up to be keyboard-accessible?
     * Accept: tabindex="0" + at least one keyboard handler, OR
     *         tabindex="0" + role="button" (button-styled anchor).
     */
    protected function isKeyboardAccessibleAnchor(DOMElement $anchor): bool
    {
        if ($anchor->getAttribute('tabindex') !== '0') {
            return false;
        }

        if ($anchor->getAttribute('role') === 'button') {
            return true;
        }

        return $anchor->hasAttribute('onkeydown')
            || $anchor->hasAttribute('onkeyup')
            || $anchor->hasAttribute('onkeypress');
    }

    protected function checkPositiveTabindex(): void
    {
        // Positive tabindex is generally an anti-pattern
        foreach ($this->query('//*[@tabindex and @tabindex > 0]') as $element) {
            $this->report('positive_tabindex', Severity::Warning, '2.4.3', $element, [
                'value' => (int) $element->getAttribute('tabindex'),
            ]);
        }
    }

    protected function checkClickHandlers(): void
    {
        // Click handlers on non-interactive elements
        foreach ($this->query('//*[@onclick]') as $element) {
            if (in_array(Element::tag($element), self::INTERACTIVE_ELEMENTS, true)) {
                continue;
            }

            if (in_array($element->getAttribute('role'), self::FOCUSABLE_ROLES, true)) {
                continue;
            }

            // Focusable through tabindex?
            if ($element->hasAttribute('tabindex') && $element->getAttribute('tabindex') !== '-1') {
                continue;
            }

            $this->report('click_without_keyboard', Severity::Error, '2.1.1', $element, [
                'tag' => Element::tag($element),
            ]);
        }

        // Elements with mouse events but no keyboard equivalent
        foreach ($this->query('//*[@onmouseover or @onmouseout or @onmousedown or @onmouseup]') as $element) {
            $hasKeyboardEvents = $element->hasAttribute('onkeydown')
                || $element->hasAttribute('onkeyup')
                || $element->hasAttribute('onkeypress')
                || $element->hasAttribute('onfocus')
                || $element->hasAttribute('onblur');

            if (! $hasKeyboardEvents) {
                $this->report('mouse_only_handler', Severity::Warning, '2.1.1', $element, [
                    'tag' => Element::tag($element),
                ]);
            }
        }
    }
}
