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

    /** JSON shape: like toArray(), with empty `params` and `meta` encoded as objects (spec §12). */
    public function jsonSerialize(): array
    {
        return self::objectifyMaps($this->toArray());
    }

    /**
     * @param  array<string, mixed>  $violation  a toArray() result
     * @return array<string, mixed> the same with empty `params` / `meta` as stdClass, so json_encode writes {}
     */
    public static function objectifyMaps(array $violation): array
    {
        foreach (['params', 'meta'] as $map) {
            if (($violation[$map] ?? null) === []) {
                $violation[$map] = new \stdClass;
            }
        }

        return $violation;
    }

    private function translate(string $part, ?string $locale): string
    {
        $id = 'bfsg::violations.'.$this->key.'.'.$part;

        if (! function_exists('app') || ! app()->bound('translator')) {
            return $id;
        }

        $locale ??= function_exists('config') ? (config('bfsg.locale') ?: null) : null;

        $text = app('translator')->get($id, $this->stringParams(), $locale);

        return is_string($text) ? $text : $id;
    }

    /** @return array<string, string> placeholders as strings; non-scalar values (custom analyzers) as JSON */
    private function stringParams(): array
    {
        return array_map(
            fn ($value) => match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value) || $value === null => (string) $value,
                default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            },
            $this->params,
        );
    }
}
