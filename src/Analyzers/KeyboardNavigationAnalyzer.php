<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMAttr;
use DOMElement;
use ItsJustVita\LaravelBfsg\Dom\Element;
use ItsJustVita\LaravelBfsg\Dom\Roles;
use ItsJustVita\LaravelBfsg\Dom\Text;
use ItsJustVita\LaravelBfsg\Severity;

class KeyboardNavigationAnalyzer extends BaseAnalyzer
{
    /** Whole-word (or whole-phrase) skip link patterns, English + German. */
    public const SKIP_LINK_PATTERNS = [
        'skip', 'jump to', 'überspringen', 'zum inhalt', 'zum hauptinhalt', 'zur navigation', 'zum menü', 'direkt zu', 'direkt zum',
    ];

    /** Elements that handle clicks natively or whose click is delegated by the browser. */
    private const CLICK_EXEMPT_TAGS = ['a', 'summary', 'label', 'details', 'area', 'option', 'html', 'body'];

    private const WIDGET_ROLES = ['button', 'link', 'checkbox', 'radio', 'switch', 'tab', 'menuitem', 'option', 'slider', 'textbox', 'combobox'];

    private const KEY_HANDLERS = ['onkeydown', 'onkeyup', 'onkeypress'];

    private const MOUSE_QUERY = '//*[@onmouseover or @onmouseout or @onmousedown or @onmouseup or @onmouseenter or @onmouseleave]';

    private const KEYBOARD_EQUIVALENTS = ['onkeydown', 'onkeyup', 'onkeypress', 'onfocus', 'onblur', 'onfocusin', 'onfocusout'];

    private const INTERACTIVE_DESCENDANTS = './/a[@href]|.//button|.//input|.//select|.//textarea|.//*[@tabindex]';

    protected string $key = 'keyboard';

    protected string $description = 'Keyboard operability, focus order and bypass blocks';

    protected array $rules = ['2.1.1', '2.4.1', '2.4.3', '4.1.2'];

    protected function inspect(): void
    {
        $this->checkSkipLinks();
        $this->checkTabindex();
        $this->checkDialogs();
        $this->checkClickHandlers();
        $this->checkWidgetRoles();
        $this->checkAnchors();
        $this->checkMouseHandlers();
    }

    protected function checkSkipLinks(): void
    {
        if ($this->isFragment()) {
            return;
        }

        $found = false;

        foreach ($this->query('(//a[@href])[position() <= 3]') as $link) {
            $href = trim($link->getAttribute('href'));

            if (! str_starts_with($href, '#') || ! Text::containsAnyWord($this->name($link), self::SKIP_LINK_PATTERNS)) {
                continue;
            }

            $found = true;

            if (! $this->targetExists(rawurldecode(substr($href, 1)))) {
                $this->report('skip_link_target_missing', Severity::Error, '2.4.1', $link, ['href' => $href]);
            }
        }

        if (! $found && ! $this->hasMainLandmark()) {
            $this->report('missing_skip_link', Severity::Notice, '2.4.1');
        }
    }

    protected function checkTabindex(): void
    {
        foreach ($this->queryVisible('//*[@tabindex]') as $element) {
            $tabindex = Element::tabindex($element);

            if ($tabindex !== null && $tabindex > 0) {
                $this->report('positive_tabindex', Severity::Warning, '2.4.3', $element, ['value' => $tabindex]);

                continue;
            }

            if ($tabindex === -1
                && Element::isNativelyInteractive($element)
                && Element::tag($element) !== 'iframe'
                && ! Element::isDisabled($element)
                && ! Roles::isRoving($element)) {
                $this->report('negative_tabindex_on_interactive', Severity::Notice, '2.1.1', $element, ['tag' => Element::tag($element)]);
            }
        }
    }

