<?php

namespace ItsJustVita\LaravelBfsg;

use Countable;
use JsonSerializable;

final class AnalysisResult implements Countable, JsonSerializable
{
    /**
     * @param  array<string, list<Violation>>  $byAnalyzer  keyed by analyzer, registry order
     * @param  list<string>  $analyzersRun
     */
    public function __construct(
        private array $byAnalyzer,
        private array $analyzersRun,
        private ?string $url = null,
        private ?string $locale = null,
    ) {
        $this->byAnalyzer = array_filter($byAnalyzer, fn (array $violations) => $violations !== []);
    }

    /** @return array<string, list<Violation>> */
    public function byAnalyzer(): array
    {
        return $this->byAnalyzer;
    }

    /** @return list<Violation> */
    public function all(): array
    {
        return array_merge([], ...array_values($this->byAnalyzer));
    }

    /** @return list<Violation> */
    public function forAnalyzer(string $key): array
    {
        return $this->byAnalyzer[$key] ?? [];
    }

    public function count(): int
    {
        return count($this->all());
    }

    /** @return array{error: int, warning: int, notice: int} */
    public function countBySeverity(): array
    {
        $counts = ['error' => 0, 'warning' => 0, 'notice' => 0];

        foreach ($this->all() as $violation) {
            $counts[$violation->severity->value]++;
        }

        return $counts;
    }

    public function hasErrors(): bool
    {
        return $this->countBySeverity()['error'] > 0;
    }

    public function isAccessible(): bool
    {
        $counts = $this->countBySeverity();

        return $counts['error'] === 0 && $counts['warning'] === 0;
    }

    /** @return list<string> */
    public function analyzersRun(): array
    {
        return $this->analyzersRun;
    }

    public function url(): ?string
    {
        return $this->url;
    }

    public function locale(): ?string
    {
        return $this->locale;
    }

    public function withUrl(string $url): static
    {
        $clone = clone $this;
        $clone->url = $url;

        return $clone;
    }

    public function toArray(): array
    {
        $counts = $this->countBySeverity();

        return [
            'analyzers' => $this->analyzersRun,
            'summary' => [
                'total' => $this->count(),
                'errors' => $counts['error'],
                'warnings' => $counts['warning'],
                'notices' => $counts['notice'],
                'accessible' => $this->isAccessible(),
            ],
            'violations' => array_map(
                fn (array $violations) => array_map(fn (Violation $v) => $v->toArray($this->locale), $violations),
                $this->byAnalyzer,
            ),
        ];
    }

    /** JSON shape: `violations` is an object keyed by analyzer (also when empty), each violation as Violation::jsonSerialize(). */
    public function jsonSerialize(): array
    {
        $array = $this->toArray();
        $array['violations'] = $array['violations'] === [] ? new \stdClass : array_map(
            fn (array $violations) => array_map(fn (array $violation) => Violation::objectifyMaps($violation), $violations),
            $array['violations'],
        );

        return $array;
    }
}
