<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMDocument;
use DOMXPath;
use DOMElement;

class AriaAnalyzer
{
    protected array $violations = [];

    // Valid ARIA roles
    protected const VALID_ROLES = [
        'alert', 'alertdialog', 'application', 'article', 'banner', 'button',
        'checkbox', 'columnheader', 'combobox', 'complementary', 'contentinfo',
        'definition', 'dialog', 'directory', 'document', 'feed', 'figure',
        'form', 'grid', 'gridcell', 'group', 'heading', 'img', 'link',
        'list', 'listbox', 'listitem', 'log', 'main', 'marquee', 'math',
        'menu', 'menubar', 'menuitem', 'menuitemcheckbox', 'menuitemradio',
        'navigation', 'none', 'note', 'option', 'presentation', 'progressbar',
        'radio', 'radiogroup', 'region', 'row', 'rowgroup', 'rowheader',
        'scrollbar', 'search', 'searchbox', 'separator', 'slider', 'spinbutton',
        'status', 'switch', 'tab', 'table', 'tablist', 'tabpanel', 'term',
        'textbox', 'timer', 'toolbar', 'tooltip', 'tree', 'treegrid', 'treeitem',
    ];

    // ARIA attributes that need validation
    protected const ARIA_ATTRIBUTES = [
        'aria-label', 'aria-labelledby', 'aria-describedby', 'aria-required',
        'aria-invalid', 'aria-hidden', 'aria-expanded', 'aria-checked',
        'aria-selected', 'aria-disabled', 'aria-readonly', 'aria-live',
        'aria-atomic', 'aria-relevant', 'aria-busy', 'aria-current',
    ];

    public function analyze(DOMDocument $dom): array
    {
        $this->violations = [];
        $xpath = new DOMXPath($dom);

        // Check for invalid ARIA roles
        $this->checkAriaRoles($xpath);

        // Check for missing required ARIA attributes
        $this->checkRequiredAriaAttributes($xpath);

        // Check for conflicting ARIA attributes
        $this->checkConflictingAriaAttributes($xpath);

        // Check for proper ARIA labeling
        $this->checkAriaLabeling($xpath);

        // Check for ARIA on non-interactive elements
        $this->checkAriaOnNonInteractiveElements($xpath);

        return $this->violations;
    }

    protected function checkAriaRoles(DOMXPath $xpath): void
    {
        $elementsWithRoles = $xpath->query('//*[@role]');

        foreach ($elementsWithRoles as $element) {
            $role = $element->getAttribute('role');

            // Check for invalid role values
            if (!in_array($role, self::VALID_ROLES)) {
                $this->violations[] = [
                    'type' => 'error',
                    'rule' => 'WCAG 4.1.2',
                    'element' => $element->nodeName,
                    'message' => "Invalid ARIA role: '{$role}'",
                    'suggestion' => 'Use a valid ARIA role from the WAI-ARIA specification',
                    'auto_fixable' => false,
                ];
            }

            // Check for redundant roles
            if ($this->isRedundantRole($element, $role)) {
                $this->violations[] = [
                    'type' => 'warning',
                    'rule' => 'WCAG 4.1.2',
                    'element' => $element->nodeName,
                    'message' => "Redundant ARIA role '{$role}' on {$element->nodeName}",
                    'suggestion' => 'Remove redundant role attribute',
                    'auto_fixable' => true,
                ];
            }
        }
    }

    protected function checkRequiredAriaAttributes(DOMXPath $xpath): void
    {
        // Elements with specific roles that require certain ARIA attributes
        $roleRequirements = [
            'checkbox' => ['aria-checked'],
            'combobox' => ['aria-expanded'],
            'slider' => ['aria-valuenow', 'aria-valuemin', 'aria-valuemax'],
            'spinbutton' => ['aria-valuenow'],
        ];

        foreach ($roleRequirements as $role => $requiredAttrs) {
            $elements = $xpath->query("//*[@role='{$role}']");

            foreach ($elements as $element) {
                foreach ($requiredAttrs as $attr) {
                    if (!$element->hasAttribute($attr)) {
                        $this->violations[] = [
                            'type' => 'error',
                            'rule' => 'WCAG 4.1.2',
                            'element' => $element->nodeName,
                            'message' => "Role '{$role}' requires {$attr} attribute",
                            'suggestion' => "Add {$attr} attribute to element with role='{$role}'",
                            'auto_fixable' => false,
                        ];
                    }
                }
            }
        }
    }

