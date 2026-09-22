<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMElement;
use DOMNode;
use ItsJustVita\LaravelBfsg\Dom\Element;
use ItsJustVita\LaravelBfsg\Dom\Roles;
use ItsJustVita\LaravelBfsg\Severity;

class ErrorHandlingAnalyzer extends BaseAnalyzer
{
    /** Input types the browser validates by format. */
    private const VALIDATED_TYPES = ['email', 'url', 'number'];

    private const NOT_FIELDS = ['hidden', 'submit', 'reset', 'button', 'image'];

    private const ERROR_CLASS_TOKENS = ['error', 'invalid', 'is-invalid', 'has-error', 'field-error'];

    private const ERROR_ATTRIBUTES = ['aria-invalid', 'aria-errormessage', 'aria-describedby'];

    protected string $key = 'error_handling';

    protected string $description = 'Error identification in forms';

    protected array $rules = ['3.3.1'];

    protected function inspect(): void
    {
        foreach ($this->queryVisible('//form') as $form) {
            if (! $this->bypassesBrowserValidation($form)) {
                continue;
            }

            $fields = $this->validatedFields($form);

            if ($fields === [] || $this->hasErrorStrategy($form, $fields)) {
                continue;
            }

            $this->report('no_error_strategy', Severity::Notice, '3.3.1', $form, ['name' => $this->formName($form)]);
        }
    }

    /** Only forms that switch off native validation must bring their own error identification. */
    protected function bypassesBrowserValidation(DOMElement $form): bool
    {
        return $form->hasAttribute('novalidate') || $this->query('.//*[@formnovalidate]', $form) !== [];
    }

    /** @return list<DOMElement> fields with required, aria-required, pattern, or a validated input type */
    protected function validatedFields(DOMElement $form): array
    {
        $fields = [];

        foreach ($this->queryVisible('.//input|.//select|.//textarea', $form) as $field) {
            $type = Element::enumAttr($field, 'type') ?: 'text';

            if (Element::tag($field) === 'input' && in_array($type, self::NOT_FIELDS, true)) {
                continue;
            }

            if ($field->hasAttribute('required')
                || Element::enumAttr($field, 'aria-required') === 'true'
                || $field->hasAttribute('pattern')
                || (Element::tag($field) === 'input' && in_array($type, self::VALIDATED_TYPES, true))) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /** @param  list<DOMElement>  $fields */
    protected function hasErrorStrategy(DOMElement $form, array $fields): bool
    {
        foreach ($fields as $field) {
            foreach (self::ERROR_ATTRIBUTES as $attribute) {
                if (trim($field->getAttribute($attribute)) !== '') {
                    return true;
                }
            }
        }

        if ($this->containsLiveMessage($form) || ($form->parentNode !== null && $this->containsLiveMessage($form->parentNode))) {
            return true;
        }

        foreach ($this->query('.//*[@class]', $form) as $element) {
            if (array_intersect(array_map('strtolower', Element::classTokens($element)), self::ERROR_CLASS_TOKENS) !== []) {
                return true;
            }
        }

        return false;
    }

    /** role=alert|status (explicit or implicit, e.g. <output>) inside the node. */
    protected function containsLiveMessage(DOMNode $node): bool
    {
        foreach ($this->query('.//*[@role]|.//output', $node) as $element) {
            if (in_array(Roles::of($element), ['alert', 'status'], true)) {
                return true;
            }
        }

        return false;
    }

    protected function formName(DOMElement $form): string
    {
        return $form->getAttribute('id')
            ?: $form->getAttribute('name')
            ?: $form->getAttribute('action')
            ?: 'unnamed';
    }
}
