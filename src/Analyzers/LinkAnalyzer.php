<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMElement;
use ItsJustVita\LaravelBfsg\Dom\Text;
use ItsJustVita\LaravelBfsg\Severity;

class LinkAnalyzer extends BaseAnalyzer
{
    private const MAX_TEXT = 50;

    private const DOWNLOAD_EXTENSIONS = '/\.(pdf|doc|docx|xls|xlsx|zip|rar)$/i';

    protected string $key = 'links';

    protected string $description = 'Link purpose and link behaviour';

    protected array $rules = ['2.4.4', '4.1.2', '3.2.5'];

    // Common non-descriptive link texts to avoid (English + German).
    protected const NON_DESCRIPTIVE_TEXTS = [
        // English
        'click here',
        'here',
        'read more',
        'more',
        'link',
        'click',
        'go',
        'start',
        'download',
        'learn more',
        'continue',
        'see more',
        'view more',
        'details',
        // German
        'hier klicken',
        'hier',
        'klicken',
        'mehr',
        'mehr erfahren',
        'weiterlesen',
        'weiter',
        'lesen',
        'jetzt',
        'los',
        'herunterladen',
    ];

    protected function inspect(): void
    {
        $this->checkNonDescriptiveLinks();
        $this->checkEmptyLinks();
        $this->checkLinksWithoutHref();
        $this->checkAdjacentDuplicateLinks();
        $this->checkNewWindowLinks();
        $this->checkLinkPurposeClarity();
    }

    protected function checkNonDescriptiveLinks(): void
    {
        foreach ($this->query('//a[@href]') as $link) {
            $linkText = $this->text($link);

            if (in_array(Text::lower($linkText), self::NON_DESCRIPTIVE_TEXTS, true)) {
                $this->report('non_descriptive', Severity::Error, '2.4.4', $link, [
                    'text' => $this->excerpt($linkText),
                    'href' => $link->getAttribute('href'),
                ], related: ['2.4.9']);
            }

            // Very short link text without an accessible name of its own.
            if (Text::length($linkText) > 0 && Text::length($linkText) <= 2 && ! $link->hasAttribute('aria-label')) {
                $this->report('non_descriptive', Severity::Warning, '2.4.4', $link, [
                    'text' => $this->excerpt($linkText),
                    'href' => $link->getAttribute('href'),
                ]);
            }
        }
    }

    protected function checkEmptyLinks(): void
    {
        foreach ($this->query('//a[@href and not(text()) and not(*)]') as $link) {
            if (! $link->hasAttribute('aria-label') && ! $link->hasAttribute('title')) {
                $this->report('missing_name', Severity::Error, '2.4.4', $link, [
                    'href' => $link->getAttribute('href'),
                ], related: ['4.1.2']);
            }
        }

        // Links whose only content is an image without alternative text.
        foreach ($this->query('//a[@href]/img[not(@alt) or @alt=""]') as $img) {
            $link = $img->parentNode;

            if (! $link instanceof DOMElement) {
                continue;
            }

            if ($this->text($link) === '' && ! $link->hasAttribute('aria-label')) {
                $this->report('missing_name', Severity::Error, '2.4.4', $link, [
                    'href' => $link->getAttribute('href'),
                ], meta: ['reason' => 'image_without_alt'], related: ['1.1.1']);
            }
        }
    }

    protected function checkLinksWithoutHref(): void
    {
        foreach ($this->query('//a[not(@href)]') as $link) {
            $this->report('missing_href', Severity::Warning, '2.4.4', $link, [
                'text' => $this->excerpt($this->text($link)),
            ]);
        }
    }

    protected function checkAdjacentDuplicateLinks(): void
    {
        $previousHref = null;

        foreach ($this->query('//a[@href]') as $link) {
            $href = $link->getAttribute('href');

            if ($href !== '' && $href === $previousHref && $link->previousSibling?->nodeName === 'a') {
                $this->report('adjacent_duplicate', Severity::Warning, '2.4.4', $link, ['href' => $href]);
            }

            $previousHref = $href;
        }
    }

    /**
     * For links with target="_blank" two independent concerns are reported:
     *   1. UX (WCAG 3.2.5): does the user know it opens in a new window?
     *   2. Security: does the link carry rel="noopener noreferrer"?
     */
    protected function checkNewWindowLinks(): void
    {
        foreach ($this->query('//a[@target="_blank" or @target="blank"]') as $link) {
            $linkText = $this->text($link);
            $ariaLabel = $link->getAttribute('aria-label');
            $title = $link->getAttribute('title');
            $rel = $link->getAttribute('rel');
            $href = $link->getAttribute('href');

            // Is there any indication that this link opens in a new window?
            $hasWarning = (
                stripos($linkText, 'new window') !== false ||
                stripos($linkText, 'new tab') !== false ||
                stripos($linkText, 'opens in') !== false ||
                stripos($ariaLabel, 'new window') !== false ||
                stripos($ariaLabel, 'new tab') !== false ||
                stripos($title, 'new window') !== false ||
                stripos($title, 'new tab') !== false
            );

            if (! $hasWarning) {
                $this->report('new_window_unannounced', Severity::Warning, '3.2.5', $link, [
                    'href' => $href,
                    'text' => $this->excerpt($linkText),
                ]);
            }

            if (stripos($rel, 'noopener') === false || stripos($rel, 'noreferrer') === false) {
                $this->report('missing_noopener', Severity::Warning, null, $link, [
                    'href' => $href,
                ], tags: ['security'], autoFixable: true);
            }
        }
    }

    protected function checkLinkPurposeClarity(): void
    {
        foreach ($this->query('//a[@href]') as $link) {
            $href = $link->getAttribute('href');
            $linkText = $this->text($link);

            if (filter_var($linkText, FILTER_VALIDATE_URL)) {
                $this->report('url_as_text', Severity::Warning, '2.4.4', $link, [
                    'href' => $href,
                    'text' => $this->excerpt($linkText),
                ]);
            }

            if (preg_match(self::DOWNLOAD_EXTENSIONS, $href) !== 1) {
                continue;
            }

            $hasFileIndication = (
                stripos($linkText, 'pdf') !== false ||
                stripos($linkText, 'download') !== false ||
                stripos($linkText, 'document') !== false ||
                stripos($linkText, 'file') !== false
            );

            if (! $hasFileIndication) {
                $this->report('download_unannounced', Severity::Warning, '2.4.4', $link, [
                    'href' => $href,
                    'text' => $this->excerpt($linkText),
                    'type' => strtoupper(pathinfo($href, PATHINFO_EXTENSION)),
                ]);
            }
        }
    }

    protected function excerpt(string $text): string
    {
        return Text::truncate($text, self::MAX_TEXT);
    }
}
