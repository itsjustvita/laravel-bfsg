<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMElement;
use ItsJustVita\LaravelBfsg\Dom\Element;
use ItsJustVita\LaravelBfsg\Dom\Roles;
use ItsJustVita\LaravelBfsg\Severity;

class StatusMessageAnalyzer extends BaseAnalyzer
{
    private const VALID_LIVE_VALUES = ['off', 'polite', 'assertive'];

    /** Class tokens that mark a status message container (exact token match). */
    public const MESSAGE_CLASS_TOKENS = ['alert', 'toast', 'flash', 'notification', 'notice', 'message', 'snackbar'];

    protected string $key = 'status_messages';

    protected string $description = 'Status messages and live regions';

    protected array $rules = ['4.1.3'];

    protected function inspect(): void
    {
        foreach ($this->queryVisible('//*[@aria-live]') as $element) {
            $value = Element::enumAttr($element, 'aria-live');

            if ($value === '') {
                $this->report('empty_aria_live', Severity::Notice, '4.1.3', $element);
            } elseif (! in_array($value, self::VALID_LIVE_VALUES, true)) {
                $this->report('invalid_aria_live', Severity::Error, '4.1.3', $element, ['value' => $element->getAttribute('aria-live')]);
            }
        }

        foreach ($this->queryVisible('//*[@class]') as $element) {
            if ($this->isMessageContainer($element) && ! $this->insideMessageContainer($element) && ! $this->isAnnounced($element)) {
                $this->report('alert_without_live_region', Severity::Warning, '4.1.3', $element);
            }
        }
    }

    protected function isMessageContainer(DOMElement $element): bool
    {
        return array_intersect(array_map('strtolower', Element::classTokens($element)), self::MESSAGE_CLASS_TOKENS) !== [];
    }

    /** Only the outermost message container is reported. */
    protected function insideMessageContainer(DOMElement $element): bool
    {
        for ($node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            if ($this->isMessageContainer($node)) {
                return true;
            }
        }

        return false;
    }

    /** Self or an ancestor is a live region: a live role (status, alert, log, marquee; <output> is status) or aria-live polite/assertive. */
    protected function isAnnounced(DOMElement $element): bool
    {
        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
            $role = Roles::of($node);

            if (($role !== null && Roles::isLive($role)) || in_array(Element::enumAttr($node, 'aria-live'), ['polite', 'assertive'], true)) {
                return true;
            }
        }

        return false;
    }
}
