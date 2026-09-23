<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMElement;
use ItsJustVita\LaravelBfsg\Dom\Element;
use ItsJustVita\LaravelBfsg\Dom\Text;
use ItsJustVita\LaravelBfsg\Severity;

class LanguageAnalyzer extends BaseAnalyzer
{
    private const MAX_CONTENT = 50;

    private const BCP47 = '/^[a-z]{2,3}(-[a-z0-9]{2,8})*$/i';

    /** ISO 639-1 two-letter language codes (plus the deprecated iw, in, ji that browsers still map). */
    public const ISO_639_1 = [
        'aa', 'ab', 'ae', 'af', 'ak', 'am', 'an', 'ar', 'as', 'av', 'ay', 'az', 'ba', 'be', 'bg', 'bh', 'bi', 'bm', 'bn', 'bo',
        'br', 'bs', 'ca', 'ce', 'ch', 'co', 'cr', 'cs', 'cu', 'cv', 'cy', 'da', 'de', 'dv', 'dz', 'ee', 'el', 'en', 'eo', 'es',
        'et', 'eu', 'fa', 'ff', 'fi', 'fj', 'fo', 'fr', 'fy', 'ga', 'gd', 'gl', 'gn', 'gu', 'gv', 'ha', 'he', 'hi', 'ho', 'hr',
        'ht', 'hu', 'hy', 'hz', 'ia', 'id', 'ie', 'ig', 'ii', 'ik', 'io', 'is', 'it', 'iu', 'ja', 'jv', 'ka', 'kg', 'ki', 'kj',
        'kk', 'kl', 'km', 'kn', 'ko', 'kr', 'ks', 'ku', 'kv', 'kw', 'ky', 'la', 'lb', 'lg', 'li', 'ln', 'lo', 'lt', 'lu', 'lv',
        'mg', 'mh', 'mi', 'mk', 'ml', 'mn', 'mr', 'ms', 'mt', 'my', 'na', 'nb', 'nd', 'ne', 'ng', 'nl', 'nn', 'no', 'nr', 'nv',
        'ny', 'oc', 'oj', 'om', 'or', 'os', 'pa', 'pi', 'pl', 'ps', 'pt', 'qu', 'rm', 'rn', 'ro', 'ru', 'rw', 'sa', 'sc', 'sd',
        'se', 'sg', 'si', 'sk', 'sl', 'sm', 'sn', 'so', 'sq', 'sr', 'ss', 'st', 'su', 'sv', 'sw', 'ta', 'te', 'tg', 'th', 'ti',
        'tk', 'tl', 'tn', 'to', 'tr', 'ts', 'tt', 'tw', 'ty', 'ug', 'uk', 'ur', 'uz', 've', 'vi', 'vo', 'wa', 'wo', 'xh', 'yi',
        'yo', 'za', 'zh', 'zu', 'iw', 'in', 'ji',
    ];

    /** Frequent function words used for the EN/DE heuristic; words shared by both languages are left out. */
    private const WORDS = [
        'en' => ['the', 'and', 'for', 'with', 'from', 'about', 'this', 'that', 'have', 'will', 'you', 'your', 'are', 'our', 'is', 'of', 'to', 'we', 'can', 'more'],
        'de' => ['der', 'die', 'das', 'und', 'für', 'mit', 'von', 'über', 'diese', 'haben', 'ist', 'nicht', 'sie', 'wir', 'ein', 'eine', 'auf', 'zu', 'den', 'dem', 'des', 'sich', 'auch', 'werden'],
    ];

    private const MIN_WORDS = 8;

    private const MIN_HITS = 3;

    private const BLOCKS = '//p|//div|//li|//td|//th|//dd|//dt|//blockquote|//figcaption|//caption|//h1|//h2|//h3|//h4|//h5|//h6';

    protected string $key = 'language';

    protected string $description = 'Language of the page and of parts';

    protected array $rules = ['3.1.1', '3.1.2'];

    protected function inspect(): void
    {
        $this->checkDocumentLanguage();
        $this->checkLangAttributes();
        $this->checkLanguageChanges();
        $this->checkXmlLang();
    }

