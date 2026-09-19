<?php

namespace ItsJustVita\LaravelBfsg\Dom;

use DOMElement;
use DOMNode;
use DOMText;

/**
 * Subset of the accessible-name computation: aria-labelledby → aria-label → native → title.
 */
final class AccessibleName
{
    public static function of(DOMElement $element, HtmlDocument $document): string
    {
        $labelledby = trim($element->getAttribute('aria-labelledby'));

        if ($labelledby !== '') {
            $parts = [];

            foreach (preg_split('/\s+/', $labelledby, -1, PREG_SPLIT_NO_EMPTY) as $id) {
                $reference = $document->elementsById()[$id] ?? null;

                if ($reference !== null) {
                    $parts[] = $reference === $element ? self::native($element, $document) : self::content($reference, true);
                }
            }

            $name = Text::normalize(implode(' ', $parts));

            if ($name !== '') {
                return $name;
            }
        }

        $label = Text::normalize($element->getAttribute('aria-label'));

        if ($label !== '') {
            return $label;
        }

        $native = self::native($element, $document);

        if ($native !== '') {
            return $native;
        }

        return Text::normalize($element->getAttribute('title'));
    }

    private static function native(DOMElement $element, HtmlDocument $document): string
    {
        switch (Element::tag($element)) {
            case 'img':
            case 'area':
                return Text::normalize($element->getAttribute('alt'));

            case 'input':
                $type = Element::enumAttr($element, 'type') ?: 'text';

                if ($type === 'image') {
                    return Text::normalize($element->getAttribute('alt')) ?: Text::normalize($element->getAttribute('value'));
                }

                if (in_array($type, ['submit', 'reset', 'button'], true)) {
                    return Text::normalize($element->getAttribute('value'));
                }

                return self::fromLabel($element, $document);

            case 'select':
            case 'textarea':
                return self::fromLabel($element, $document);

            case 'svg':
                return self::childText($element, 'title');

            case 'table':
                return self::childText($element, 'caption');

            case 'figure':
                return self::childText($element, 'figcaption');

            case 'fieldset':
                return self::childText($element, 'legend');

            default:
                return self::content($element, false);
        }
    }

    private static function fromLabel(DOMElement $element, HtmlDocument $document): string
    {
        $id = trim($element->getAttribute('id'));

        if ($id !== '') {
            foreach ($document->query('//label[@for='.$document->xpathLiteral($id).']') as $label) {
                $name = self::content($label, false, $element);

                if ($name !== '') {
                    return $name;
                }
            }
        }

        $ancestor = Element::closest($element, 'label');

        return $ancestor === null ? '' : self::content($ancestor, false, $element);
    }

    private static function childText(DOMElement $element, string $tag): string
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && Element::tag($child) === $tag) {
                return self::content($child, false);
            }
        }

        return '';
    }

    /**
     * Text of a subtree as assistive technology would read it: text nodes, alt of images,
     * svg titles, aria-label of descendants; aria-hidden subtrees are skipped unless the
     * subtree was reached through aria-labelledby.
     */
    private static function content(DOMNode $node, bool $includeHidden, ?DOMElement $skip = null): string
    {
        $text = '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $text .= $child->wholeText.' ';

                continue;
            }

            if (! $child instanceof DOMElement || $child === $skip) {
                continue;
            }

            if (! $includeHidden && Element::enumAttr($child, 'aria-hidden') === 'true') {
                continue;
            }

            $label = Text::normalize($child->getAttribute('aria-label'));

            if ($label !== '') {
                $text .= $label.' ';

                continue;
            }

            $text .= match (Element::tag($child)) {
                'img', 'area' => Text::normalize($child->getAttribute('alt')),
                'svg' => self::childText($child, 'title'),
                'input' => in_array(Element::enumAttr($child, 'type'), ['submit', 'reset', 'button'], true) ? Text::normalize($child->getAttribute('value')) : '',
                'script', 'style', 'template', 'noscript' => '',
                default => self::content($child, $includeHidden, $skip),
            };

            $text .= ' ';
        }

        return Text::normalize($text);
    }
}
