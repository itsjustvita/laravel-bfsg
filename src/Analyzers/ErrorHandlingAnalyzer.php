<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMElement;
use ItsJustVita\LaravelBfsg\Severity;

class ErrorHandlingAnalyzer extends BaseAnalyzer
{
    private const REQUIRED_FIELDS = './/input[@required]|.//textarea[@required]|.//select[@required]';

    private const ERROR_CONTAINERS = './/*[contains(@class, "error") or contains(@class, "invalid") or contains(@class, "validation")]';

    protected string $key = 'error_handling';

    protected string $description = 'Error identification in forms';

    protected array $rules = ['3.3.1'];

    protected function inspect(): void
    {
        $this->checkFormErrorHandling();
    }

    protected function checkFormErrorHandling(): void
    {
        foreach ($this->query('//form') as $form) {
            $this->checkForm($form);
        }
    }

    protected function checkForm(DOMElement $form): void
    {
        // Only forms with required fields are expected to have an error handling strategy.
        if ($this->query(self::REQUIRED_FIELDS, $form) === []) {
            return;
        }

        $ariaInvalid = $this->query('.//*[@aria-invalid]', $form);
        $ariaErrorMessage = $this->query('.//*[@aria-errormessage]', $form);
        // aria-describedby is commonly used to link error text.
        $ariaDescribedBy = $this->query('.//*[@aria-describedby]', $form);
        $alertRoles = $this->query('.//*[@role="alert"]', $form);
        // role="alert" may also live next to the form instead of inside it.
        $nearbyAlerts = $form->parentNode === null ? [] : $this->query('.//*[@role="alert"]', $form->parentNode);
        // Class-based patterns (error, invalid, validation) count as a strategy, but an incomplete one.
        $errorContainers = $this->query(self::ERROR_CONTAINERS, $form);

        $hasErrorStrategy = $ariaInvalid !== []
            || $ariaErrorMessage !== []
            || $ariaDescribedBy !== []
            || $alertRoles !== []
            || $nearbyAlerts !== []
            || $errorContainers !== [];

        if (! $hasErrorStrategy) {
            $this->report(
                'no_error_strategy',
                Severity::Warning,
                '3.3.1',
                $form,
                ['name' => $this->formName($form)],
                related: ['3.3.3'],
            );

            return;
        }

        $hasAriaStrategy = $ariaInvalid !== [] || $ariaErrorMessage !== [] || $alertRoles !== [];

        if ($errorContainers !== [] && ! $hasAriaStrategy) {
            $this->report(
                'css_only_error_indicators',
                Severity::Notice,
                '3.3.1',
                $form,
                ['name' => $this->formName($form)],
                related: ['3.3.3'],
            );
        }
    }

    protected function formName(DOMElement $form): string
    {
        return $form->getAttribute('id')
            ?: $form->getAttribute('name')
            ?: $form->getAttribute('action')
            ?: 'unnamed';
    }
}