    protected function checkDocumentLanguage(): void
    {
        if ($this->isFragment()) {
            return;
        }

        $html = $this->document->root();

        if ($html === null || Element::tag($html) !== 'html') {
            return;
        }

        if (! $html->hasAttribute('lang')) {
            $this->report('missing_lang', Severity::Error, '3.1.1', $html);
        } elseif (trim($html->getAttribute('lang')) === '') {
            $this->report('empty_lang', Severity::Error, '3.1.1', $html);
        }
    }

    /** Every non-empty lang attribute: BCP 47 syntax, then a known ISO 639-1 primary subtag. */
    protected function checkLangAttributes(): void
    {
        foreach ($this->queryVisible('//*[@lang]') as $element) {
            $lang = trim($element->getAttribute('lang'));

            if ($lang === '') {
                continue;
            }

            $rule = Element::tag($element) === 'html' ? '3.1.1' : '3.1.2';

            if (preg_match(self::BCP47, $lang) !== 1) {
                $this->report('invalid_lang', Severity::Error, $rule, $element, ['lang' => $lang]);

                continue;
            }

            $primary = strtolower(explode('-', $lang)[0]);

            if (strlen($primary) === 2 && ! in_array($primary, self::ISO_639_1, true)) {
                $this->report('unknown_lang', Severity::Warning, $rule, $element, ['lang' => $lang]);
            }
        }
    }

    protected function checkLanguageChanges(): void
    {
        foreach ($this->queryVisible(self::BLOCKS) as $element) {
            $text = $this->ownText($element);
            $detected = $this->detectLanguage($text);

            if ($detected === null) {
                continue;
            }

            $inherited = $this->inheritedLanguage($element);

            if ($inherited === null || $inherited === $detected) {
                continue;
            }

            $this->report('possible_language_change', Severity::Warning, '3.1.2', $element, [
                'content' => Text::truncate($text, self::MAX_CONTENT),
                'lang' => $detected,
            ]);
        }
    }

    protected function checkXmlLang(): void
    {
        foreach ($this->queryVisible('//*[@*[name()="xml:lang"]]') as $element) {
            // getAttribute() does not resolve the colon-named attribute of an HTML-parsed document.
            $xmlLang = trim($element->attributes?->getNamedItem('xml:lang')?->nodeValue ?? '');
            $lang = trim($element->getAttribute('lang'));

            if ($lang !== '' && strtolower($xmlLang) !== strtolower($lang)) {
                $this->report('xml_lang_mismatch', Severity::Warning, '3.1.1', $element, ['lang' => $lang, 'xml_lang' => $xmlLang]);
            }
        }
    }

    /** 'en', 'de' or null: at least MIN_WORDS words, MIN_HITS function words, and twice as many hits as the other language. */
    protected function detectLanguage(string $text): ?string
    {
        // URLs and e-mail addresses are identifiers, not prose in any language.
        $lower = Text::lower(preg_replace('~\b(?:https?://|www\.)\S+|\S+@\S+\.\S+~iu', ' ', $text) ?? $text);

        if (preg_match_all('/[\pL]+/u', $lower) < self::MIN_WORDS) {
            return null;
        }

        $hits = [];

        foreach (self::WORDS as $language => $words) {
            $pattern = '/(?<![\pL])(?:'.implode('|', array_map(fn (string $word) => preg_quote($word, '/'), $words)).')(?![\pL])/u';
            $hits[$language] = preg_match_all($pattern, $lower);
        }

        foreach (['en' => 'de', 'de' => 'en'] as $language => $other) {
            if ($hits[$language] >= self::MIN_HITS && $hits[$language] >= 2 * $hits[$other] + 1) {
                return $language;
            }
        }

        return null;
    }

    /** Primary subtag of the nearest lang attribute on the element or an ancestor. */
    protected function inheritedLanguage(DOMElement $element): ?string
    {
        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
            $lang = trim($node->getAttribute('lang'));

            if ($lang !== '') {
                return strtolower(explode('-', $lang)[0]);
            }
        }

        return null;
    }
}
