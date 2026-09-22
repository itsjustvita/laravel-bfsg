<?php

namespace ItsJustVita\LaravelBfsg\Dom;

use DOMElement;

final class Roles
{
    /** WAI-ARIA 1.2 concrete roles (plus the 1.3 additions comment, mark, suggestion, image). */
    public const VALID = [
        'alert', 'alertdialog', 'application', 'article', 'banner', 'blockquote', 'button', 'caption', 'cell',
        'checkbox', 'code', 'columnheader', 'combobox', 'comment', 'complementary', 'contentinfo', 'definition',
        'deletion', 'dialog', 'directory', 'document', 'emphasis', 'feed', 'figure', 'form', 'generic', 'grid',
        'gridcell', 'group', 'heading', 'image', 'img', 'insertion', 'link', 'list', 'listbox', 'listitem', 'log',
        'main', 'mark', 'marquee', 'math', 'menu', 'menubar', 'menuitem', 'menuitemcheckbox', 'menuitemradio',
        'meter', 'navigation', 'none', 'note', 'option', 'paragraph', 'presentation', 'progressbar', 'radio',
        'radiogroup', 'region', 'row', 'rowgroup', 'rowheader', 'scrollbar', 'search', 'searchbox', 'separator',
        'slider', 'spinbutton', 'status', 'strong', 'subscript', 'suggestion', 'superscript', 'switch', 'tab',
        'table', 'tablist', 'tabpanel', 'term', 'textbox', 'time', 'timer', 'toolbar', 'tooltip', 'tree',
        'treegrid', 'treeitem',
        // DPUB-ARIA 1.1
        'doc-abstract', 'doc-acknowledgments', 'doc-afterword', 'doc-appendix', 'doc-backlink', 'doc-biblioentry',
        'doc-bibliography', 'doc-biblioref', 'doc-chapter', 'doc-colophon', 'doc-conclusion', 'doc-cover',
        'doc-credit', 'doc-credits', 'doc-dedication', 'doc-endnote', 'doc-endnotes', 'doc-epigraph',
        'doc-epilogue', 'doc-errata', 'doc-example', 'doc-footnote', 'doc-foreword', 'doc-glossary',
        'doc-glossref', 'doc-index', 'doc-introduction', 'doc-noteref', 'doc-notice', 'doc-pagebreak',
        'doc-pagefooter', 'doc-pageheader', 'doc-pagelist', 'doc-part', 'doc-preface', 'doc-prologue',
        'doc-pullquote', 'doc-qna', 'doc-subtitle', 'doc-tip', 'doc-toc',
        // Graphics-ARIA
        'graphics-document', 'graphics-object', 'graphics-symbol',
    ];

    public const ABSTRACT = [
        'command', 'composite', 'input', 'landmark', 'range', 'roletype', 'section', 'sectionhead', 'select',
        'structure', 'widget', 'window',
    ];

    /** @var array<string, list<string>> */
    public const REQUIRED_STATES = [
        'checkbox' => ['aria-checked'],
        'menuitemcheckbox' => ['aria-checked'],
        'menuitemradio' => ['aria-checked'],
        'radio' => ['aria-checked'],
        'switch' => ['aria-checked'],
        'combobox' => ['aria-expanded'],
        'heading' => ['aria-level'],
        'meter' => ['aria-valuenow'],
        'scrollbar' => ['aria-valuenow'],
        'slider' => ['aria-valuenow'],
    ];

    /** @var array<string, list<string>> state => roles that support it */
    public const SUPPORTED_STATES = [
        'aria-checked' => ['checkbox', 'menuitemcheckbox', 'menuitemradio', 'option', 'radio', 'switch', 'treeitem'],
        'aria-selected' => ['columnheader', 'gridcell', 'option', 'row', 'rowheader', 'tab', 'treeitem'],
        'aria-pressed' => ['button'],
        'aria-expanded' => [
            'application', 'button', 'checkbox', 'columnheader', 'combobox', 'gridcell', 'link', 'listbox',
            'menuitem', 'menuitemcheckbox', 'menuitemradio', 'row', 'rowheader', 'switch', 'tab', 'treeitem',
        ],
        'aria-valuenow' => ['meter', 'progressbar', 'scrollbar', 'separator', 'slider', 'spinbutton'],
    ];

    public const LIVE = ['alert', 'log', 'marquee', 'status'];

