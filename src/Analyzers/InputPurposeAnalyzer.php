<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMElement;
use ItsJustVita\LaravelBfsg\Dom\Text;
use ItsJustVita\LaravelBfsg\Severity;

class InputPurposeAnalyzer extends BaseAnalyzer
{
    private const FIELDS = '//input[not(@type="hidden") and not(@type="submit") and not(@type="button") and not(@type="reset")]|//select|//textarea';

    protected string $key = 'input_purpose';

    protected string $description = 'Identify input purpose (autocomplete)';

    protected array $rules = ['1.3.5'];

    /** @var list<string> */
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

    /** @var list<string> */
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

    protected function inspect(): void
    {
        $this->checkInputPurpose();
    }

    protected function checkInputPurpose(): void
    {
        foreach ($this->query(self::FIELDS) as $input) {
            $autocomplete = $input->getAttribute('autocomplete');

            if ($autocomplete !== '') {
                $this->validateAutocompleteValue($autocomplete, $input);
            } elseif ($this->isPersonalDataField($input)) {
                $this->report('missing_autocomplete', Severity::Warning, '1.3.5', $input, [
                    'name' => $this->fieldName($input),
                ]);
            }
        }
    }

    protected function isPersonalDataField(DOMElement $input): bool
    {
        $name = Text::lower($input->getAttribute('name'));
        $id = Text::lower($input->getAttribute('id'));
        $type = Text::lower($input->getAttribute('type'));

        foreach ($this->personalDataPatterns as $pattern) {
            if ($name !== '' && str_contains($name, $pattern)) {
                return true;
            }
            if ($id !== '' && str_contains($id, $pattern)) {
                return true;
            }
        }

        // Input types that imply personal data.
        return in_array($type, ['email', 'tel', 'url'], true);
    }

    protected function validateAutocompleteValue(string $autocomplete, DOMElement $input): void
    {
        $tokens = preg_split('/\s+/', trim($autocomplete)) ?: [];
        $lastToken = end($tokens);

        if (! in_array($lastToken, $this->validAutocompleteTokens, true)) {
            $this->report('invalid_autocomplete', Severity::Error, '1.3.5', $input, [
                'name' => $this->fieldName($input),
                'value' => $autocomplete,
            ]);
        }
    }

    protected function fieldName(DOMElement $input): string
    {
        return $input->getAttribute('name') ?: $input->getAttribute('id') ?: 'unnamed';
    }
}
