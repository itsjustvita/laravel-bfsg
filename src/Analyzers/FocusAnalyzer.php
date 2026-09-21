<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMElement;
use ItsJustVita\LaravelBfsg\Dom\Element;
use ItsJustVita\LaravelBfsg\Dom\Text;
use ItsJustVita\LaravelBfsg\Severity;

class FocusAnalyzer extends BaseAnalyzer
{
    private const GLOBAL_RESET = '/\*\s*:focus\s*\{[^}]*(?:outline\s*:\s*(?:none|0(?:px)?)\s*;?|outline-style\s*:\s*none\s*;?)[^}]*\}/i';

    private const FOCUS_RULE = '/([\w\s.*#\[\]=",>+~:-]+):focus\s*\{([^}]*)\}/i';

    protected string $key = 'focus';

    protected string $description = 'Visible keyboard focus';

    protected array $rules = ['2.4.7'];

    /** @var list<string> */
    protected array $interactiveElements = [
        'a',
        'button',
        'input',
        'select',
        'textarea',
    ];

    protected function inspect(): void
    {
        $this->checkInlineStyles();
        $this->checkStyleBlocks();
    }

    protected function checkInlineStyles(): void
    {
        $selectors = [];

        foreach ($this->interactiveElements as $tag) {
            $selectors[] = "//{$tag}[@style]";
        }

        // Also check elements with tabindex.
        $selectors[] = '//*[@tabindex][@style]';

        foreach ($this->query(implode('|', $selectors)) as $element) {
            if ($this->removesOutline($element->getAttribute('style'))) {
                $this->report('outline_removed_inline', Severity::Error, '2.4.7', $element, [
                    'tag' => Element::tag($element),
                ]);
            }
        }
    }

    protected function checkStyleBlocks(): void
    {
        foreach ($this->query('//style') as $style) {
            $media = Text::lower(trim($style->getAttribute('media')));

            if ($media !== '' && ! str_contains($media, 'all') && ! str_contains($media, 'screen')) {
                continue;
            }

            $css = $style->textContent;

            if (trim($css) === '') {
                continue;
            }

            $this->analyzeStyleBlock($css, $style);
        }
    }

    protected function analyzeStyleBlock(string $css, DOMElement $style): void
    {
        // Global focus resets such as *:focus { outline: none }.
        if (preg_match(self::GLOBAL_RESET, $css) === 1) {
            if (! $this->hasAlternativeFocusIndicator($css)) {
                $this->report('outline_removed_global', Severity::Warning, '2.4.7', $style);
            }

            return;
        }

        // Focus rules on specific selectors that remove the outline without an alternative.
        if (preg_match_all(self::FOCUS_RULE, $css, $matches, PREG_SET_ORDER) === 0) {
            return;
        }

        foreach ($matches as $match) {
            $ruleBody = $match[2];

            if (! $this->removesOutline($ruleBody) || $this->hasAlternativeInRule($ruleBody)) {
                continue;
            }

            $selector = trim($match[1]).':focus';

            $this->report('outline_removed', Severity::Error, '2.4.7', $style, ['selector' => $selector], ['selector' => $selector]);
        }
    }

    protected function removesOutline(string $css): bool
    {
        return preg_match('/outline\s*:\s*(?:none|0(?:px)?)\s*[;!}]?/i', $css) === 1
            || preg_match('/outline-style\s*:\s*none/i', $css) === 1;
    }

    protected function hasAlternativeFocusIndicator(string $css): bool
    {
        if (preg_match_all('/:focus\s*\{([^}]*)\}/i', $css, $matches) === 0) {
            return false;
        }

        foreach ($matches[1] as $ruleBody) {
            if ($this->hasAlternativeInRule($ruleBody)) {
                return true;
            }
        }

        return false;
    }

    protected function hasAlternativeInRule(string $ruleBody): bool
    {
        return preg_match('/box-shadow\s*:/i', $ruleBody) === 1
            || preg_match('/border\s*:/i', $ruleBody) === 1
            || preg_match('/border-color\s*:/i', $ruleBody) === 1
            || preg_match('/background\s*:/i', $ruleBody) === 1
            || preg_match('/background-color\s*:/i', $ruleBody) === 1;
    }
}
