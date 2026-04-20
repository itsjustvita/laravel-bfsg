<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

class SemanticHTMLAnalyzer
{
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

    /**
     * Analyze semantic HTML structure
     */
    public function analyze(\DOMDocument $dom): array
    {
        $issues = [];
        $xpath = new \DOMXPath($dom);

        // Check for main landmark
        $mains = $dom->getElementsByTagName('main');
        if ($mains->length === 0) {
            $issues[] = [
                'rule' => 'WCAG 1.3.1',
                'message' => 'No <main> landmark found',
                'element' => 'main',
                'suggestion' => 'Add a <main> element to identify the primary content',
                'type' => 'warning',
            ];
        } elseif ($mains->length > 1) {
            $issues[] = [
                'rule' => 'WCAG 1.3.1',
                'message' => 'Multiple <main> elements found',
                'element' => 'main',
                'suggestion' => 'Use only one <main> element per page',
                'type' => 'error',
            ];
        }

        // Check for nav landmark
        $navs = $dom->getElementsByTagName('nav');
        if ($navs->length === 0) {
            $issues[] = [
                'rule' => 'WCAG 1.3.1',
                'message' => 'No <nav> landmark found',
                'element' => 'nav',
                'suggestion' => 'Use <nav> element to identify navigation regions',
                'type' => 'notice',
            ];
        }

        // Check for article/section usage
        $articles = $dom->getElementsByTagName('article');
        $sections = $dom->getElementsByTagName('section');

        // Sections without headings
        foreach ($sections as $section) {
            $headings = $xpath->query('.//h1|.//h2|.//h3|.//h4|.//h5|.//h6', $section);
            if ($headings->length === 0) {
                $ariaLabel = $section->getAttribute('aria-label');
                $ariaLabelledby = $section->getAttribute('aria-labelledby');

                if (empty($ariaLabel) && empty($ariaLabelledby)) {
                    $issues[] = [
                        'rule' => 'WCAG 2.4.6',
                        'message' => '<section> element without heading or aria-label',
                        'element' => 'section',
                        'suggestion' => 'Add a heading or aria-label to <section> for screen reader navigation',
                        'type' => 'warning',
                    ];
                }
            }
        }

        // Check for div-itis (excessive div usage instead of semantic elements)
        $divs = $dom->getElementsByTagName('div');
        $totalElements = $dom->getElementsByTagName('*')->length;
        if ($totalElements > 0) {
            $divRatio = $divs->length / $totalElements;
            if ($divRatio > 0.4) {
                $issues[] = [
                    'rule' => 'WCAG 1.3.1',
                    'message' => sprintf('Excessive use of <div> elements (%.0f%% of all elements)', $divRatio * 100),
                    'element' => 'div',
                    'suggestion' => 'Consider using semantic HTML5 elements like <article>, <section>, <nav>, <aside>, <header>, <footer>',
                    'type' => 'notice',
                ];
            }
        }

        // Check for button vs link misuse
        $buttons = $dom->getElementsByTagName('button');
        foreach ($buttons as $button) {
            $href = $button->getAttribute('href');
            if (! empty($href)) {
                $issues[] = [
                    'rule' => 'WCAG 1.3.1',
                    'message' => '<button> with href attribute found',
                    'element' => 'button',
                    'suggestion' => 'Use <a> for navigation, <button> for actions',
                    'type' => 'error',
                ];
            }
        }

        // Check for links used as buttons
        $links = $dom->getElementsByTagName('a');
        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            $role = $link->getAttribute('role');

            if ($role === 'button' && (empty($href) || $href === '#')) {
                $issues[] = [
                    'rule' => 'WCAG 1.3.1, 4.1.2',
                    'message' => '<a> element used as button (role="button")',
                    'element' => 'a',
                    'suggestion' => 'Use <button> element instead of <a role="button">',
                    'type' => 'warning',
                ];
            }
        }

        // Check for lists
        $this->checkListUsage($dom, $issues);

        // Check for header and footer
        $headers = $dom->getElementsByTagName('header');
        $footers = $dom->getElementsByTagName('footer');

        if ($headers->length === 0) {
            $issues[] = [
                'rule' => 'WCAG 1.3.1',
                'message' => 'No <header> landmark found',
                'element' => 'header',
                'suggestion' => 'Use <header> element to identify page or section headers',
                'type' => 'notice',
            ];
        }

        if ($footers->length === 0) {
            $issues[] = [
                'rule' => 'WCAG 1.3.1',
                'message' => 'No <footer> landmark found',
                'element' => 'footer',
                'suggestion' => 'Use <footer> element to identify page or section footers',
                'type' => 'notice',
            ];
        }

        // Check for aside usage
        $asides = $dom->getElementsByTagName('aside');
        // This is just informational, not necessarily an issue

        return [
            'issues' => $issues,
            'stats' => [
                'total_issues' => count($issues),
                'main_count' => $mains->length,
                'nav_count' => $navs->length,
                'article_count' => $articles->length,
                'section_count' => $sections->length,
                'aside_count' => $asides->length,
                'header_count' => $headers->length,
                'footer_count' => $footers->length,
            ],
        ];
    }

    /**
     * Check for proper list usage
     */
    protected function checkListUsage(\DOMDocument $dom, array &$issues): void
    {
        $xpath = new \DOMXPath($dom);

        // Find potential lists (multiple consecutive similar elements)
        // This is a simplified heuristic

        // Check for ul/ol without li
        $uls = $dom->getElementsByTagName('ul');
        foreach ($uls as $ul) {
            $lis = $xpath->query('./li', $ul);
            if ($lis->length === 0) {
                if ($this->isLikelyDynamicList($ul)) {
                    continue;
                }
                $issues[] = [
                    'rule' => 'WCAG 1.3.1',
                    'message' => '<ul> element without <li> children (may be JS-populated)',
                    'element' => 'ul',
                    'suggestion' => 'Only use <ul> when you have list items',
                    'type' => 'notice',
                ];
            }
        }

        $ols = $dom->getElementsByTagName('ol');
        foreach ($ols as $ol) {
            $lis = $xpath->query('./li', $ol);
            if ($lis->length === 0) {
                if ($this->isLikelyDynamicList($ol)) {
                    continue;
                }
                $issues[] = [
                    'rule' => 'WCAG 1.3.1',
                    'message' => '<ol> element without <li> children (may be JS-populated)',
                    'element' => 'ol',
                    'suggestion' => 'Only use <ol> when you have list items',
                    'type' => 'notice',
                ];
            }
        }
    }

    /**
     * Heuristic: is this list likely populated/controlled by JavaScript?
     * Avoids false positives on carousels, menus, dropdowns, tabs, etc.
     */
    protected function isLikelyDynamicList(\DOMElement $list): bool
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
        if ($role !== '' && in_array($role, self::DYNAMIC_LIST_ROLES, true)) {
            return true;
        }

        return false;
    }
}
