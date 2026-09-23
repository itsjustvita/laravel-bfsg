<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMElement;
use ItsJustVita\LaravelBfsg\Dom\Element;
use ItsJustVita\LaravelBfsg\Dom\Roles;
use ItsJustVita\LaravelBfsg\Dom\Text;
use ItsJustVita\LaravelBfsg\Severity;

class FormAnalyzer extends BaseAnalyzer
{
    /** Input types that are not labelled controls (buttons are checked by button_missing_name). */
    private const UNLABELLED_TYPES = ['hidden', 'submit', 'reset', 'button', 'image'];

    /** Markers that tell sighted users a field is required. */
    private const REQUIRED_MARKERS = ['*', 'required', 'pflicht', 'erforderlich', 'obligatorisch'];

    protected string $key = 'forms';

    protected string $description = 'Labels and instructions for form controls';

    protected array $rules = ['4.1.2', '1.3.1', '3.3.2'];

    /** @var array<int, string> form object id => text of its description and legends (per analyze run) */
    private array $formTexts = [];

    protected function inspect(): void
    {
        $this->formTexts = [];

        foreach ($this->queryVisible('//input|//select|//textarea') as $control) {
            $this->checkControl($control);
        }

        foreach ($this->queryVisible('//button|//input|//*[@role]') as $element) {
            $this->checkButton($element);
        }

        $this->checkRadioGroups();
    }

    protected function checkControl(DOMElement $control): void
    {
        $tag = Element::tag($control);
        $type = $tag === 'input' ? $this->inputType($control) : $tag;

        if ($tag === 'input' && in_array($type, self::UNLABELLED_TYPES, true)) {
            return;
        }

        $name = $this->name($control);

        if ($name === '') {
            $this->report(
                'control_missing_label',
                Severity::Error,
                '4.1.2',
                $control,
                ['name' => $this->controlName($control), 'type' => $type],
                related: ['1.3.1', '3.3.2'],
            );

            return;
        }

        if ($this->isRequired($control) && ! $this->requiredIsIndicated($control, $name)) {
            $this->report('required_not_indicated', Severity::Notice, '3.3.2', $control, ['name' => $this->controlName($control)]);
        }
    }

    protected function checkButton(DOMElement $element): void
    {
        $tag = Element::tag($element);

        $isButton = match (true) {
            $tag === 'button' => true,
            $tag === 'input' => $this->inputType($element) === 'button',
            default => Roles::effective($element) === 'button' && ! ($tag === 'a' && $element->hasAttribute('href')),
        };

        if ($isButton && $this->name($element) === '') {
            $this->report('button_missing_name', Severity::Error, '4.1.2', $element, ['tag' => $tag]);
        }
    }

    /** Radio buttons sharing a name (per form) need a group label: fieldset/legend or a named radiogroup/group. */
    protected function checkRadioGroups(): void
    {
        $groups = [];

        foreach ($this->queryVisible('//input[@name]') as $radio) {
            if ($this->inputType($radio) !== 'radio' || trim($radio->getAttribute('name')) === '') {
                continue;
            }

            $form = Element::closest($radio, 'form');
            $groups[($form === null ? 'document' : spl_object_id($form)).'|'.$radio->getAttribute('name')][] = $radio;
        }

        foreach ($groups as $radios) {
            if (count($radios) < 2 || $this->hasGroupLabel($radios[0])) {
                continue;
            }

            $this->report('radio_group_missing_legend', Severity::Warning, '1.3.1', $radios[0], ['name' => $radios[0]->getAttribute('name')]);
        }
    }

    protected function hasGroupLabel(DOMElement $radio): bool
    {
        for ($node = $radio->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            if (Element::tag($node) === 'fieldset' && $this->name($node) !== '') {
                return true;
            }

            if (in_array(Roles::effective($node), ['radiogroup', 'group'], true) && $this->authoredName($node) !== '') {
                return true;
            }
        }

        return false;
    }

    protected function isRequired(DOMElement $control): bool
    {
        return $control->hasAttribute('required') || Element::enumAttr($control, 'aria-required') === 'true';
    }

    /**
     * The required state is visible when the control's name, its label texts (including aria-hidden
     * asterisks), its description, or a legend/description of its form mentions it.
     */
    protected function requiredIsIndicated(DOMElement $control, string $name): bool
    {
        $texts = [$name];
        $id = trim($control->getAttribute('id'));

        if ($id !== '') {
            foreach ($this->document->labelsFor($id) as $label) {
                $texts[] = Element::text($label);
            }
        }

        $wrapping = Element::closest($control, 'label');
        $texts[] = $wrapping === null ? '' : Element::text($wrapping);
        $texts[] = $this->referencedText($control, 'aria-describedby');

        $form = Element::closest($control, 'form');

        if ($form !== null) {
            $texts[] = $this->formText($form);
        }

        $haystack = Text::lower(implode(' ', $texts));

        foreach (self::REQUIRED_MARKERS as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }

    /** The form's description and legend texts, collected once per form instead of once per control. */
    protected function formText(DOMElement $form): string
    {
        return $this->formTexts[spl_object_id($form)] ??= implode(' ', [
            $this->referencedText($form, 'aria-describedby'),
            ...array_map(fn (DOMElement $legend): string => Element::text($legend), $this->query('.//legend', $form)),
        ]);
    }

    protected function referencedText(DOMElement $element, string $attribute): string
    {
        $texts = [];

        foreach (Element::idrefs($element, $attribute) as $id) {
            $reference = $this->document->elementsById()[$id] ?? null;
            $texts[] = $reference === null ? '' : Element::text($reference);
        }

        return implode(' ', $texts);
    }

    protected function inputType(DOMElement $input): string
    {
        return Element::enumAttr($input, 'type') ?: 'text';
    }

    protected function controlName(DOMElement $control): string
    {
        return $control->getAttribute('name')
            ?: ($control->getAttribute('id') ?: 'unnamed');
    }
}