    protected function checkDialogs(): void
    {
        foreach ($this->queryVisible('//dialog|//*[@role]') as $element) {
            $isElement = Element::tag($element) === 'dialog';
            $role = Roles::effective($element);

            if (! $isElement && ! in_array($role, ['dialog', 'alertdialog'], true)) {
                continue;
            }

            if ($this->authoredName($element) === '') {
                $this->report('dialog_missing_name', Severity::Error, '4.1.2', $element);
            }

            if (! $isElement && Element::enumAttr($element, 'aria-modal') !== 'true') {
                $this->report('dialog_missing_aria_modal', Severity::Warning, '4.1.2', $element);
            }
        }
    }

    protected function checkClickHandlers(): void
    {
        foreach ($this->queryVisible('//*[@onclick]') as $element) {
            $tag = Element::tag($element);

            if (in_array($tag, self::CLICK_EXEMPT_TAGS, true) || Element::isNativelyInteractive($element)) {
                continue;
            }

            $tabindex = Element::tabindex($element);
            $focusable = $tabindex !== null && $tabindex >= 0;

            if ($focusable && (in_array(Roles::effective($element), ['button', 'link'], true) || $this->hasAny($element, self::KEY_HANDLERS))) {
                continue;
            }

            $delegates = $this->query(self::INTERACTIVE_DESCENDANTS, $element) !== [];
            $this->report('click_without_keyboard', $delegates ? Severity::Warning : Severity::Error, '2.1.1', $element, ['tag' => $tag]);
        }
    }

    protected function checkWidgetRoles(): void
    {
        foreach ($this->queryVisible('//*[@role]') as $element) {
            $role = Roles::effective($element);

            if (! in_array($role, self::WIDGET_ROLES, true)
                || $element->hasAttribute('tabindex')
                || $element->hasAttribute('onclick')
                || Element::isNativelyInteractive($element)
                || Element::tag($element) === 'a'
                || Element::enumAttr($element, 'aria-disabled') === 'true'
                || Roles::isRoving($element)
                || $this->query('ancestor::*[@aria-activedescendant]', $element) !== []) {
                continue;
            }

            $this->report('role_without_tabindex', Severity::Warning, '2.1.1', $element, ['role' => $role, 'tag' => Element::tag($element)]);
        }
    }

    protected function checkAnchors(): void
    {
        foreach ($this->queryVisible('//a[not(@href)]') as $anchor) {
            $interactive = $this->hasEventHandler($anchor)
                || in_array(Roles::effective($anchor), ['button', 'link'], true)
                || $anchor->hasAttribute('tabindex');

            if (! $interactive || Element::isFocusable($anchor) || Roles::isRoving($anchor)) {
                continue;
            }

            $this->report('anchor_not_focusable', Severity::Warning, '2.1.1', $anchor);
        }
    }

    protected function checkMouseHandlers(): void
    {
        foreach ($this->queryVisible(self::MOUSE_QUERY) as $element) {
            if (Element::isNativelyInteractive($element) || $this->hasAny($element, self::KEYBOARD_EQUIVALENTS)) {
                continue;
            }

            $this->report('mouse_only_handler', Severity::Notice, '2.1.1', $element, ['tag' => Element::tag($element)]);
        }
    }

    protected function hasMainLandmark(): bool
    {
        foreach ($this->query('//main|//*[@role]') as $element) {
            if (Roles::of($element) === 'main') {
                return true;
            }
        }

        return false;
    }

    protected function targetExists(string $id): bool
    {
        if ($id === '') {
            return false;
        }

        return isset($this->document->elementsById()[$id])
            || $this->query('//a[@name='.$this->document->xpathLiteral($id).']') !== [];
    }

    /** @param  list<string>  $attributes */
    protected function hasAny(DOMElement $element, array $attributes): bool
    {
        foreach ($attributes as $attribute) {
            if ($element->hasAttribute($attribute)) {
                return true;
            }
        }

        return false;
    }

    protected function hasEventHandler(DOMElement $element): bool
    {
        foreach ($element->attributes as $attribute) {
            if ($attribute instanceof DOMAttr && str_starts_with(strtolower($attribute->nodeName), 'on')) {
                return true;
            }
        }

        return false;
    }
}
