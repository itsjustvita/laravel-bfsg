<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

class LanguageAnalyzer
{
    /**
     * Valid ISO 639-1 language codes (most common ones)
     */
    protected array $validLanguageCodes = [
        'de', 'en', 'fr', 'es', 'it', 'nl', 'pl', 'pt', 'ru', 'tr',
        'ar', 'zh', 'ja', 'ko', 'hi', 'sv', 'no', 'da', 'fi', 'el',
        'cs', 'hu', 'ro', 'bg', 'hr', 'sr', 'sk', 'sl', 'uk', 'vi',
        'th', 'id', 'ms', 'fa', 'he', 'ur', 'bn', 'ta', 'te', 'mr'
    ];

    /**
     * Analyze language attributes in HTML
     *
     * @param \DOMDocument $dom
     * @return array
     */
    public function analyze(\DOMDocument $dom): array
    {
        $issues = [];

        // Check for main lang attribute on html element
        $htmlElements = $dom->getElementsByTagName('html');
        if ($htmlElements->length > 0) {
            $htmlElement = $htmlElements->item(0);
            $langAttr = $htmlElement->getAttribute('lang');

            if (empty($langAttr)) {
                $issues[] = [
                    'rule' => 'WCAG 3.1.1, BFSG §3',
                    'message' => 'Missing language attribute on html element',
                    'element' => '<html>',
                    'suggestion' => 'Add lang attribute to html element (e.g., lang="de" for German)',
                    'severity' => 'critical'
                ];
            } else {
                // Validate language code
                $langCode = $this->extractLanguageCode($langAttr);
                if (!$this->isValidLanguageCode($langCode)) {
                    $issues[] = [
                        'rule' => 'WCAG 3.1.1, BFSG §3',
                        'message' => "Invalid language code: {$langAttr}",
                        'element' => '<html>',
                        'suggestion' => 'Use valid ISO 639-1 language code (e.g., "de", "en", "fr")',
                        'severity' => 'error'
                    ];
                }
            }
        } else {
            $issues[] = [
                'rule' => 'WCAG 3.1.1, BFSG §3',
                'message' => 'No html element found in document',
                'suggestion' => 'Ensure document has proper html structure',
                'severity' => 'critical'
            ];
        }

        // Check for language changes in content
        $xpath = new \DOMXPath($dom);

        // Find elements with potential foreign language content but no lang attribute
        $textElements = $xpath->query('//p|//div|//span|//h1|//h2|//h3|//h4|//h5|//h6|//li|//td|//th');

        foreach ($textElements as $element) {
            if ($element->nodeValue && strlen(trim($element->nodeValue)) > 20) {
                // Check if element has lang attribute when needed
                $parentLang = $this->getInheritedLanguage($element);

                // Check for mixed language indicators (basic heuristic)
                if ($this->containsMixedLanguage($element->nodeValue, $parentLang)) {
                    $elementLang = $element->getAttribute('lang');
                    if (empty($elementLang)) {
                        $snippet = substr(trim($element->nodeValue), 0, 50) . '...';
                        $issues[] = [
                            'rule' => 'WCAG 3.1.2',
                            'message' => 'Possible language change without lang attribute',
                            'element' => $element->nodeName,
                            'content' => $snippet,
                            'suggestion' => 'Add lang attribute to elements with different language',
                            'severity' => 'warning'
                        ];
                    }
                }
            }
        }

        // Check all elements with lang attributes for validity
        $elementsWithLang = $xpath->query('//*[@lang]');
        foreach ($elementsWithLang as $element) {
            $langAttr = $element->getAttribute('lang');
            if ($langAttr) {
                $langCode = $this->extractLanguageCode($langAttr);
                if (!$this->isValidLanguageCode($langCode)) {
                    $issues[] = [
                        'rule' => 'WCAG 3.1.1',
                        'message' => "Invalid language code: {$langAttr}",
                        'element' => '<' . $element->nodeName . '>',
                        'suggestion' => 'Use valid ISO 639-1 language code',
                        'severity' => 'error'
                    ];
                }
            }
        }

        // Check for xml:lang attribute (should match lang if present)
        $elementsWithXmlLang = $xpath->query('//*[@xml:lang]');
        foreach ($elementsWithXmlLang as $element) {
            $xmlLang = $element->getAttribute('xml:lang');
            $lang = $element->getAttribute('lang');

            if ($lang && $xmlLang !== $lang) {
                $issues[] = [
                    'rule' => 'WCAG 3.1.1',
                    'message' => 'Mismatched lang and xml:lang attributes',
                    'element' => '<' . $element->nodeName . '>',
                    'suggestion' => 'Ensure lang and xml:lang attributes have the same value',
                    'severity' => 'warning'
                ];
            }
        }

        return [
            'issues' => $issues,
            'stats' => [
                'total_issues' => count($issues),
                'critical_issues' => count(array_filter($issues, fn($i) => ($i['severity'] ?? '') === 'critical')),
                'has_main_lang' => $htmlElements->length > 0 && !empty($htmlElements->item(0)->getAttribute('lang'))
            ]
        ];
    }

    /**
     * Extract language code from lang attribute value
     */
    protected function extractLanguageCode(string $lang): string
    {
        // Handle values like "en-US", "de-DE" by extracting the primary code
        $parts = explode('-', strtolower(trim($lang)));
        return $parts[0] ?? '';
    }

    /**
     * Check if language code is valid
     */
    protected function isValidLanguageCode(string $code): bool
    {
        return in_array(strtolower($code), $this->validLanguageCodes);
    }

    /**
     * Get inherited language from parent elements
     */
    protected function getInheritedLanguage(\DOMElement $element): ?string
    {
        $current = $element;
        while ($current && $current->parentNode) {
            if ($current->hasAttribute('lang')) {
                return $this->extractLanguageCode($current->getAttribute('lang'));
            }
            $current = $current->parentNode instanceof \DOMElement ? $current->parentNode : null;
        }
        return null;
    }

    /**
     * Basic heuristic to detect mixed language content
     */
    protected function containsMixedLanguage(string $text, ?string $documentLang): bool
    {
        // Skip if no document language is set
        if (!$documentLang) {
            return false;
        }

        // Common English words in German context or vice versa
        $indicators = [
            'de' => [
                'english' => ['the', 'and', 'for', 'with', 'from', 'about', 'this', 'that', 'have', 'will'],
                'pattern' => '/\b(the|and|for|with|from|about|this|that|have|will)\b/i'
            ],
            'en' => [
                'german' => ['der', 'die', 'das', 'und', 'für', 'mit', 'von', 'über', 'diese', 'haben'],
                'pattern' => '/\b(der|die|das|und|für|mit|von|über|diese|haben)\b/i'
            ]
        ];

        // Only check for German/English mix as example
        if ($documentLang === 'de' && isset($indicators['de'])) {
            if (preg_match($indicators['de']['pattern'], $text)) {
                // Additional check: multiple indicators
                $matches = 0;
                foreach ($indicators['de']['english'] as $word) {
                    if (stripos($text, ' ' . $word . ' ') !== false) {
                        $matches++;
                    }
                }
                return $matches >= 3; // At least 3 English words
            }
        } elseif ($documentLang === 'en' && isset($indicators['en'])) {
            if (preg_match($indicators['en']['pattern'], $text)) {
                $matches = 0;
                foreach ($indicators['en']['german'] as $word) {
                    if (stripos($text, ' ' . $word . ' ') !== false) {
                        $matches++;
                    }
                }
                return $matches >= 3; // At least 3 German words
            }
        }

        return false;
    }
}