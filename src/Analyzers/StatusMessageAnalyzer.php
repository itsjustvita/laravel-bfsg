<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use ItsJustVita\LaravelBfsg\Severity;

class StatusMessageAnalyzer extends BaseAnalyzer
{
    private const INTERACTIVE = '//button|//input[@type="submit"]|//input[@type="button"]';

    protected string $key = 'status_messages';

    protected string $description = 'Status messages and live regions';

    protected array $rules = ['4.1.3'];

    /** @var list<string> */
    protected array $implicitLiveRoles = [
        'status',
        'alert',
        'log',
        'progressbar',
        'timer',
    ];

    /** @var list<string> */
    protected array $validAriaLiveValues = [
        'polite',
        'assertive',
        'off',
    ];

    protected function inspect(): void
    {
        $this->checkLiveRegions();
    }

    protected function checkLiveRegions(): void
    {
        $hasLiveRegion = false;

        // Explicit aria-live regions.
        foreach ($this->query('//*[@aria-live]') as $element) {
            $hasLiveRegion = true;
            $value = $element->getAttribute('aria-live');

            if (! in_array($value, $this->validAriaLiveValues, true)) {
                $this->report('invalid_aria_live', Severity::Error, '4.1.3', $element, ['value' => $value]);
            }
        }

        // Implicit live region roles.
        foreach ($this->implicitLiveRoles as $role) {
            if ($this->query('//*[@role="'.$role.'"]') !== []) {
                $hasLiveRegion = true;
            }
        }

        if (! $hasLiveRegion) {
            $this->checkDynamicContentIndicators();
        }
    }

    protected function checkDynamicContentIndicators(): void
    {
        // Heuristic: forms and buttons indicate dynamic content that may produce status messages.
        $hasDynamicContent = $this->query('//form') !== [] || $this->query(self::INTERACTIVE) !== [];

        if ($hasDynamicContent) {
            $this->report('no_live_region', Severity::Notice, '4.1.3');
        }
    }
}
