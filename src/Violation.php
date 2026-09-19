<?php

namespace ItsJustVita\LaravelBfsg;

use JsonSerializable;

final readonly class Violation implements JsonSerializable
{
    /**
     * @param  array<string, scalar|null>  $params  translation placeholders, kept on the violation
     * @param  array<string, mixed>  $meta  analyzer-specific extras (ratio, approximate, href, ...)
     * @param  list<string>  $related  further success criteria
     * @param  list<string>  $tags  best-practice | aaa | security | approximate
     */
    public function __construct(
        public string $analyzer,
        public string $key,
        public Severity $severity,
        public ?string $rule,
        public array $params = [],
        public ?string $element = null,
        public ?string $selector = null,
        public ?string $snippet = null,
        public array $meta = [],
        public array $related = [],
        public array $tags = [],
        public bool $autoFixable = false,
    ) {}

    /**
     * Wrap a v2-style issue array. Removed once every analyzer extends BaseAnalyzer.
     */
    public static function fromLegacy(string $analyzer, array $issue): self
    {
        [$rule, $related, $tags] = self::splitLegacyRule($issue['rule'] ?? null);

        $known = ['type', 'severity', 'rule', 'element', 'message', 'suggestion', 'auto_fixable'];

        return new self(
            analyzer: $analyzer,
            key: $analyzer.'.legacy',
            severity: Severity::fromLegacy($issue['type'] ?? $issue['severity'] ?? null),
            rule: $rule,
            params: [
                'message' => (string) ($issue['message'] ?? ''),
                'suggestion' => (string) ($issue['suggestion'] ?? ''),
            ],
            element: isset($issue['element']) ? (string) $issue['element'] : null,
            meta: array_diff_key($issue, array_flip($known)),
            related: $related,
            tags: $tags,
            autoFixable: (bool) ($issue['auto_fixable'] ?? false),
        );
    }

    /** @return array{0: ?string, 1: list<string>, 2: list<string>} */
    private static function splitLegacyRule(?string $rule): array
    {
        if ($rule === null || trim($rule) === '') {
            return [null, [], []];
        }

        preg_match_all('/\b(\d\.\d\.\d{1,2})\b/', $rule, $matches);
        $criteria = $matches[1];

        if ($criteria === []) {
            return [null, [], ['security']];
        }

        return [array_shift($criteria), array_values($criteria), []];
    }

    public function message(?string $locale = null): string
    {
        return $this->translate('message', $locale);
    }

    public function suggestion(?string $locale = null): string
    {
        return $this->translate('suggestion', $locale);
    }

    public function fingerprint(): string
    {
        return sha1(implode('|', [$this->analyzer, $this->key, (string) $this->rule, (string) $this->selector]));
    }

    public function toArray(?string $locale = null): array
    {
        return [
            'id' => $this->fingerprint(),
            'analyzer' => $this->analyzer,
            'key' => $this->key,
            'severity' => $this->severity->value,
            'rule' => $this->rule,
            'related' => $this->related,
            'tags' => $this->tags,
            'message' => $this->message($locale),
            'suggestion' => $this->suggestion($locale),
            'element' => $this->element,
            'selector' => $this->selector,
            'snippet' => $this->snippet,
            'params' => $this->params,
            'meta' => $this->meta,
            'auto_fixable' => $this->autoFixable,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private function translate(string $part, ?string $locale): string
    {
        if (str_ends_with($this->key, '.legacy')) {
            return (string) ($this->params[$part] ?? '');
        }

        $id = 'bfsg::violations.'.$this->key.'.'.$part;

        if (! function_exists('app') || ! app()->bound('translator')) {
            return $id;
        }

        $locale ??= function_exists('config') ? (config('bfsg.locale') ?: null) : null;

        $text = app('translator')->get($id, $this->stringParams(), $locale);

        return is_string($text) ? $text : $id;
    }

    /** @return array<string, string> */
    private function stringParams(): array
    {
        return array_map(
            fn ($value) => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
            $this->params,
        );
    }
}
