<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMElement;
use ItsJustVita\LaravelBfsg\Dom\Element;
use ItsJustVita\LaravelBfsg\Severity;

class AriaAnalyzer extends BaseAnalyzer
{
    protected string $key = 'aria';

    protected string $description = 'ARIA roles, states and references';

    protected array $rules = ['4.1.2', '1.3.1'];

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

    protected function inspect(): void
    {
        // Check for invalid ARIA roles
        $this->checkAriaRoles();

        // Check for missing required ARIA attributes
        $this->checkRequiredAriaAttributes();

        // Check for conflicting ARIA attributes
        $this->checkConflictingAriaAttributes();

        // Check for proper ARIA labeling
        $this->checkAriaLabeling();

        // Check for ARIA on non-interactive elements
        $this->checkAriaOnNonInteractiveElements();
    }

    protected function checkAriaRoles(): void
    {
        foreach ($this->query('//*[@role]') as $element) {
            $role = $element->getAttribute('role');

            // Check for invalid role values
            if (! in_array($role, self::VALID_ROLES, true)) {
                $this->report('invalid_role', Severity::Error, '4.1.2', $element, ['role' => $role]);
            }

            // Check for redundant roles
            if ($this->isRedundantRole($element, $role)) {
                $this->report(
                    'redundant_role',
                    Severity::Warning,
                    '4.1.2',
                    $element,
                    ['role' => $role, 'tag' => Element::tag($element)],
                    autoFixable: true,
                );
            }
        }
    }

    protected function checkRequiredAriaAttributes(): void
    {
        // Elements with specific roles that require certain ARIA attributes
        $roleRequirements = [
            'checkbox' => ['aria-checked'],
            'combobox' => ['aria-expanded'],
            'slider' => ['aria-valuenow', 'aria-valuemin', 'aria-valuemax'],
            'spinbutton' => ['aria-valuenow'],
        ];

        foreach ($roleRequirements as $role => $requiredAttrs) {
            foreach ($this->query('//*[@role='.$this->document->xpathLiteral($role).']') as $element) {
                foreach ($requiredAttrs as $attr) {
                    if ($element->hasAttribute($attr)) {
                        continue;
                    }

                    $this->report(
                        'missing_required_state',
                        Severity::Error,
                        '4.1.2',
                        $element,
                        ['role' => $role, 'attribute' => $attr],
                    );
                }
            }
        }
    }

    protected function checkConflictingAriaAttributes(): void
    {
        // Check for aria-hidden on focusable elements
        $focusableWithHidden = $this->query('//a[@aria-hidden="true"]|//button[@aria-hidden="true"]|//input[@aria-hidden="true"]|//select[@aria-hidden="true"]|//textarea[@aria-hidden="true"]');

        foreach ($focusableWithHidden as $element) {
            $this->report('hidden_focusable', Severity::Error, '4.1.2', $element, ['tag' => Element::tag($element)]);
        }

        // Check for both aria-label and aria-labelledby
        foreach ($this->query('//*[@aria-label and @aria-labelledby]') as $element) {
            $this->report('label_conflict', Severity::Warning, '4.1.2', $element);
        }
    }

    protected function checkAriaLabeling(): void
    {
        $this->checkIdReferences('aria-labelledby');
        $this->checkIdReferences('aria-describedby');
    }

    /** Report every IDREF of $attribute that points at an id the document does not have. */
    protected function checkIdReferences(string $attribute): void
    {
        $elementsById = $this->document->elementsById();

        foreach ($this->query("//*[@{$attribute}]") as $element) {
            $ids = preg_split('/\s+/', $element->getAttribute($attribute), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            foreach ($ids as $id) {
                if (isset($elementsById[$id])) {
                    continue;
                }

                $this->report(
                    'dangling_idref',
                    Severity::Error,
                    '1.3.1',
                    $element,
                    ['attribute' => $attribute, 'id' => $id],
                    related: ['4.1.2'],
                );
            }
        }
    }

    protected function checkAriaOnNonInteractiveElements(): void
    {
        // Check for interactive ARIA attributes on non-interactive elements
        $nonInteractiveElements = ['div', 'span', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
        $interactiveAttributes = ['aria-pressed', 'aria-checked', 'aria-selected'];
        $interactiveRoles = ['button', 'checkbox', 'link', 'menuitem', 'option', 'radio', 'switch', 'tab'];

        foreach ($nonInteractiveElements as $tagName) {
            foreach ($interactiveAttributes as $attr) {
                foreach ($this->query("//{$tagName}[@{$attr}]") as $element) {
                    // Skip elements that carry an interactive role
                    if (in_array($element->getAttribute('role'), $interactiveRoles, true)) {
                        continue;
                    }

                    $this->report(
                        'unsupported_state',
                        Severity::Warning,
                        '4.1.2',
                        $element,
                        ['attribute' => $attr, 'tag' => $tagName],
                    );
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

        $tagName = Element::tag($element);

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
