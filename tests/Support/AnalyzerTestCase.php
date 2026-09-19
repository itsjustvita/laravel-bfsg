<?php

namespace ItsJustVita\LaravelBfsg\Tests\Support;

use ItsJustVita\LaravelBfsg\Contracts\Analyzer;
use ItsJustVita\LaravelBfsg\Dom\HtmlDocument;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use ItsJustVita\LaravelBfsg\Violation;

abstract class AnalyzerTestCase extends TestCase
{
    abstract protected function analyzer(): Analyzer;

    /**
     * @param  array{fragment?: ?bool, ignoredSelectors?: list<string>}  $options
     * @return list<Violation>
     */
    protected function analyze(string $html, array $options = []): array
    {
        return $this->analyzer()->analyze(HtmlDocument::fromHtml($html, $options));
    }

    /** @param  list<Violation>  $violations */
    protected function assertHasViolation(
        array $violations,
        string $key,
        ?string $element = null,
        ?Severity $severity = null,
        ?string $selector = null,
    ): Violation {
        foreach ($violations as $violation) {
            if ($violation->key !== $key) {
                continue;
            }
            if ($element !== null && $violation->element !== $element) {
                continue;
            }
            if ($severity !== null && $violation->severity !== $severity) {
                continue;
            }
            if ($selector !== null && $violation->selector !== $selector) {
                continue;
            }

            return $violation;
        }

        $this->fail(sprintf(
            'No violation matching key=%s element=%s severity=%s selector=%s. Found: %s',
            $key,
            $element ?? '*',
            $severity?->value ?? '*',
            $selector ?? '*',
            $this->describeAll($violations),
        ));
    }

    /** @param  list<Violation>  $violations */
    protected function assertNoViolation(array $violations, string $key): void
    {
        foreach ($violations as $violation) {
            if ($violation->key === $key) {
                $this->fail("Unexpected violation {$key}: ".$this->describeAll($violations));
            }
        }

        $this->addToAssertionCount(1);
    }

    /** @param  list<Violation>  $violations */
    protected function assertViolationCount(array $violations, string $key, int $expected): void
    {
        $actual = count(array_filter($violations, fn (Violation $v) => $v->key === $key));

        $this->assertSame($expected, $actual, "Expected {$expected} × {$key}, found {$actual}: ".$this->describeAll($violations));
    }

    /**
     * @param  list<Violation>  $violations
     * @return list<string>
     */
    protected function keys(array $violations): array
    {
        return array_values(array_map(fn (Violation $v) => $v->key, $violations));
    }

    /** @param  list<Violation>  $violations */
    private function describeAll(array $violations): string
    {
        if ($violations === []) {
            return '(none)';
        }

        return implode(', ', array_map(
            fn (Violation $v) => $v->key.'['.($v->element ?? 'document').'/'.$v->severity->value.']',
            $violations,
        ));
    }
}
