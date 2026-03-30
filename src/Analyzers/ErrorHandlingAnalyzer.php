<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMDocument;
use DOMXPath;

class ErrorHandlingAnalyzer
{
    protected array $violations = [];

    public function analyze(DOMDocument $dom): array
    {
        $this->violations = [];
        $xpath = new DOMXPath($dom);

        $this->checkFormErrorHandling($xpath);

        return ['issues' => $this->violations];
    }

    protected function checkFormErrorHandling(DOMXPath $xpath): void
    {
        $forms = $xpath->query('//form');

        foreach ($forms as $form) {
            $this->checkForm($xpath, $form);
        }
    }

    protected function checkForm(DOMXPath $xpath, $form): void
    {
        // Check if the form has required fields
        $requiredFields = $xpath->query('.//input[@required]|.//textarea[@required]|.//select[@required]', $form);

        if ($requiredFields->length === 0) {
            return;
        }

        $hasErrorStrategy = false;
        $hasCompleteStrategy = true;

        // Check for aria-invalid on inputs
        $ariaInvalidInputs = $xpath->query('.//*[@aria-invalid]', $form);
        if ($ariaInvalidInputs->length > 0) {
            $hasErrorStrategy = true;
        }

        // Check for aria-errormessage on inputs
        $ariaErrorMessage = $xpath->query('.//*[@aria-errormessage]', $form);
        if ($ariaErrorMessage->length > 0) {
            $hasErrorStrategy = true;
        }

        // Check for aria-describedby on inputs (commonly used for error messages)
        $ariaDescribedBy = $xpath->query('.//*[@aria-describedby]', $form);
        if ($ariaDescribedBy->length > 0) {
            $hasErrorStrategy = true;
        }

        // Check for role="alert" within or near the form
        $alertRoles = $xpath->query('.//*[@role="alert"]', $form);
        if ($alertRoles->length > 0) {
            $hasErrorStrategy = true;
        }

        // Also check for role="alert" as siblings of the form
        $formParent = $form->parentNode;
        if ($formParent) {
            $nearbyAlerts = $xpath->query('.//*[@role="alert"]', $formParent);
            if ($nearbyAlerts->length > 0) {
                $hasErrorStrategy = true;
            }
        }

        // Check for error container patterns (class containing error, invalid, validation)
        $errorContainers = $xpath->query(
            './/*[contains(@class, "error") or contains(@class, "invalid") or contains(@class, "validation")]',
            $form
        );
        if ($errorContainers->length > 0) {
            $hasErrorStrategy = true;

            // If we only have class-based patterns but no ARIA, it's incomplete
            if ($ariaInvalidInputs->length === 0 && $ariaErrorMessage->length === 0 && $alertRoles->length === 0) {
                $hasCompleteStrategy = false;
            }
        }

        if (! $hasErrorStrategy) {
            $this->violations[] = [
                'type' => 'warning',
                'rule' => 'WCAG 3.3.1, 3.3.3',
                'element' => 'form',
                'message' => 'Form with required fields has no detectable error handling strategy',
                'suggestion' => 'Add aria-invalid, aria-errormessage, or role="alert" elements for accessible error feedback',
                'auto_fixable' => false,
            ];
        } elseif (! $hasCompleteStrategy) {
            $this->violations[] = [
                'type' => 'notice',
                'rule' => 'WCAG 3.3.1, 3.3.3',
                'element' => 'form',
                'message' => 'Form has CSS-based error indicators but may lack ARIA error attributes',
                'suggestion' => 'Supplement visual error indicators with aria-invalid and aria-errormessage for screen reader support',
                'auto_fixable' => false,
            ];
        }
    }
}