    protected function checkConflictingAriaAttributes(DOMXPath $xpath): void
    {
        // Check for aria-hidden on focusable elements
        $focusableWithHidden = $xpath->query('//a[@aria-hidden="true"]|//button[@aria-hidden="true"]|//input[@aria-hidden="true"]|//select[@aria-hidden="true"]|//textarea[@aria-hidden="true"]');

        foreach ($focusableWithHidden as $element) {
            $this->violations[] = [
                'type' => 'error',
                'rule' => 'WCAG 4.1.2',
                'element' => $element->nodeName,
                'message' => 'Focusable element with aria-hidden="true"',
                'suggestion' => 'Remove aria-hidden or make element non-focusable',
                'auto_fixable' => false,
            ];
        }

        // Check for both aria-label and aria-labelledby
        $elementsWithBothLabels = $xpath->query('//*[@aria-label and @aria-labelledby]');

        foreach ($elementsWithBothLabels as $element) {
            $this->violations[] = [
                'type' => 'warning',
                'rule' => 'WCAG 4.1.2',
                'element' => $element->nodeName,
                'message' => 'Element has both aria-label and aria-labelledby',
                'suggestion' => 'Use either aria-label or aria-labelledby, not both',
                'auto_fixable' => false,
            ];
        }
    }

    protected function checkAriaLabeling(DOMXPath $xpath): void
    {
        // Check aria-labelledby references
        $elementsWithLabelledby = $xpath->query('//*[@aria-labelledby]');

        foreach ($elementsWithLabelledby as $element) {
            $ids = explode(' ', $element->getAttribute('aria-labelledby'));

            foreach ($ids as $id) {
                $id = trim($id);
                if (!empty($id)) {
                    $referencedElement = $xpath->query("//*[@id='{$id}']");

                    if ($referencedElement->length === 0) {
                        $this->violations[] = [
                            'type' => 'error',
                            'rule' => 'WCAG 1.3.1, 4.1.2',
                            'element' => $element->nodeName,
                            'message' => "aria-labelledby references non-existent ID: '{$id}'",
                            'suggestion' => 'Ensure the referenced ID exists in the document',
                            'auto_fixable' => false,
                        ];
                    }
                }
            }
        }

        // Check aria-describedby references
        $elementsWithDescribedby = $xpath->query('//*[@aria-describedby]');

        foreach ($elementsWithDescribedby as $element) {
            $ids = explode(' ', $element->getAttribute('aria-describedby'));

            foreach ($ids as $id) {
                $id = trim($id);
                if (!empty($id)) {
                    $referencedElement = $xpath->query("//*[@id='{$id}']");

                    if ($referencedElement->length === 0) {
                        $this->violations[] = [
                            'type' => 'error',
                            'rule' => 'WCAG 1.3.1, 4.1.2',
                            'element' => $element->nodeName,
                            'message' => "aria-describedby references non-existent ID: '{$id}'",
                            'suggestion' => 'Ensure the referenced ID exists in the document',
                            'auto_fixable' => false,
                        ];
                    }
                }
            }
        }
    }

    protected function checkAriaOnNonInteractiveElements(DOMXPath $xpath): void
    {
        // Check for interactive ARIA attributes on non-interactive elements
        $nonInteractiveElements = ['div', 'span', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
        $interactiveAttributes = ['aria-pressed', 'aria-checked', 'aria-selected'];

        foreach ($nonInteractiveElements as $tagName) {
            foreach ($interactiveAttributes as $attr) {
                $elements = $xpath->query("//{$tagName}[@{$attr}]");

                foreach ($elements as $element) {
                    // Check if element has an interactive role
                    $role = $element->getAttribute('role');
                    $interactiveRoles = ['button', 'checkbox', 'link', 'menuitem', 'option', 'radio', 'switch', 'tab'];

                    if (!in_array($role, $interactiveRoles)) {
                        $this->violations[] = [
                            'type' => 'warning',
                            'rule' => 'WCAG 4.1.2',
                            'element' => $element->nodeName,
                            'message' => "Interactive ARIA attribute '{$attr}' on non-interactive element",
                            'suggestion' => 'Add an appropriate interactive role or remove the attribute',
                            'auto_fixable' => false,
                        ];
                    }
                }
            }
        }
    }

    protected function isRedundantRole(DOMElement $element, string $role): bool
    {
        // Map of HTML elements to their implicit ARIA roles
        $implicitRoles = [
            'button' => 'button',
            'input' => [
                'button' => 'button',
                'checkbox' => 'checkbox',
                'radio' => 'radio',
                'range' => 'slider',
            ],
            'a' => 'link',
            'article' => 'article',
            'aside' => 'complementary',
            'footer' => 'contentinfo',
            'header' => 'banner',
            'main' => 'main',
            'nav' => 'navigation',
            'section' => 'region',
        ];

        $tagName = strtolower($element->nodeName);

        if (isset($implicitRoles[$tagName])) {
            if (is_array($implicitRoles[$tagName])) {
                $type = $element->getAttribute('type');
                return isset($implicitRoles[$tagName][$type]) && $implicitRoles[$tagName][$type] === $role;
            }

            return $implicitRoles[$tagName] === $role;
        }

        return false;
    }
}