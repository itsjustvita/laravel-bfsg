<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMDocument;
use DOMXPath;

class LinkAnalyzer
{
    protected array $violations = [];

    // Common non-descriptive link texts to avoid
    protected const NON_DESCRIPTIVE_TEXTS = [
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
    ];

    public function analyze(DOMDocument $dom): array
    {
        $this->violations = [];
        $xpath = new DOMXPath($dom);

        // Check for non-descriptive link text
        $this->checkNonDescriptiveLinks($xpath);

        // Check for empty links
        $this->checkEmptyLinks($xpath);

        // Check for links without href
        $this->checkLinksWithoutHref($xpath);

        // Check for adjacent duplicate links
        $this->checkAdjacentDuplicateLinks($xpath);

        // Check for links opening in new window without warning
        $this->checkNewWindowLinks($xpath);

        // Check for link purpose clarity
        $this->checkLinkPurposeClarity($xpath);

        return ['issues' => $this->violations];
    }

    protected function checkNonDescriptiveLinks(DOMXPath $xpath): void
    {
        $links = $xpath->query('//a[@href]');

        foreach ($links as $link) {
            $linkText = trim(strtolower($link->textContent));

            if (in_array($linkText, self::NON_DESCRIPTIVE_TEXTS)) {
                $this->violations[] = [
                    'type' => 'error',
                    'rule' => 'WCAG 2.4.4, 2.4.9',
                    'element' => 'a',
                    'message' => "Non-descriptive link text: '{$linkText}'",
                    'href' => $link->getAttribute('href'),
                    'suggestion' => 'Use descriptive text that explains the link destination or purpose',
                    'auto_fixable' => false,
                ];
            }

            // Check for very short link text
            if (strlen($linkText) > 0 && strlen($linkText) <= 2 && ! $link->hasAttribute('aria-label')) {
                $this->violations[] = [
                    'type' => 'warning',
                    'rule' => 'WCAG 2.4.4',
                    'element' => 'a',
                    'message' => "Very short link text: '{$linkText}'",
                    'href' => $link->getAttribute('href'),
                    'suggestion' => 'Consider using more descriptive text or adding aria-label',
                    'auto_fixable' => false,
                ];
            }
        }
    }

    protected function checkEmptyLinks(DOMXPath $xpath): void
    {
        $emptyLinks = $xpath->query('//a[@href and not(text()) and not(*)]');

        foreach ($emptyLinks as $link) {
            // Check if link has aria-label or title
            if (! $link->hasAttribute('aria-label') && ! $link->hasAttribute('title')) {
                $this->violations[] = [
                    'type' => 'error',
                    'rule' => 'WCAG 2.4.4, 4.1.2',
                    'element' => 'a',
                    'message' => 'Empty link without accessible text',
                    'href' => $link->getAttribute('href'),
                    'suggestion' => 'Add link text, aria-label, or title attribute',
                    'auto_fixable' => false,
                ];
            }
        }

        // Check for links with only images but no alt text
        $imageLinks = $xpath->query('//a[@href]/img[not(@alt) or @alt=""]');

        foreach ($imageLinks as $img) {
            $link = $img->parentNode;

            // Check if link has other text content
            $textContent = trim(str_replace($img->textContent, '', $link->textContent));

            if (empty($textContent) && ! $link->hasAttribute('aria-label')) {
                $this->violations[] = [
                    'type' => 'error',
                    'rule' => 'WCAG 2.4.4, 1.1.1',
                    'element' => 'a',
                    'message' => 'Link with image lacking alternative text',
                    'href' => $link->getAttribute('href'),
                    'suggestion' => 'Add alt text to image or aria-label to link',
                    'auto_fixable' => false,
                ];
            }
        }
    }

    protected function checkLinksWithoutHref(DOMXPath $xpath): void
    {
        $linksWithoutHref = $xpath->query('//a[not(@href)]');

        foreach ($linksWithoutHref as $link) {
            $this->violations[] = [
                'type' => 'warning',
                'rule' => 'WCAG 2.4.4',
                'element' => 'a',
                'message' => 'Anchor element without href attribute',
                'content' => substr(trim($link->textContent), 0, 50),
                'suggestion' => 'Add href attribute or use a different element',
                'auto_fixable' => false,
            ];
        }
    }

