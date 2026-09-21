<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMElement;
use ItsJustVita\LaravelBfsg\Dom\Text;
use ItsJustVita\LaravelBfsg\Severity;

class LanguageAnalyzer extends BaseAnalyzer
{
    private const MAX_CONTENT = 50;

    protected string $key = 'language';

    protected string $description = 'Language of the page and of parts';

    protected array $rules = ['3.1.1', '3.1.2'];

    /**
     * Valid ISO 639-1 language codes (most common ones)
     */
    protected array $validLanguageCodes = [
        'de', 'en', 'fr', 'es', 'it', 'nl', 'pl', 'pt', 'ru', 'tr',
        'ar', 'zh', 'ja', 'ko', 'hi', 'sv', 'no', 'da', 'fi', 'el',
        'cs', 'hu', 'ro', 'bg', 'hr', 'sr', 'sk', 'sl', 'uk', 'vi',
        'th', 'id', 'ms', 'fa', 'he', 'ur', 'bn', 'ta', 'te', 'mr',
    ];

    protected function inspect(): void
    {
        $this->checkDocumentLanguage();
        $this->checkLanguageChanges();
        $this->checkLanguageCodes();
        $this->checkXmlLang();
    }

    /** Main lang attribute on the html element. */
    protected function checkDocumentLanguage(): void
    {
        $html = $this->query('//html')[0] ?? null;

        if (! $html instanceof DOMElement) {
            $this->report('no_html_element', Severity::Error, '3.1.1');

            return;
        }

        $langAttr = $html->getAttribute('lang');

        if (trim($langAttr) === '') {
            $this->report('missing_lang', Severity::Error, '3.1.1', $html);

            return;
        }

        if (! $this->isValidLanguageCode($this->extractLanguageCode($langAttr))) {
            $this->report('invalid_lang', Severity::Error, '3.1.1', $html, ['lang' => $langAttr]);
        }
    }

    /** Content that looks like a language change but carries no lang attribute. */
    protected function checkLanguageChanges(): void
    {
        foreach ($this->query('//p|//div|//span|//h1|//h2|//h3|//h4|//h5|//h6|//li|//td|//th') as $element) {
            $content = $element->nodeValue ?? '';

            if (strlen(trim($content)) <= 20) {
                continue;
            }

            if (! $this->containsMixedLanguage($content, $this->getInheritedLanguage($element))) {
                continue;
            }

            if (trim($element->getAttribute('lang')) !== '') {
                continue;
            }

            $this->report('possible_language_change', Severity::Warning, '3.1.2', $element, [
                'content' => Text::truncate(Text::normalize($content), self::MAX_CONTENT),
            ]);
        }
    }

    /** Every lang attribute in the document must carry a valid language code. */
    protected function checkLanguageCodes(): void
    {
        foreach ($this->query('//*[@lang]') as $element) {
            $langAttr = $element->getAttribute('lang');

            if (trim($langAttr) === '') {
                continue;
            }

            if ($this->isValidLanguageCode($this->extractLanguageCode($langAttr))) {
                continue;
            }

            $this->report(
                'invalid_lang',
                Severity::Error,
                $element->nodeName === 'html' ? '3.1.1' : '3.1.2',
                $element,
                ['lang' => $langAttr],
            );
        }
    }

    /** xml:lang should match lang where both are present. */
    protected function checkXmlLang(): void
    {
        foreach ($this->query('//*[@*[name()="xml:lang"]]') as $element) {
            // getAttribute() does not resolve the colon-named attribute of an HTML-parsed document.
            $xmlLang = $element->attributes?->getNamedItem('xml:lang')?->nodeValue ?? '';
            $lang = $element->getAttribute('lang');

            if ($lang !== '' && $xmlLang !== $lang) {
                $this->report('xml_lang_mismatch', Severity::Warning, '3.1.1', $element, [
                    'lang' => $lang,
                    'xml_lang' => $xmlLang,
                ]);
            }
        }
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
    protected function getInheritedLanguage(DOMElement $element): ?string
    {
        $current = $element;
        while ($current && $current->parentNode) {
            if ($current->hasAttribute('lang')) {
                return $this->extractLanguageCode($current->getAttribute('lang'));
            }
            $current = $current->parentNode instanceof DOMElement ? $current->parentNode : null;
        }

        return null;
    }

    /**
     * Basic heuristic to detect mixed language content
     */
    protected function containsMixedLanguage(string $text, ?string $documentLang): bool
    {
        // Skip if no document language is set
        if (! $documentLang) {
            return false;
        }

        // Common English words in German context or vice versa
        $indicators = [
            'de' => [
                'english' => ['the', 'and', 'for', 'with', 'from', 'about', 'this', 'that', 'have', 'will'],
                'pattern' => '/\b(the|and|for|with|from|about|this|that|have|will)\b/i',
            ],
            'en' => [
                'german' => ['der', 'die', 'das', 'und', 'für', 'mit', 'von', 'über', 'diese', 'haben'],
                'pattern' => '/\b(der|die|das|und|für|mit|von|über|diese|haben)\b/i',
            ],
        ];

        // Only check for German/English mix as example
        if ($documentLang === 'de' && isset($indicators['de'])) {
            if (preg_match($indicators['de']['pattern'], $text)) {
                // Additional check: multiple indicators
                $matches = 0;
                foreach ($indicators['de']['english'] as $word) {
                    if (stripos($text, ' '.$word.' ') !== false) {
                        $matches++;
                    }
                }

                return $matches >= 3; // At least 3 English words
            }
        } elseif ($documentLang === 'en' && isset($indicators['en'])) {
            if (preg_match($indicators['en']['pattern'], $text)) {
                $matches = 0;
                foreach ($indicators['en']['german'] as $word) {
                    if (stripos($text, ' '.$word.' ') !== false) {
                        $matches++;
                    }
                }

                return $matches >= 3; // At least 3 German words
            }
        }

        return false;
    }
}