    public const ROVING = ['gridcell', 'menuitem', 'menuitemcheckbox', 'menuitemradio', 'option', 'radio', 'row', 'tab', 'treeitem'];

    private const SECTIONING = ['article', 'aside', 'main', 'nav', 'section'];

    public static function isValid(string $role): bool
    {
        return in_array(strtolower($role), self::VALID, true);
    }

    public static function isAbstract(string $role): bool
    {
        return in_array(strtolower($role), self::ABSTRACT, true);
    }

    /** First token of the role attribute that is a valid concrete role, or null. */
    public static function effective(DOMElement $element): ?string
    {
        foreach (Element::roles($element) as $token) {
            if (self::isValid($token)) {
                return $token;
            }
        }

        return null;
    }

    /** Explicit (effective) role, falling back to the implicit role of the element. */
    public static function of(DOMElement $element): ?string
    {
        return self::effective($element) ?? self::implicit($element);
    }

    public static function implicit(DOMElement $element): ?string
    {
        $tag = Element::tag($element);

        return match ($tag) {
            'main' => 'main',
            'nav' => 'navigation',
            'aside' => 'complementary',
            'article' => 'article',
            'header' => self::insideSectioning($element) ? null : 'banner',
            'footer' => self::insideSectioning($element) ? null : 'contentinfo',
            'section' => self::hasName($element) ? 'region' : null,
            'form' => self::hasName($element) ? 'form' : null,
            'a', 'area' => $element->hasAttribute('href') ? 'link' : null,
            'button' => 'button',
            'img' => $element->hasAttribute('alt') && trim($element->getAttribute('alt')) === '' ? 'presentation' : 'img',
            'output' => 'status',
            'dialog' => 'dialog',
            'table' => 'table',
            'ul', 'ol', 'menu' => 'list',
            'li' => 'listitem',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => 'heading',
            'hr' => 'separator',
            'progress' => 'progressbar',
            'meter' => 'meter',
            'option' => 'option',
            'tr' => 'row',
            'td' => 'cell',
            'th' => Element::enumAttr($element, 'scope') === 'row' ? 'rowheader' : 'columnheader',
            'textarea' => 'textbox',
            'fieldset', 'details' => 'group',
            'select' => ($element->hasAttribute('multiple') || (int) $element->getAttribute('size') > 1) ? 'listbox' : 'combobox',
            'input' => self::implicitInputRole($element),
            default => null,
        };
    }

    /** @return list<string> */
    public static function requiredStates(string $role): array
    {
        return self::REQUIRED_STATES[strtolower($role)] ?? [];
    }

    public static function supportsState(string $role, string $state): bool
    {
        return in_array(strtolower($role), self::SUPPORTED_STATES[strtolower($state)] ?? [], true);
    }

    public static function isLive(string $role): bool
    {
        return in_array(strtolower($role), self::LIVE, true);
    }

    /** Whether tabindex="-1" on this element is part of a roving-tabindex widget. */
    public static function isRoving(DOMElement $element): bool
    {
        $role = self::of($element);

        if ($role !== null && in_array($role, self::ROVING, true)) {
            return true;
        }

        for ($node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            if (in_array(self::effective($node), ['toolbar', 'menubar', 'tablist', 'menu', 'listbox', 'tree', 'grid', 'radiogroup'], true)) {
                return true;
            }
        }

        return false;
    }

    private static function implicitInputRole(DOMElement $element): ?string
    {
        $type = Element::enumAttr($element, 'type') ?: 'text';
        $hasList = $element->hasAttribute('list');

        return match ($type) {
            'button', 'image', 'reset', 'submit' => 'button',
            'checkbox' => 'checkbox',
            'radio' => 'radio',
            'range' => 'slider',
            'number' => 'spinbutton',
            'search' => $hasList ? 'combobox' : 'searchbox',
            'email', 'tel', 'text', 'url' => $hasList ? 'combobox' : 'textbox',
            'hidden' => null,
            default => 'textbox',
        };
    }

    private static function insideSectioning(DOMElement $element): bool
    {
        for ($node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            if (in_array(Element::tag($node), self::SECTIONING, true)) {
                return true;
            }
        }

        return false;
    }

    private static function hasName(DOMElement $element): bool
    {
        return trim($element->getAttribute('aria-label')) !== '' || trim($element->getAttribute('aria-labelledby')) !== '';
    }
}
