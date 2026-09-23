<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMElement;
use ItsJustVita\LaravelBfsg\Dom\Element;
use ItsJustVita\LaravelBfsg\Severity;

class InputPurposeAnalyzer extends BaseAnalyzer
{
    /** Input types for which missing_autocomplete applies (absent type = text). */
    private const TEXT_LIKE_TYPES = ['text', 'email', 'tel', 'url', 'password', 'number', 'date'];

    /** name/id tokens that indicate personal data (English + German). `cc-*` is matched as a prefix. */
    public const PERSONAL_TOKENS = [
        'email', 'e-mail', 'name', 'vorname', 'nachname', 'firstname', 'lastname', 'phone', 'tel', 'telefon',
        'mobile', 'handy', 'street', 'strasse', 'straße', 'address', 'adresse', 'zip', 'plz', 'postal', 'city',
        'ort', 'stadt', 'country', 'land', 'birthday', 'geburtstag', 'geburtsdatum', 'company', 'firma',
        'organization', 'username', 'benutzername',
    ];

    /**
     * Nouns that make a `name` token name something other than a person ("product_name", "category[name]"):
     * such fields do not collect information about the user (1.3.5 applies to the user's own data only).
     */
    public const NON_PERSON_NAME_QUALIFIERS = [
        'product', 'category', 'file', 'filename', 'project', 'team', 'page', 'site', 'app', 'tag', 'item', 'article',
        'event', 'group', 'role', 'route', 'field', 'column', 'table', 'key', 'domain', 'host', 'server', 'database',
        'db', 'brand', 'model', 'variant', 'plan', 'task', 'list', 'folder', 'document', 'image', 'attribute', 'option',
        'setting', 'menu', 'template', 'course', 'room', 'venue', 'channel', 'queue', 'job', 'label', 'layer', 'shop',
        'store', 'produkt', 'kategorie', 'datei', 'projekt', 'seite', 'artikel', 'gruppe', 'rolle', 'feld', 'vorlage',
    ];

    /** Autofill field names of the HTML standard. */
    public const FIELD_TOKENS = [
        'name', 'honorific-prefix', 'given-name', 'additional-name', 'family-name', 'honorific-suffix', 'nickname',
        'username', 'new-password', 'current-password', 'one-time-code', 'organization-title', 'organization',
        'street-address', 'address-line1', 'address-line2', 'address-line3', 'address-level4', 'address-level3',
        'address-level2', 'address-level1', 'country', 'country-name', 'postal-code', 'cc-name', 'cc-given-name',
        'cc-additional-name', 'cc-family-name', 'cc-number', 'cc-exp', 'cc-exp-month', 'cc-exp-year', 'cc-csc',
        'cc-type', 'transaction-currency', 'transaction-amount', 'language', 'bday', 'bday-day', 'bday-month',
        'bday-year', 'sex', 'url', 'photo',
    ];

    /** Contact fields; only these may carry a home/work/mobile/fax/pager hint. */
    public const CONTACT_TOKENS = [
        'tel', 'tel-country-code', 'tel-national', 'tel-area-code', 'tel-local', 'tel-local-prefix',
        'tel-local-suffix', 'tel-extension', 'email', 'impp',
    ];

    private const CONTACT_HINTS = ['home', 'work', 'mobile', 'fax', 'pager'];

    protected string $key = 'input_purpose';

    protected string $description = 'Identify input purpose (autocomplete)';

    protected array $rules = ['1.3.5'];

    protected function inspect(): void
    {
        foreach ($this->queryVisible('//input|//select|//textarea') as $field) {
            if ($this->insideSearch($field)) {
                continue;
            }

            $value = trim($field->getAttribute('autocomplete'));

            if ($value !== '' && ! $this->isValidAutocomplete($value)) {
                $this->report('invalid_autocomplete', Severity::Error, '1.3.5', $field, ['name' => $this->fieldName($field), 'value' => $value]);

                continue;
            }

            if (Element::tag($field) !== 'input' || ! in_array(Element::enumAttr($field, 'type') ?: 'text', self::TEXT_LIKE_TYPES, true)) {
                continue;
            }

            if (! $this->isPersonalDataField($field)) {
                continue;
            }

            if ($value === '') {
                $this->report('missing_autocomplete', Severity::Warning, '1.3.5', $field, ['name' => $this->fieldName($field)]);
            } elseif (strtolower($value) === 'off') {
                $this->report('autocomplete_off_on_personal_field', Severity::Notice, '1.3.5', $field, ['name' => $this->fieldName($field)]);
            }
        }
    }

    /** [section-*] [shipping|billing] [home|work|mobile|fax|pager] <field> [webauthn], or on/off alone. */
    public function isValidAutocomplete(string $value): bool
    {
        $tokens = preg_split('/\s+/', strtolower(trim($value)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($tokens === ['on'] || $tokens === ['off']) {
            return true;
        }

        if (($tokens[0] ?? '') !== '' && str_starts_with($tokens[0], 'section-') && strlen($tokens[0]) > 8) {
            array_shift($tokens);
        }

        if (in_array($tokens[0] ?? '', ['shipping', 'billing'], true)) {
            array_shift($tokens);
        }

        $hint = in_array($tokens[0] ?? '', self::CONTACT_HINTS, true) ? array_shift($tokens) : null;

        if (end($tokens) === 'webauthn') {
            array_pop($tokens);
        }

        if (count($tokens) !== 1) {
            return false;
        }

        $field = $tokens[0];

        return $hint === null
            ? in_array($field, self::FIELD_TOKENS, true) || in_array($field, self::CONTACT_TOKENS, true)
            : in_array($field, self::CONTACT_TOKENS, true);
    }

    protected function isPersonalDataField(DOMElement $field): bool
    {
        foreach (['name', 'id'] as $attribute) {
            $value = mb_strtolower(trim($field->getAttribute($attribute)), 'UTF-8');

            if ($value === '') {
                continue;
            }

            if (in_array($value, self::PERSONAL_TOKENS, true) || str_starts_with($value, 'cc-')) {
                return true;
            }

            $tokens = preg_split('/[_\-\[\]\s.]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $personal = array_values(array_unique(array_intersect($tokens, self::PERSONAL_TOKENS)));

            if ($personal === ['name'] && array_intersect($tokens, self::NON_PERSON_NAME_QUALIFIERS) !== []) {
                continue;
            }

            if ($personal !== []) {
                return true;
            }
        }

        return false;
    }

    /** Fields of a search form or search landmark never collect data about the user. */
    protected function insideSearch(DOMElement $field): bool
    {
        for ($node = $field; $node instanceof DOMElement; $node = $node->parentNode) {
            if (Element::tag($node) === 'search' || Element::enumAttr($node, 'role') === 'search') {
                return true;
            }
        }

        return false;
    }

    protected function fieldName(DOMElement $field): string
    {
        return $field->getAttribute('name') ?: $field->getAttribute('id') ?: 'unnamed';
    }
}
