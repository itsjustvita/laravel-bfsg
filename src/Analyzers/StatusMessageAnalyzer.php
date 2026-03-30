<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMDocument;
use DOMXPath;

class StatusMessageAnalyzer
{
    protected array $violations = [];

    protected array $implicitLiveRoles = [
        'status',
        'alert',
        'log',
        'progressbar',
        'timer',
    ];

    protected array $validAriaLiveValues = [
        'polite',
        'assertive',
        'off',
    ];

    public function analyze(DOMDocument $dom): array
    {
        $this->violations = [];
        $xpath = new DOMXPath($dom);

        $this->checkLiveRegions($xpath);

        return ['issues' => $this->violations];
    }

    protected function checkLiveRegions(DOMXPath $xpath): void
    {
        $hasLiveRegion = false;

        // Check for explicit aria-live regions
        $ariaLiveElements = $xpath->query('//*[@aria-live]');

        foreach ($ariaLiveElements as $element) {
            $hasLiveRegion = true;
            $value = $element->getAttribute('aria-live');

            if (! in_array($value, $this->validAriaLiveValues, true)) {
                $this->violations[] = [
                    'type' => 'error',
                    'rule' => 'WCAG 4.1.3',
                    'element' => $element->nodeName,
                    'message' => "Invalid aria-live value \"{$value}\"",
                    'suggestion' => 'Use a valid aria-live value: polite, assertive, or off',
                    'auto_fixable' => false,
                ];
            }
        }

        // Check for implicit live region roles
        foreach ($this->implicitLiveRoles as $role) {
            $elements = $xpath->query("//*[@role=\"{$role}\"]");
            if ($elements->length > 0) {
                $hasLiveRegion = true;
            }
        }

        // Heuristic: check if page has dynamic content indicators (forms or buttons)
        if (! $hasLiveRegion) {
            $this->checkDynamicContentIndicators($xpath);
        }
    }

    protected function checkDynamicContentIndicators(DOMXPath $xpath): void
    {
        $forms = $xpath->query('//form');
        $buttons = $xpath->query('//button|//input[@type="submit"]|//input[@type="button"]');

        $hasDynamicContent = $forms->length > 0 || $buttons->length > 0;

        if ($hasDynamicContent) {
            $this->violations[] = [
                'type' => 'warning',
                'rule' => 'WCAG 4.1.3',
                'element' => 'body',
                'message' => 'Page with interactive elements has no aria-live region for status messages',
                'suggestion' => 'Add an aria-live="polite" region or use role="status" to announce dynamic content changes to screen readers',
                'auto_fixable' => false,
            ];
        }
    }
}
