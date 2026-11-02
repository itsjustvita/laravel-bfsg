<?php

namespace ItsJustVita\LaravelBfsg\Tests\Support;

/** Wall-clock budgets for the `performance` group; BFSG_PERF_FACTOR scales them for slow machines such as CI runners. */
final class PerformanceBudget
{
    public static function seconds(float $base): float
    {
        return $base * (float) (getenv('BFSG_PERF_FACTOR') ?: 1);
    }
}
