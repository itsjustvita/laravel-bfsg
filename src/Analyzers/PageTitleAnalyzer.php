<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMDocument;
use DOMXPath;

class PageTitleAnalyzer
{
    protected array $violations = [];

    protected array $genericTitles = [
        'home',
        'untitled',
        'page',
        'document',
        'welcome',
        'index',
        'test',
        'website',
    ];

    public function analyze(DOMDocument $dom): array
    {
        $this->violations = [];
        $xpath = new DOMXPath($dom);

        $this->checkTitleExists($xpath);

        return ['issues' => $this->violations];
    }

    protected function checkTitleExists(DOMXPath $xpath): void
    {
        $titles = $xpath->query('//title');

        if ($titles->length === 0) {
            $this->violations[] = [
                'type' => 'error',
                'rule' => 'WCAG 2.4.2',
                'element' => 'title',
                'message' => 'Page is missing a <title> element',
                'suggestion' => 'Add a descriptive <title> element to the <head> section',
                'auto_fixable' => false,
            ];

            return;
        }

        $title = $titles->item(0);
        $titleText = trim($title->textContent);

        if ($titleText === '') {
            $this->violations[] = [
                'type' => 'error',
                'rule' => 'WCAG 2.4.2',
                'element' => 'title',
                'message' => 'Page <title> element is empty',
                'suggestion' => 'Add descriptive text to the <title> element',
                'auto_fixable' => false,
            ];

            return;
        }

        $this->checkGenericTitle($titleText);
        $this->checkTitleLength($titleText);
    }

    protected function checkGenericTitle(string $titleText): void
    {
        if (in_array(strtolower($titleText), $this->genericTitles, true)) {
            $this->violations[] = [
                'type' => 'warning',
                'rule' => 'WCAG 2.4.2',
                'element' => 'title',
                'message' => "Page title \"{$titleText}\" is too generic",
                'suggestion' => 'Use a descriptive title that identifies the page content and purpose',
                'auto_fixable' => false,
            ];
        }
    }

    protected function checkTitleLength(string $titleText): void
    {
        $length = mb_strlen($titleText);

        if ($length < 3) {
            $this->violations[] = [
                'type' => 'warning',
                'rule' => 'WCAG 2.4.2',
                'element' => 'title',
                'message' => "Page title is too short ({$length} characters)",
                'suggestion' => 'Use a more descriptive title with at least 3 characters',
                'auto_fixable' => false,
            ];
        } elseif ($length > 70) {
            $this->violations[] = [
                'type' => 'warning',
                'rule' => 'WCAG 2.4.2',
                'element' => 'title',
                'message' => "Page title is too long ({$length} characters)",
                'suggestion' => 'Keep the title under 70 characters for better usability',
                'auto_fixable' => false,
            ];
        }
    }
}
