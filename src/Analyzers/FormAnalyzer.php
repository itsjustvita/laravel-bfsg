<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMDocument;
use DOMXPath;

class FormAnalyzer
{
    protected array $violations = [];

    public function analyze(DOMDocument $dom): array
    {
        $this->violations = [];
        $xpath = new DOMXPath($dom);

        // Check for form inputs without labels
        $this->checkInputsWithoutLabels($xpath);

        // Check for forms without proper ARIA labels
        $this->checkFormsAccessibility($xpath);

        // Check for required fields without proper indication
        $this->checkRequiredFields($xpath);

        return $this->violations;
    }

    protected function checkInputsWithoutLabels(DOMXPath $xpath): void
    {
        // Find inputs without associated labels
        $inputs = $xpath->query('//input[@type!="hidden" and @type!="submit" and @type!="button" and not(@aria-label) and not(@aria-labelledby)]');

        foreach ($inputs as $input) {
            $id = $input->getAttribute('id');
            $hasLabel = false;

            if ($id) {
                // Check if there's a label with matching 'for' attribute
                $labels = $xpath->query("//label[@for='{$id}']");
                $hasLabel = $labels->length > 0;
            }

            if (!$hasLabel) {
                $this->violations[] = [
                    'type' => 'error',
                    'rule' => 'WCAG 1.3.1, 3.3.2',
                    'element' => 'input',
                    'message' => 'Form input without associated label',
                    'name' => $input->getAttribute('name') ?: 'unnamed',
                    'suggestion' => 'Add a <label> element or aria-label attribute',
                    'auto_fixable' => false,
                ];
            }
        }

        // Check textareas and selects as well
        $this->checkOtherFormElements($xpath, 'textarea');
        $this->checkOtherFormElements($xpath, 'select');
    }

    protected function checkOtherFormElements(DOMXPath $xpath, string $element): void
    {
        $elements = $xpath->query("//{$element}[not(@aria-label) and not(@aria-labelledby)]");

        foreach ($elements as $elem) {
            $id = $elem->getAttribute('id');
            $hasLabel = false;

            if ($id) {
                $labels = $xpath->query("//label[@for='{$id}']");
                $hasLabel = $labels->length > 0;
            }

            if (!$hasLabel) {
                $this->violations[] = [
                    'type' => 'error',
                    'rule' => 'WCAG 1.3.1, 3.3.2',
                    'element' => $element,
                    'message' => ucfirst($element) . ' without associated label',
                    'name' => $elem->getAttribute('name') ?: 'unnamed',
                    'suggestion' => 'Add a <label> element or aria-label attribute',
                    'auto_fixable' => false,
                ];
            }
        }
    }

    protected function checkFormsAccessibility(DOMXPath $xpath): void
    {
        // Check forms without aria-label or aria-labelledby
        $forms = $xpath->query('//form[not(@aria-label) and not(@aria-labelledby)]');

        foreach ($forms as $form) {
            // Check if form has a heading or legend that could serve as label
            $hasHeading = $xpath->query('.//h1|.//h2|.//h3|.//h4|.//h5|.//h6|.//legend', $form)->length > 0;

            if (!$hasHeading) {
                $this->violations[] = [
                    'type' => 'warning',
                    'rule' => 'WCAG 1.3.1',
                    'element' => 'form',
                    'message' => 'Form without descriptive label or heading',
                    'suggestion' => 'Add aria-label to the form or include a heading/legend',
                    'auto_fixable' => false,
                ];
            }
        }
    }

    protected function checkRequiredFields(DOMXPath $xpath): void
    {
        // Check required fields without proper indication
        $requiredInputs = $xpath->query('//input[@required]|//textarea[@required]|//select[@required]');

        foreach ($requiredInputs as $input) {
            $hasAriaRequired = $input->getAttribute('aria-required') === 'true';
            $id = $input->getAttribute('id');

            if (!$hasAriaRequired) {
                $this->violations[] = [
                    'type' => 'warning',
                    'rule' => 'WCAG 3.3.2',
                    'element' => $input->nodeName,
                    'message' => 'Required field without aria-required attribute',
                    'name' => $input->getAttribute('name') ?: 'unnamed',
                    'suggestion' => 'Add aria-required="true" for better screen reader support',
                    'auto_fixable' => true,
                ];
            }
        }
    }
}