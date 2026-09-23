<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMElement;
use ItsJustVita\LaravelBfsg\Css\CssParser;
use ItsJustVita\LaravelBfsg\Dom\Element;
use ItsJustVita\LaravelBfsg\Dom\Roles;
use ItsJustVita\LaravelBfsg\Severity;

class AriaAnalyzer extends BaseAnalyzer
{
    public const IDREF_ATTRIBUTES = [
        'aria-labelledby', 'aria-describedby', 'aria-controls', 'aria-owns',
        'aria-activedescendant', 'aria-errormessage', 'aria-details', 'aria-flowto',
    ];

    private const IDREF_QUERY = '//*[@aria-labelledby or @aria-describedby or @aria-controls or @aria-owns or @aria-activedescendant or @aria-errormessage or @aria-details or @aria-flowto]';

    private const STATES = ['aria-checked', 'aria-selected', 'aria-pressed', 'aria-expanded', 'aria-valuenow'];

    /** Native elements whose required state is provided by the element itself. */
    private const NATIVE_STATE_TAGS = ['select', 'progress', 'meter', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    private const NATIVE_STATE_INPUTS = ['checkbox', 'radio', 'range'];

    protected string $key = 'aria';

    protected string $description = 'ARIA roles, states and references';

    protected array $rules = ['4.1.2', '1.3.1', '4.1.1'];

    public function __construct(private ?CssParser $cssParser = null) {}

    protected function inspect(): void
    {
        foreach ($this->queryVisible('//*[@role]') as $element) {
            $this->checkRole($element);
        }

        foreach ($this->queryVisible('//*[@aria-checked or @aria-selected or @aria-pressed or @aria-expanded or @aria-valuenow]') as $element) {
            $this->checkSupportedState($element);
        }

        $this->checkHiddenFocusable();
        $this->checkIdReferences();
        $this->checkDuplicateIds();
    }

    protected function checkRole(DOMElement $element): void
    {
        $tokens = Element::roles($element);

        if ($tokens === []) {
            return;
        }

        $role = null;

        foreach ($tokens as $token) {
            if (Roles::isValid($token) || Roles::isAbstract($token)) {
                $role = $token;

                break;
            }
        }

        if ($role === null) {
            $this->report('invalid_role', Severity::Error, '4.1.2', $element, ['role' => trim($element->getAttribute('role'))]);

            return;
        }

        if (Roles::isAbstract($role)) {
            $this->report('abstract_role', Severity::Error, '4.1.2', $element, ['role' => $role]);

            return;
        }

        foreach (Roles::requiredStates($role) as $state) {
            if (! $element->hasAttribute($state) && ! $this->providesStateNatively($element)) {
                $this->report('missing_required_state', Severity::Error, '4.1.2', $element, ['role' => $role, 'attribute' => $state]);

                break;
            }
        }

        if ($role === Roles::implicit($element)) {
            $this->report('redundant_role', Severity::Notice, '4.1.2', $element, ['role' => $role, 'tag' => Element::tag($element)], autoFixable: true);
        }
    }

    protected function checkSupportedState(DOMElement $element): void
    {
        $role = Roles::of($element);

        foreach (self::STATES as $state) {
            if ($element->hasAttribute($state) && ($role === null || ! Roles::supportsState($role, $state))) {
                $this->report('unsupported_state', Severity::Warning, '4.1.2', $element, ['attribute' => $state, 'tag' => Element::tag($element)]);

                return;
            }
        }
    }

    /**
     * Focusable elements that are, or sit inside, aria-hidden="true" but are still rendered. Elements hidden
     * by the page's stylesheets (e.g. a closed Bootstrap modal: .modal { display: none }) are not rendered.
     */
    protected function checkHiddenFocusable(): void
    {
        $reported = [];
        $parser = null;

        foreach ($this->query('//*[@aria-hidden]') as $container) {
            if (Element::enumAttr($container, 'aria-hidden') !== 'true' || Element::isNotRendered($container)) {
                continue;
            }

            $parser ??= $this->cssParser?->parse($this->document) ?? $this->document->cssParser();

            if ($parser->hidesElement($container)) {
                continue;
            }

            foreach ([$container, ...$this->query('.//*', $container)] as $element) {
                $id = spl_object_id($element);

                if (isset($reported[$id]) || ! Element::isFocusable($element) || Element::isNotRendered($element) || $parser->hidesElement($element)) {
                    continue;
                }

                $reported[$id] = true;
                $this->report('hidden_focusable', Severity::Error, '4.1.2', $element, ['tag' => Element::tag($element)]);
            }
        }
    }

    /** One finding per element: the first dangling reference is the parameter, all of them go into meta. */
    protected function checkIdReferences(): void
    {
        $byId = $this->document->elementsById();

        foreach ($this->queryVisible(self::IDREF_QUERY) as $element) {
            $dangling = [];

            foreach (self::IDREF_ATTRIBUTES as $attribute) {
                foreach (Element::idrefs($element, $attribute) as $id) {
                    if (! isset($byId[$id])) {
                        $dangling[] = ['attribute' => $attribute, 'id' => $id];
                    }
                }
            }

            if ($dangling !== []) {
                $this->report('dangling_idref', Severity::Error, '1.3.1', $element, $dangling[0], ['references' => $dangling], related: ['4.1.2']);
            }
        }
    }

    /** Duplicate ids only matter when something references them. */
    protected function checkDuplicateIds(): void
    {
        $duplicates = $this->document->duplicateIds();

        if ($duplicates === []) {
            return;
        }

        $referenced = [];

        foreach ($this->query(self::IDREF_QUERY) as $element) {
            foreach (self::IDREF_ATTRIBUTES as $attribute) {
                foreach (Element::idrefs($element, $attribute) as $id) {
                    $referenced[$id] = true;
                }
            }
        }

        foreach ($this->query('//label[@for]') as $label) {
            $referenced[trim($label->getAttribute('for'))] = true;
        }

        foreach (array_keys($duplicates) as $id) {
            if (! isset($referenced[$id])) {
                continue;
            }

            foreach (array_slice($this->query('//*[@id='.$this->document->xpathLiteral($id).']'), 1) as $element) {
                $this->report('duplicate_id', Severity::Warning, '4.1.1', $element, ['id' => $id]);
            }
        }
    }

    protected function providesStateNatively(DOMElement $element): bool
    {
        $tag = Element::tag($element);

        if (in_array($tag, self::NATIVE_STATE_TAGS, true)) {
            return true;
        }

        return $tag === 'input' && in_array(Element::enumAttr($element, 'type'), self::NATIVE_STATE_INPUTS, true);
    }
}
