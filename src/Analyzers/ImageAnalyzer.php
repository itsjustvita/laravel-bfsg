<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMElement;
use ItsJustVita\LaravelBfsg\Dom\Element;
use ItsJustVita\LaravelBfsg\Dom\Roles;
use ItsJustVita\LaravelBfsg\Dom\Text;
use ItsJustVita\LaravelBfsg\Severity;

class ImageAnalyzer extends BaseAnalyzer
{
    private const FILENAME = '/\.(jpe?g|png|gif|svg|webp|avif)$/i';

    private const GENERIC_ALT = ['image', 'img', 'photo', 'picture', 'foto', 'bild', 'grafik', 'icon', 'spacer'];

    private const CONTROL_ROLES = ['button', 'link', 'menuitem', 'tab', 'option', 'checkbox', 'radio', 'switch'];

    protected string $key = 'images';

    protected string $description = 'Text alternatives for images';

    protected array $rules = ['1.1.1'];

    protected function inspect(): void
    {
        foreach ($this->queryVisible('//img') as $img) {
            $this->checkImage($img);
        }

        foreach ($this->queryVisible('//input[@type]') as $input) {
            if (Element::enumAttr($input, 'type') === 'image' && ! $input->hasAttribute('alt') && $this->authoredName($input) === '') {
                $this->report('missing_alt', Severity::Error, '1.1.1', $input, ['src' => $input->getAttribute('src')]);
            }
        }

        foreach ($this->queryVisible('//area[@href]') as $area) {
            if ($this->name($area) === '') {
                $this->report('area_missing_alt', Severity::Error, '1.1.1', $area, ['href' => $area->getAttribute('href')]);
            }
        }

        foreach ($this->queryVisible('//svg[not(ancestor::svg)]') as $svg) {
            $this->checkSvg($svg);
        }
    }

    protected function checkImage(DOMElement $img): void
    {
        $src = $img->getAttribute('src');

        if ($this->isPresentational($img)) {
            return;
        }

        if (! $img->hasAttribute('alt')) {
            if ($this->name($img) === '') {
                $this->report('missing_alt', Severity::Error, '1.1.1', $img, ['src' => $src]);
            }

            return;
        }

        $alt = $img->getAttribute('alt');

        if ($alt === '') {
            if (! $this->insideNamedControl($img) && ! $this->insideCaptionedFigure($img)) {
                $this->report('possibly_decorative', Severity::Notice, '1.1.1', $img, ['src' => $src]);
            }

            return;
        }

        $normalized = Text::lower(Text::normalize($alt));

        if ($normalized === '' || preg_match(self::FILENAME, $normalized) === 1 || in_array($normalized, self::GENERIC_ALT, true)) {
            $this->report('suspicious_alt', Severity::Notice, '1.1.1', $img, ['alt' => Text::truncate($alt, 50), 'src' => $src]);
        }
    }

    protected function checkSvg(DOMElement $svg): void
    {
        if ($this->isPresentational($svg) || $this->name($svg) !== '') {
            return;
        }

        $control = $this->closestControl($svg);

        if ($control !== null && $this->name($control) !== '') {
            return;
        }

        $this->report('svg_missing_name', Severity::Error, '1.1.1', $svg);
    }

    protected function isPresentational(DOMElement $element): bool
    {
        return in_array(Roles::effective($element), ['presentation', 'none'], true);
    }

    protected function insideNamedControl(DOMElement $element): bool
    {
        $control = $this->closestControl($element);

        return $control !== null && $this->name($control) !== '';
    }

    protected function insideCaptionedFigure(DOMElement $element): bool
    {
        $figure = Element::closest($element, 'figure');

        return $figure !== null && $this->query('./figcaption', $figure) !== [];
    }

    protected function closestControl(DOMElement $element): ?DOMElement
    {
        for ($node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            $tag = Element::tag($node);

            if (($tag === 'a' && $node->hasAttribute('href')) || $tag === 'button' || in_array(Roles::effective($node), self::CONTROL_ROLES, true)) {
                return $node;
            }
        }

        return null;
    }
}
