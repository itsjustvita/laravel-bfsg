<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMElement;
use ItsJustVita\LaravelBfsg\Severity;

class SemanticHTMLAnalyzer extends BaseAnalyzer
{
    /** Above this share of <div> elements the markup is reported as unspecific. */
    private const MAX_DIV_RATIO = 0.4;

    /**
     * Classes that suggest a list is populated dynamically (JS widgets).
     * When a <ul>/<ol> has one of these, empty-list checks are skipped.
     */
    protected const DYNAMIC_LIST_CLASS_HINTS = [
        'carousel',
        'swiper',
        'slider',
        'slick',
        'owl',
        'menu',
        'dropdown',
        'tabs',
        'nav',
        'pagination',
        'tree',
    ];

    /**
     * Roles that indicate a list is populated/controlled by JS.
     */
    protected const DYNAMIC_LIST_ROLES = [
        'list',
        'listbox',
        'menu',
        'tablist',
        'menubar',
        'navigation',
        'tree',
    ];

    protected string $key = 'semantic';

    protected string $description = 'Landmarks and document structure';

    protected array $rules = ['1.3.1', '2.4.1', '2.4.6'];

    protected function inspect(): void
    {
        $this->checkLandmarks();
        $this->checkSections();
        $this->checkDivRatio();
        $this->checkButtonLinkMisuse();
        $this->checkListUsage();
    }

    /** main, nav, header and footer landmarks. */
    protected function checkLandmarks(): void
    {
        $mains = $this->query('//main');

        if ($mains === []) {
            $this->report('missing_main', Severity::Warning, '1.3.1');
        }

        foreach (array_slice($mains, 1) as $position => $main) {
            $this->report('multiple_main', Severity::Error, '1.3.1', $main, ['index' => $position + 2]);
        }

        if ($this->query('//nav') === []) {
            $this->report('missing_nav', Severity::Notice, '1.3.1');
        }

        if ($this->query('//header') === []) {
            $this->report('missing_header', Severity::Notice, '1.3.1');
        }

        if ($this->query('//footer') === []) {
            $this->report('missing_footer', Severity::Notice, '1.3.1');
        }
    }

    /** Every section needs a heading or an accessible name. */
    protected function checkSections(): void
    {
        foreach ($this->query('//section') as $section) {
            if ($this->query('.//h1|.//h2|.//h3|.//h4|.//h5|.//h6', $section) !== []) {
                continue;
            }

            if (trim($section->getAttribute('aria-label')) !== '' || trim($section->getAttribute('aria-labelledby')) !== '') {
                continue;
            }

            $this->report('section_without_heading', Severity::Warning, '2.4.6', $section);
        }
    }

    /** Div-itis: generic containers instead of semantic elements. */
    protected function checkDivRatio(): void
    {
        $total = count($this->query('//*'));

        if ($total === 0) {
            return;
        }

        $ratio = count($this->query('//div')) / $total;

        if ($ratio > self::MAX_DIV_RATIO) {
            $this->report('div_ratio', Severity::Notice, '1.3.1', null, ['ratio' => (int) round($ratio * 100)]);
        }
    }

    /** Buttons that navigate and links that act as buttons. */
    protected function checkButtonLinkMisuse(): void
    {
        foreach ($this->query('//button[@href]') as $button) {
            if ($button->getAttribute('href') !== '') {
                $this->report('button_with_href', Severity::Error, '1.3.1', $button);
            }
        }

        foreach ($this->query('//a[@role="button"]') as $link) {
            $href = $link->getAttribute('href');

            if ($href === '' || $href === '#') {
                $this->report('anchor_as_button', Severity::Warning, '1.3.1', $link, related: ['4.1.2']);
            }
        }
    }

    /** Lists without list items — unless they look JS-populated. */
    protected function checkListUsage(): void
    {
        foreach ($this->query('//ul | //ol') as $list) {
            if ($this->query('./li', $list) !== []) {
                continue;
            }

            if ($this->isLikelyDynamicList($list)) {
                continue;
            }

            $this->report('empty_list', Severity::Notice, '1.3.1', $list, ['tag' => $list->nodeName]);
        }
    }

    /**
     * Heuristic: is this list likely populated/controlled by JavaScript?
     * Avoids false positives on carousels, menus, dropdowns, tabs, etc.
     */
    protected function isLikelyDynamicList(DOMElement $list): bool
    {
        // 1. Class hint (carousel, swiper, menu, dropdown, …)
        $class = strtolower($list->getAttribute('class'));
        if ($class !== '') {
            foreach (self::DYNAMIC_LIST_CLASS_HINTS as $hint) {
                if (str_contains($class, $hint)) {
                    return true;
                }
            }
        }

        // 2. Any data-* attribute → likely driven by JS
        if ($list->hasAttributes()) {
            foreach ($list->attributes as $attr) {
                if (str_starts_with($attr->nodeName, 'data-')) {
                    return true;
                }
            }
        }

        // 3. Role hint (listbox, menu, tablist, …)
        $role = strtolower($list->getAttribute('role'));

        return $role !== '' && in_array($role, self::DYNAMIC_LIST_ROLES, true);
    }
}
