<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit\Css;

use ItsJustVita\LaravelBfsg\Css\SupportsCondition;
use ItsJustVita\LaravelBfsg\Tests\Support\PerformanceBudget;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** Contrast report C5: the @supports condition with three-valued logic (true only when certain, null: undecidable). */
class SupportsConditionTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?bool}> */
    public static function conditions(): array
    {
        return [
            'oklch() colour' => ['(color: oklch(0% 0 0))', true],
            'not oklch() colour' => ['not (color: oklch(0% 0 0))', false],
            'daisyUI 4 fallback guard' => ['not (color:oklch(0% 0 0))', false],
            'Tailwind v4 color-mix() pair' => ['(color: color-mix(in lab, red, red))', true],
            'color-mix() in oklab' => ['(color: color-mix(in oklab, red 20%, blue))', true],
            'color-mix() with a hue method' => ['(color: color-mix(in oklch longer hue, red, blue))', true],
            'color-mix() in an unknown space' => ['(color: color-mix(in nonsense, red, red))', null],
            'lab() background' => ['(background-color: lab(50% 40 59.5))', true],
            'color() function' => ['(color: color(display-p3 1 0 0))', true],
            'border colour' => ['(border-color: #fff)', true],
            'outline colour' => ['(outline-color: rgb(0 0 0 / 50%))', true],
            'relative colour' => ['(color: rgb(from red r g b))', null],
            'not grid' => ['not (display: grid)', false],
            'display flex' => ['(display: flex)', true],
            'display contents' => ['(display:contents)', true],
            'display flow-root, upper case' => ['(DISPLAY: Flow-Root)', true],
            'position sticky' => ['(position: sticky)', true],
            'gap length' => ['(gap: 1rem)', true],
            'gap zero' => ['(gap: 0)', true],
            'aspect-ratio' => ['(aspect-ratio: 16 / 9)', true],
            'aspect-ratio number' => ['(aspect-ratio: 1)', true],
            'custom property' => ['(--tw: initial)', true],
            'important declaration' => ['(display: grid !important)', true],
            'and with a vendor test' => ['(display: grid) and (-webkit-touch-callout: none)', null],
            'or with a vendor test' => ['(display: grid) or (-webkit-touch-callout: none)', true],
            'and with a false test' => ['(display: grid) and (not (display: flex))', false],
            'or of false tests' => ['(not (display: grid)) or (not (display: flex))', false],
            'not of an undecidable and' => ['not ((display:grid) and (x:y))', null],
            'vendor test alone' => ['(-webkit-touch-callout:none)', null],
            'unknown property' => ['(margin-trim: inline)', null],
            'unknown display value' => ['(display: nonsense-value)', null],
            'gap keyword' => ['(gap: wide)', null],
            'selector()' => ['selector(:has(a))', null],
            'not selector()' => ['not selector(:has(a))', null],
            'font-tech()' => ['font-tech(color-COLRv1)', null],
            'font-format()' => ['font-format(woff2)', null],
            'at-rule()' => ['at-rule(@layer)', null],
            'general enclosed' => ['(display grid)', null],
            'nested parentheses' => ['((display: grid))', true],
            'keywords are case-insensitive' => ['NOT (display: grid) ', false],
            'Tailwind v4 @property fallback' => ['((-webkit-hyphens:none) and (not (margin-trim:inline))) or ((-moz-orient:inline) and (not (color:rgb(from red r g b))))', null],
            'or with a true and a vendor test' => ['(-moz-appearance: none) or (color: oklch(0 0 0))', true],
        ];
    }

    #[DataProvider('conditions')]
    public function test_evaluates_the_condition(string $condition, ?bool $expected): void
    {
        $this->assertSame($expected, SupportsCondition::evaluate($condition));
    }

    /** @return array<string, array{0: string}> malformed conditions: never certain */
    public static function malformed(): array
    {
        return [
            'empty' => [''],
            'bare declaration' => ['display: grid'],
            'unclosed' => ['(display: grid'],
            'stray closing parenthesis' => ['(display: grid))'],
            'mixed and/or' => ['(display: grid) and (display: flex) or (display: block)'],
            'not without whitespace' => ['not(display: grid)'],
            'trailing and' => ['(display: grid) and'],
            'double not' => ['not not (display: grid)'],
            'missing operand' => ['and (display: grid)'],
            'unclosed string' => ['(content: "x)'],
            'declaration without a value' => ['(display:)'],
        ];
    }

    #[DataProvider('malformed')]
    public function test_malformed_conditions_are_undecidable(string $condition): void
    {
        $this->assertNull(SupportsCondition::evaluate($condition));
    }

    public function test_nesting_beyond_the_cap_is_undecidable(): void
    {
        $this->assertTrue(SupportsCondition::evaluate(str_repeat('(', 32).'display: grid'.str_repeat(')', 32)));
        $this->assertNull(SupportsCondition::evaluate(str_repeat('(', 34).'display: grid'.str_repeat(')', 34)));
        $this->assertNull(SupportsCondition::evaluate(str_repeat('not (', 40).'display: grid'.str_repeat(')', 40)));
    }

    /** @return array<string, array{0: string}> hostile conditions of about 1 MB, each undecidable */
    public static function hostileConditions(): array
    {
        return [
            '1 MB and-chain ending in a vendor test' => [str_repeat('(display: grid) and ', 52000).'(-webkit-x: y)'],
            '1 MB or-chain of undecidable tests' => [implode(' or ', array_fill(0, 60000, '(x: y)'))],
            '1 MB value' => ['(color: '.str_repeat('a', 1024 * 1024).')'],
            '1 MB function argument' => ['selector('.str_repeat('(', 1024 * 1024).')'],
            '1 MB unclosed general enclosed' => ['('.str_repeat('a ', 512 * 1024)],
            '10 000 nested parentheses' => [str_repeat('(', 10000).'display: grid'.str_repeat(')', 10000)],
            '10 000 nested parentheses, unclosed' => [str_repeat('(', 10000)],
            '10 000 nested nots' => [str_repeat('not (', 10000).'display: grid'.str_repeat(')', 10000)],
        ];
    }

    // Bounded and linear: one pass over the condition, nesting capped at 32 levels.
    #[Group('performance')]
    #[DataProvider('hostileConditions')]
    public function test_hostile_conditions_are_undecidable_within_budget(string $condition): void
    {
        $start = microtime(true);
        $result = SupportsCondition::evaluate($condition);
        $elapsed = microtime(true) - $start;

        $this->assertNull($result);
        $this->assertLessThan(PerformanceBudget::seconds(0.5), $elapsed, sprintf('took %.2f s', $elapsed));
    }
}
