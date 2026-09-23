<?php

namespace ItsJustVita\LaravelBfsg\Dom;

use DOMElement;
use DOMNode;
use DOMText;

final class Element
{
    private const HIDDEN_CONTAINERS = ['template', 'noscript', 'head'];

    /** display:none / visibility:hidden as a whole declaration of an inline style attribute. */
    private const HIDDEN_STYLE = '/(?:^|;)\s*(?:display\s*:\s*none|visibility\s*:\s*hidden)\s*(?:!\s*important\s*)?(?:;|$)/';

    private const INTERACTIVE_TAGS = ['button', 'select', 'textarea', 'summary', 'iframe'];

    public static function tag(DOMElement $element): string
    {
        return strtolower($element->nodeName);
    }

    public static function describe(DOMElement $element): string
    {
        $description = self::tag($element);

        if (($id = trim($element->getAttribute('id'))) !== '') {
            $description .= '#'.$id;
        }

        foreach (array_slice(self::classTokens($element), 0, 2) as $class) {
            $description .= '.'.$class;
        }

        return $description;
    }

    /**
     * outerHTML, whitespace-collapsed and truncated to $max characters. Serialization stops once enough markup
     * exists for the snippet, so a finding on <html> or <body> does not serialize the whole page.
     */
    public static function snippet(DOMElement $element, int $max = 120): string
    {
        return Text::truncate(Text::normalize(self::boundedHtml($element, $max * 4)), $max);
    }

    /** @param  string[]  $tokens  class tokens, compared case-insensitively */
    public static function hasAnyClassToken(DOMElement $element, array $tokens): bool
    {
        return array_intersect(array_map('strtolower', self::classTokens($element)), array_map('strtolower', $tokens)) !== [];
    }

    /** Text of the element's own text nodes only. */
    public static function ownText(DOMElement $element): string
    {
        $text = '';

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMText) {
                $text .= $child->wholeText.' ';
            }
        }

        return Text::normalize($text);
    }

    public static function text(DOMElement $element): string
    {
        return Text::normalize($element->textContent);
    }

    /**
     * Hidden from everyone: self or ancestor carries the hidden attribute, an inline display:none /
     * visibility:hidden declaration, or sits inside template/noscript/head.
     */
    public static function isNotRendered(DOMElement $element): bool
    {
        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
            if (in_array(self::tag($node), self::HIDDEN_CONTAINERS, true) || $node->hasAttribute('hidden')) {
                return true;
            }

            if (preg_match(self::HIDDEN_STYLE, strtolower($node->getAttribute('style'))) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Not rendered, or removed from the accessibility tree by aria-hidden="true" on self or an ancestor. */
    public static function isHidden(DOMElement $element): bool
    {
        if (self::isNotRendered($element)) {
            return true;
        }

        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
            if (self::enumAttr($node, 'aria-hidden') === 'true') {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> IDREF tokens of an attribute, split on any whitespace */
    public static function idrefs(DOMElement $element, string $attribute): array
    {
        return preg_split('/\s+/', trim($element->getAttribute($attribute)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** @return list<string> */
    public static function classTokens(DOMElement $element): array
    {
        return preg_split('/\s+/', trim($element->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    public static function hasClassToken(DOMElement $element, string $token): bool
    {
        return in_array($token, self::classTokens($element), true);
    }

    /** Enumerated attribute: trimmed and lower-cased, '' when absent. */
    public static function enumAttr(DOMElement $element, string $name): string
    {
        return strtolower(trim($element->getAttribute($name)));
    }

    public static function attr(DOMElement $element, string $name): ?string
    {
        return $element->hasAttribute($name) ? $element->getAttribute($name) : null;
    }

    /** @return list<string> role tokens as written, lower-cased */
    public static function roles(DOMElement $element): array
    {
        return preg_split('/\s+/', self::enumAttr($element, 'role'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    public static function tabindex(DOMElement $element): ?int
    {
        if (! $element->hasAttribute('tabindex')) {
            return null;
        }

        $value = trim($element->getAttribute('tabindex'));

        return preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : null;
    }

    public static function isDisabled(DOMElement $element): bool
    {
        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
            if ($node->hasAttribute('disabled') && in_array(self::tag($node), ['button', 'input', 'select', 'textarea', 'fieldset', 'optgroup', 'option'], true)) {
                return true;
            }
        }

        return false;
    }

    public static function isNativelyInteractive(DOMElement $element): bool
    {
        $tag = self::tag($element);

        return match (true) {
            in_array($tag, self::INTERACTIVE_TAGS, true) => true,
            $tag === 'a', $tag === 'area' => $element->hasAttribute('href'),
            $tag === 'input' => self::enumAttr($element, 'type') !== 'hidden',
            $tag === 'audio', $tag === 'video' => $element->hasAttribute('controls'),
            default => $element->hasAttribute('contenteditable') && self::enumAttr($element, 'contenteditable') !== 'false',
        };
    }

    public static function isFocusable(DOMElement $element): bool
    {
        $tabindex = self::tabindex($element);

        if ($tabindex !== null && $tabindex < 0) {
            return false;
        }

        if (self::isDisabled($element)) {
            return false;
        }

        return $tabindex !== null || self::isNativelyInteractive($element);
    }

    public static function previousElement(DOMElement $element): ?DOMElement
    {
        for ($node = $element->previousSibling; $node !== null; $node = $node->previousSibling) {
            if ($node instanceof DOMElement) {
                return $node;
            }
        }

        return null;
    }

    public static function closest(DOMElement $element, string $tag): ?DOMElement
    {
        for ($node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            if (self::tag($node) === strtolower($tag)) {
                return $node;
            }
        }

        return null;
    }

    /** Serialize a node like saveHTML(), but stop descending once $budget bytes are written (unclosed then). */
    private static function boundedHtml(DOMNode $node, int $budget): string
    {
        $document = $node->ownerDocument;

        if ($document === null) {
            return '';
        }

        if (! $node instanceof DOMElement || ! $node->hasChildNodes()) {
            return $document->saveHTML($node) ?: '';
        }

        $shell = $document->saveHTML($node->cloneNode(false)) ?: '';
        $close = '</'.$node->nodeName.'>';
        $html = str_ends_with($shell, $close) ? substr($shell, 0, -strlen($close)) : $shell;

        foreach ($node->childNodes as $child) {
            if (strlen($html) >= $budget) {
                return $html;
            }

            $html .= self::boundedHtml($child, $budget - strlen($html));
        }

        return $html.$close;
    }

    public static function isElement(?DOMNode $node): bool
    {
        return $node instanceof DOMElement;
    }
}
