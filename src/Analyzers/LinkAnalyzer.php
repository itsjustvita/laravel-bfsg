<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMElement;
use ItsJustVita\LaravelBfsg\Dom\Element;
use ItsJustVita\LaravelBfsg\Dom\Roles;
use ItsJustVita\LaravelBfsg\Dom\Text;
use ItsJustVita\LaravelBfsg\Severity;

class LinkAnalyzer extends BaseAnalyzer
{
    private const MAX_TEXT = 50;

    /** Link names that do not describe the destination (English + German), compared after normalization. */
    public const GENERIC_NAMES = [
        'click here', 'here', 'read more', 'more', 'link', 'click', 'go', 'start', 'download', 'learn more',
        'continue', 'see more', 'view more', 'details', 'more info', 'info', 'this link',
        'hier klicken', 'hier', 'klicken', 'mehr', 'mehr erfahren', 'mehr lesen', 'mehr infos', 'weiterlesen',
        'weiter', 'lesen', 'jetzt', 'los', 'herunterladen', 'dieser link',
    ];

    /** Longest link text still taken as a language name on a link with hreflang/lang ("Nederlands", "Português"). */
    private const MAX_LANGUAGE_NAME = 12;

    private const NEW_WINDOW_HINTS = [
        'new window', 'new tab', 'opens in', 'external', 'neues fenster', 'neuem fenster', 'neuer tab',
        'neuen tab', 'neuem tab', 'öffnet in', 'extern',
    ];

