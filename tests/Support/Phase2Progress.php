<?php

namespace ItsJustVita\LaravelBfsg\Tests\Support;

use ItsJustVita\LaravelBfsg\Violation;

/**
 * Registry keys of the analyzers whose Appendix A implementation is complete. Every analyzer task of the
 * Phase 2 plan appends its key; the policy and fixture tests only assert on keys of analyzers listed here.
 */
final class Phase2Progress
{
    /** @var list<string> */
    public const DONE = ['images', 'forms', 'headings', 'contrast', 'aria', 'links'];

    /**
     * @param  list<string>  $keys  full violation keys such as "images.missing_alt"
     * @return list<string>
     */
    public static function filter(array $keys): array
    {
        return array_values(array_filter($keys, fn (string $key) => self::isDone($key)));
    }

    /**
     * @param  list<Violation>  $violations
     * @return list<Violation>
     */
    public static function violations(array $violations): array
    {
        return array_values(array_filter($violations, fn (Violation $violation) => in_array($violation->analyzer, self::DONE, true)));
    }

    public static function isDone(string $key): bool
    {
        return in_array(explode('.', $key, 2)[0], self::DONE, true);
    }
}
