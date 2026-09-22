<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMAttr;
use DOMElement;
use ItsJustVita\LaravelBfsg\Dom\Element;
use ItsJustVita\LaravelBfsg\Dom\Roles;
use ItsJustVita\LaravelBfsg\Severity;

class SemanticHTMLAnalyzer extends BaseAnalyzer
{
    /** Class tokens (or token prefixes followed by - or _) that indicate a list populated by JavaScript. */
    public const DYNAMIC_LIST_CLASS_HINTS = [
        'carousel', 'swiper', 'slider', 'slick', 'owl', 'menu', 'dropdown', 'tabs', 'nav', 'pagination', 'tree', 'splide', 'glide',
    ];

    protected string $key = 'semantic';

    protected string $description = 'Landmarks and document structure';

    protected array $rules = ['1.3.1', '2.4.1', '4.1.2'];

    protected function inspect(): void
    {
        $this->checkMain();
        $this->checkSections();
        $this->checkLists();
        $this->checkButtons();
    }

    protected function checkMain(): void
    {
        $mains = array_values(array_filter($this->query('//main|//*[@role]'), fn (DOMElement $element) => Roles::of($element) === 'main'));

        if ($mains === [] && ! $this->isFragment()) {
            $this->report('missing_main', Severity::Warning, '2.4.1');
        }

        $visible = array_values(array_filter($mains, fn (DOMElement $main) => ! $this->isHidden($main)));

        foreach (array_slice($visible, 1) as $position => $main) {
            $this->report('multiple_main', Severity::Error, '1.3.1', $main, ['index' => $position + 2]);
        }
    }

    protected function checkSections(): void
    {
        foreach ($this->queryVisible('//section') as $section) {
            if (trim($section->getAttribute('aria-label')) !== '' || trim($section->getAttribute('aria-labelledby')) !== '') {
                continue;
            }

            $headings = array_filter(
                $this->query('.//h1|.//h2|.//h3|.//h4|.//h5|.//h6|.//*[@role]', $section),
                fn (DOMElement $element) => Roles::of($element) === 'heading',
            );

            if ($headings !== []) {
                continue;
            }

            $this->report('section_without_heading', Severity::Notice, '1.3.1', $section);
        }
    }

    protected function checkLists(): void
    {
        foreach ($this->queryVisible('//ul|//ol') as $list) {
            if ($this->query('./li', $list) !== [] || $this->isLikelyDynamic($list)) {
                continue;
            }

            $this->report('empty_list', Severity::Notice, '1.3.1', $list, ['tag' => Element::tag($list)]);
        }
    }

    protected function checkButtons(): void
    {
        foreach ($this->queryVisible('//button[@href]') as $button) {
            if (trim($button->getAttribute('href')) !== '') {
                $this->report('button_with_href', Severity::Notice, '4.1.2', $button, tags: ['best-practice']);
            }
        }
    }

    /** A class token (or token prefix), any data-* attribute, or any role marks a list as script-populated. */
    protected function isLikelyDynamic(DOMElement $list): bool
    {
        foreach (Element::classTokens($list) as $token) {
            $token = strtolower($token);

            foreach (self::DYNAMIC_LIST_CLASS_HINTS as $hint) {
                if ($token === $hint || str_starts_with($token, $hint.'-') || str_starts_with($token, $hint.'_')) {
                    return true;
                }
            }
        }

        foreach ($list->attributes as $attribute) {
            if ($attribute instanceof DOMAttr && str_starts_with(strtolower($attribute->nodeName), 'data-')) {
                return true;
            }
        }

        return trim($list->getAttribute('role')) !== '';
    }
}