    private const DOCUMENT_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'csv', 'zip'];

    private const DOWNLOAD_HINTS = ['download', 'pdf', 'datei', 'herunterladen', 'dokument'];

    private const CONTEXT_CONTAINERS = ['li', 'p', 'td', 'dd'];

    private const HEADINGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    protected string $key = 'links';

    protected string $description = 'Link purpose and link behaviour';

    protected array $rules = ['2.4.4', '4.1.2', '3.2.5'];

    protected function inspect(): void
    {
        foreach ($this->queryVisible('//a[@href]') as $link) {
            $this->checkLink($link);
        }
    }

    protected function checkLink(DOMElement $link): void
    {
        $href = $link->getAttribute('href');
        $name = $this->name($link);

        if ($name === '') {
            $this->report('missing_name', Severity::Error, '2.4.4', $link, ['href' => $href], related: ['4.1.2']);
        } else {
            $this->checkPurpose($link, $name, $href);
        }

        $this->checkNewWindow($link, $name, $href);
        $this->checkDownload($link, $name, $href);
        $this->checkAdjacentDuplicate($link, $name, $href);
        $this->checkPseudoLink($link, $href);
    }

    protected function checkPurpose(DOMElement $link, string $name, string $href): void
    {
        $authored = $this->authoredName($link);
        $subject = $authored !== '' ? $authored : $name;
        $normalized = Text::lower(Text::stripTrailingPunctuation($subject));

        // Language switchers ("DE", "EN", "Deutsch") declare their purpose through hreflang/lang; generic text does not.
        if (($link->hasAttribute('hreflang') || $link->hasAttribute('lang'))
            && Text::length($normalized) <= self::MAX_LANGUAGE_NAME && ! in_array($normalized, self::GENERIC_NAMES, true)) {
            return;
        }

        if (in_array($normalized, self::GENERIC_NAMES, true) || Text::length($normalized) < 3) {
            $params = ['text' => Text::truncate($subject, self::MAX_TEXT), 'href' => $href];

            if ($this->hasContext($link) || ($this->isPageNumber($normalized) && $this->insideNavigation($link))) {
                $this->report('non_descriptive_in_context', Severity::Notice, '2.4.4', $link, $params);
            } else {
                $this->report('non_descriptive', Severity::Warning, '2.4.4', $link, $params);
            }

            return;
        }

        if ($this->looksLikeUrl($name)) {
            $this->report('url_as_text', Severity::Notice, '2.4.4', $link, ['text' => Text::truncate($name, self::MAX_TEXT), 'href' => $href]);
        }
    }

    /** Programmatically determinable context (WCAG techniques H77–H81): surrounding sentence, list item, cell, or a preceding heading. */
    protected function hasContext(DOMElement $link): bool
    {
        for ($node = $link->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            if (in_array(Element::tag($node), self::CONTEXT_CONTAINERS, true)) {
                return Element::text($node) !== Element::text($link);
            }
        }

        $previous = Element::previousElement($link);

        return $previous !== null && in_array(Element::tag($previous), self::HEADINGS, true);
    }

    /** Pagination: a link named only by a number. */
    protected function isPageNumber(string $name): bool
    {
        return preg_match('/^\d+$/', $name) === 1;
    }

    protected function insideNavigation(DOMElement $link): bool
    {
        for ($node = $link->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            if (Roles::of($node) === 'navigation') {
                return true;
            }
        }

        return false;
    }

    protected function checkNewWindow(DOMElement $link, string $name, string $href): void
    {
        if (Element::enumAttr($link, 'target') !== '_blank') {
            return;
        }

        $announced = Text::lower($name.' '.$link->getAttribute('title'));

        if (! $this->containsAny($announced, self::NEW_WINDOW_HINTS)) {
            $this->report('new_window_unannounced', Severity::Notice, '3.2.5', $link, ['href' => $href], tags: ['aaa']);
        }

        $rel = preg_split('/\s+/', Element::enumAttr($link, 'rel'), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($this->isExternal($href) && array_intersect($rel, ['noopener', 'noreferrer']) === []) {
            $this->report('missing_noopener', Severity::Notice, null, $link, ['href' => $href], tags: ['security'], autoFixable: true);
        }
    }

    protected function checkDownload(DOMElement $link, string $name, string $href): void
    {
        $path = strtolower((string) preg_replace('/[?#].*$/', '', $href));
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if (! in_array($extension, self::DOCUMENT_EXTENSIONS, true) || $link->hasAttribute('download')) {
            return;
        }

        if (! Text::containsAnyWord($name.' '.$link->getAttribute('title'), [...self::DOWNLOAD_HINTS, $extension])) {
            $this->report('download_unannounced', Severity::Notice, '2.4.4', $link, ['href' => $href, 'type' => strtoupper($extension)]);
        }
    }

    protected function checkAdjacentDuplicate(DOMElement $link, string $name, string $href): void
    {
        $previous = Element::previousElement($link);

        if ($previous === null || Element::tag($previous) !== 'a' || $previous->getAttribute('href') !== $href || $this->isHidden($previous)) {
            return;
        }

        if ($name !== '' && $this->name($previous) === $name) {
            $this->report('adjacent_duplicate', Severity::Notice, '2.4.4', $link, ['href' => $href]);
        }
    }

    protected function checkPseudoLink(DOMElement $link, string $href): void
    {
        $target = strtolower(trim($href));

        if ($target !== '#' && ! str_starts_with($target, 'javascript:')) {
            return;
        }

        if ($link->hasAttribute('onclick') || Roles::effective($link) === 'button') {
            $this->report('pseudo_link', Severity::Notice, '4.1.2', $link, ['href' => $href]);
        }
    }

    /** With scheme, starting with www., or host-like (the last label is not a file extension). */
    protected function looksLikeUrl(string $name): bool
    {
        if (preg_match('~^(?:https?://|www\.)\S+$~i', $name) === 1) {
            return true;
        }

        if (preg_match('~^(?:[a-z0-9-]+\.)+([a-z]{2,})(?:/\S*)?$~i', $name, $m) !== 1) {
            return false;
        }

        return ! in_array(strtolower($m[1]), [...self::DOCUMENT_EXTENSIONS, 'html', 'htm', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'], true);
    }

    protected function isExternal(string $href): bool
    {
        return preg_match('~^(?:https?:)?//~i', trim($href)) === 1;
    }

    /** @param  list<string>  $needles */
    protected function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