    protected function checkAdjacentDuplicateLinks(DOMXPath $xpath): void
    {
        $links = $xpath->query('//a[@href]');
        $previousHref = null;
        $previousText = null;

        foreach ($links as $link) {
            $currentHref = $link->getAttribute('href');
            $currentText = trim($link->textContent);

            if ($previousHref === $currentHref && ! empty($currentHref)) {
                // Check if links are adjacent (siblings)
                if ($link->previousSibling && $link->previousSibling->nodeName === 'a') {
                    $this->violations[] = [
                        'type' => 'warning',
                        'rule' => 'WCAG 2.4.4',
                        'element' => 'a',
                        'message' => 'Adjacent duplicate links to same destination',
                        'href' => $currentHref,
                        'suggestion' => 'Combine duplicate links or differentiate their purposes',
                        'auto_fixable' => false,
                    ];
                }
            }

            $previousHref = $currentHref;
            $previousText = $currentText;
        }
    }

    protected function checkNewWindowLinks(DOMXPath $xpath): void
    {
        $newWindowLinks = $xpath->query('//a[@target="_blank" or @target="blank"]');

        foreach ($newWindowLinks as $link) {
            $linkText = trim($link->textContent);
            $ariaLabel = $link->getAttribute('aria-label');
            $title = $link->getAttribute('title');

            // Check if there's any indication that link opens in new window
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
                $this->violations[] = [
                    'type' => 'warning',
                    'rule' => 'WCAG 3.2.5',
                    'element' => 'a',
                    'message' => 'Link opens in new window without warning',
                    'href' => $link->getAttribute('href'),
                    'linkText' => substr($linkText, 0, 50),
                    'suggestion' => 'Add "(opens in new window)" to link text or aria-label',
                    'auto_fixable' => true,
                ];
            }

            // Check for missing rel="noopener noreferrer" for security
            $rel = $link->getAttribute('rel');
            if (stripos($rel, 'noopener') === false || stripos($rel, 'noreferrer') === false) {
                $this->violations[] = [
                    'type' => 'warning',
                    'rule' => 'Security Best Practice',
                    'element' => 'a',
                    'message' => 'External link missing rel="noopener noreferrer"',
                    'href' => $link->getAttribute('href'),
                    'suggestion' => 'Add rel="noopener noreferrer" for security',
                    'auto_fixable' => true,
                ];
            }
        }
    }

    protected function checkLinkPurposeClarity(DOMXPath $xpath): void
    {
        $links = $xpath->query('//a[@href]');

        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            $linkText = trim($link->textContent);

            // Check for URL as link text
            if (filter_var($linkText, FILTER_VALIDATE_URL)) {
                $this->violations[] = [
                    'type' => 'warning',
                    'rule' => 'WCAG 2.4.4',
                    'element' => 'a',
                    'message' => 'URL used as link text',
                    'href' => $href,
                    'linkText' => substr($linkText, 0, 50),
                    'suggestion' => 'Use descriptive text instead of URL',
                    'auto_fixable' => false,
                ];
            }

            // Check for file download links without indication
            if (preg_match('/\.(pdf|doc|docx|xls|xlsx|zip|rar)$/i', $href)) {
                $hasFileIndication = (
                    stripos($linkText, 'pdf') !== false ||
                    stripos($linkText, 'download') !== false ||
                    stripos($linkText, 'document') !== false ||
                    stripos($linkText, 'file') !== false
                );

                if (! $hasFileIndication) {
                    $fileType = strtoupper(pathinfo($href, PATHINFO_EXTENSION));
                    $this->violations[] = [
                        'type' => 'warning',
                        'rule' => 'WCAG 2.4.4',
                        'element' => 'a',
                        'message' => 'File download link without file type indication',
                        'href' => $href,
                        'linkText' => substr($linkText, 0, 50),
                        'suggestion' => "Add file type and size info (e.g., 'Document ({$fileType}, 2MB)')",
                        'auto_fixable' => false,
                    ];
                }
            }
        }
    }
}
