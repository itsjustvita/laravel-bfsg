<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMElement;
use DOMNode;
use ItsJustVita\LaravelBfsg\Contracts\Analyzer;
use ItsJustVita\LaravelBfsg\Dom\AccessibleName;
use ItsJustVita\LaravelBfsg\Dom\Element;
use ItsJustVita\LaravelBfsg\Dom\HtmlDocument;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Violation;

abstract class BaseAnalyzer implements Analyzer
{
    /** Registry key; every subclass sets it. */
    protected string $key = '';

    /** One-line description and the success criteria this analyzer covers (for list output). */
    protected string $description = '';

    /** @var list<string> */
    protected array $rules = [];

    protected HtmlDocument $document;

    /** @var list<Violation> */
    private array $violations = [];

    final public function key(): string
    {
        return $this->key;
    }

    /** @return list<Violation> */
    final public function analyze(HtmlDocument $document): array
    {
        $this->document = $document;
        $this->violations = [];

        if (! $document->isEmpty()) {
            $this->inspect();
        }

        $violations = $this->violations;
        $this->violations = [];

        return $violations;
    }

    /** @return array{key: string, description: string, rules: list<string>} */
    public function describe(): array
    {
        return ['key' => $this->key, 'description' => $this->description, 'rules' => $this->rules];
    }

    abstract protected function inspect(): void;

    /**
     * @param  string  $key  check name without the analyzer prefix, e.g. "missing_alt"
     * @param  array<string, scalar|null>  $params
     * @param  array<string, mixed>  $meta
     * @param  list<string>  $related
     * @param  list<string>  $tags
     */
    protected function report(
        string $key,
        Severity $severity,
        ?string $rule,
        ?DOMElement $element = null,
        array $params = [],
        array $meta = [],
        array $related = [],
        array $tags = [],
        bool $autoFixable = false,
    ): void {
        $this->violations[] = new Violation(
            analyzer: $this->key,
            key: $this->key.'.'.$key,
            severity: $severity,
            rule: $rule,
            params: $params,
            element: $element === null ? null : Element::describe($element),
            selector: $element === null ? null : $this->document->selectorFor($element),
            snippet: $element === null ? null : Element::snippet($element),
            meta: $meta,
            related: $related,
            tags: $tags,
            autoFixable: $autoFixable,
        );
    }

    /** @return list<DOMElement> */
    protected function query(string $expression, ?DOMNode $context = null): array
    {
        return $this->document->query($expression, $context);
    }

    protected function isHidden(DOMElement $element): bool
    {
        return Element::isHidden($element);
    }

    protected function name(DOMElement $element): string
    {
        return AccessibleName::of($element, $this->document);
    }

    protected function ownText(DOMElement $element): string
    {
        return Element::ownText($element);
    }

    protected function text(DOMElement $element): string
    {
        return Element::text($element);
    }

    /** @return list<string> */
    protected function roles(DOMElement $element): array
    {
        return Element::roles($element);
    }

    protected function isFragment(): bool
    {
        return $this->document->isFragment();
    }
}
