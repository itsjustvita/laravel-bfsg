<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMDocument;
use DOMXPath;

class InputPurposeAnalyzer
{
    protected array $violations = [];

    protected array $personalDataPatterns = [
        'name',
        'email',
        'tel',
        'phone',
        'address',
        'postal',
        'zip',
        'country',
        'organization',
        'cc-name',
        'cc-number',
        'cc-exp',
        'cc-csc',
        'bday',
        'birthday',
        'url',
    ];

    protected array $validAutocompleteTokens = [
        'name',
        'email',
        'tel',
        'street-address',
        'postal-code',
        'country',
        'organization',
        'cc-name',
        'cc-number',
        'cc-exp',
        'cc-csc',
        'bday',
        'url',
        'given-name',
        'family-name',
        'honorific-prefix',
        'honorific-suffix',
        'address-line1',
        'address-line2',
        'address-level1',
        'address-level2',
        'on',
        'off',
        'username',
        'new-password',
        'current-password',
        'one-time-code',
        'country-name',
        'address-level3',
        'address-level4',
        'nickname',
        'additional-name',
        'sex',
        'photo',
        'impp',
        'language',
        'bday-day',
        'bday-month',
        'bday-year',
        'tel-country-code',
        'tel-national',
        'tel-area-code',
        'tel-local',
        'tel-extension',
        'cc-exp-month',
        'cc-exp-year',
        'cc-type',
        'transaction-currency',
        'transaction-amount',
    ];

    public function analyze(DOMDocument $dom): array
    {
        $this->violations = [];
        $xpath = new DOMXPath($dom);

        $this->checkInputPurpose($xpath);

        return ['issues' => $this->violations];
    }

    protected function checkInputPurpose(DOMXPath $xpath): void
    {
        $inputs = $xpath->query('//input[not(@type="hidden") and not(@type="submit") and not(@type="button") and not(@type="reset")]|//select|//textarea');

        foreach ($inputs as $input) {
            $autocomplete = $input->getAttribute('autocomplete');
            $isPersonalData = $this->isPersonalDataField($input);

            if ($autocomplete !== '') {
                $this->validateAutocompleteValue($autocomplete, $input);
            } elseif ($isPersonalData) {
                $this->violations[] = [
                    'type' => 'warning',
                    'rule' => 'WCAG 1.3.5',
                    'element' => $input->nodeName,
                    'message' => 'Personal data input field is missing autocomplete attribute',
                    'name' => $input->getAttribute('name') ?: $input->getAttribute('id') ?: 'unnamed',
                    'suggestion' => 'Add an autocomplete attribute with the appropriate token to help users fill in personal data',
                    'auto_fixable' => false,
                ];
            }
        }
    }

    protected function isPersonalDataField($input): bool
    {
        $name = strtolower($input->getAttribute('name'));
        $id = strtolower($input->getAttribute('id'));
        $type = strtolower($input->getAttribute('type'));

        foreach ($this->personalDataPatterns as $pattern) {
            if ($name !== '' && str_contains($name, $pattern)) {
                return true;
            }
            if ($id !== '' && str_contains($id, $pattern)) {
                return true;
            }
        }

        // Check input types that imply personal data
        if (in_array($type, ['email', 'tel', 'url'], true)) {
            return true;
        }

        return false;
    }

    protected function validateAutocompleteValue(string $autocomplete, $input): void
    {
        $tokens = preg_split('/\s+/', trim($autocomplete));
        $lastToken = end($tokens);

        if (! in_array($lastToken, $this->validAutocompleteTokens, true)) {
            $this->violations[] = [
                'type' => 'error',
                'rule' => 'WCAG 1.3.5',
                'element' => $input->nodeName,
                'message' => "Invalid autocomplete value \"{$autocomplete}\"",
                'name' => $input->getAttribute('name') ?: $input->getAttribute('id') ?: 'unnamed',
                'suggestion' => 'Use a valid autocomplete token such as: name, email, tel, street-address, postal-code',
                'auto_fixable' => false,
            ];
        }
    }
}
