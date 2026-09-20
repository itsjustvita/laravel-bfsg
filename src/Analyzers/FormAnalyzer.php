<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMElement;
use ItsJustVita\LaravelBfsg\Severity;

class FormAnalyzer extends BaseAnalyzer
{
    protected string $key = 'forms';

    protected string $description = 'Labels and instructions for form controls';

    protected array $rules = ['4.1.2', '1.3.1', '3.3.2'];

    protected function inspect(): void
    {
        $this->checkInputsWithoutLabels();
        $this->checkFormsAccessibility();
        $this->checkRequiredFields();
    }

    protected function checkInputsWithoutLabels(): void
    {
        $inputs = $this->query('//input[@type!="hidden" and @type!="submit" and @type!="button" and not(@aria-label) and not(@aria-labelledby)]');

        foreach ($inputs as $input) {
            if ($this->hasAssociatedLabel($input)) {
                continue;
            }

            $this->report(
                'control_missing_label',
                Severity::Error,
                '4.1.2',
                $input,
                ['name' => $this->controlName($input), 'type' => 'input'],
                related: ['1.3.1', '3.3.2'],
            );
        }

        $this->checkOtherFormElements('textarea');
        $this->checkOtherFormElements('select');
    }

    protected function checkOtherFormElements(string $tag): void
    {
        foreach ($this->query("//{$tag}[not(@aria-label) and not(@aria-labelledby)]") as $element) {
            if ($this->hasAssociatedLabel($element)) {
                continue;
            }

            $this->report(
                'control_missing_label',
                Severity::Error,
                '4.1.2',
                $element,
                ['name' => $this->controlName($element), 'type' => $tag],
                related: ['1.3.1', '3.3.2'],
            );
        }
    }

    protected function checkFormsAccessibility(): void
    {
        foreach ($this->query('//form[not(@aria-label) and not(@aria-labelledby)]') as $form) {
            $hasHeading = $this->query('.//h1|.//h2|.//h3|.//h4|.//h5|.//h6|.//legend', $form) !== [];

            if (! $hasHeading) {
                $this->report('form_missing_name', Severity::Warning, '1.3.1', $form);
            }
        }
    }

    protected function checkRequiredFields(): void
    {
        foreach ($this->query('//input[@required]|//textarea[@required]|//select[@required]') as $field) {
            if ($field->getAttribute('aria-required') === 'true') {
                continue;
            }

            $this->report(
                'required_missing_aria_required',
                Severity::Warning,
                '3.3.2',
                $field,
                ['name' => $this->controlName($field)],
            );
        }
    }

    protected function hasAssociatedLabel(DOMElement $control): bool
    {
        $id = $control->getAttribute('id');

        if ($id === '') {
            return false;
        }

        return $this->query('//label[@for='.$this->document->xpathLiteral($id).']') !== [];
    }

    protected function controlName(DOMElement $control): string
    {
        return $control->getAttribute('name')
            ?: ($control->getAttribute('id') ?: 'unnamed');
    }
}
