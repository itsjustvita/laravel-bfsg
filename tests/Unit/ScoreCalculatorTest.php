<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\AnalysisResult;
use ItsJustVita\LaravelBfsg\Reports\ScoreCalculator;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use ItsJustVita\LaravelBfsg\Violation;

class ScoreCalculatorTest extends TestCase
{
    private function sampleResult(int $errors, int $warnings, int $notices): AnalysisResult
    {
        $make = fn (Severity $s, int $n) => array_map(fn ($i) => new Violation('x', 'x.k', $s, '1.1.1', selector: "/p[$i]"), range(1, $n));

        return new AnalysisResult(['x' => array_merge($errors ? $make(Severity::Error, $errors) : [], $warnings ? $make(Severity::Warning, $warnings) : [], $notices ? $make(Severity::Notice, $notices) : [])], ['x']);
    }

    public function test_score_uses_weights_and_rounds(): void
    {
        $calc = new ScoreCalculator;

        $this->assertSame(100, $calc->score($this->sampleResult(0, 0, 0)));
        $this->assertSame(95, $calc->score($this->sampleResult(1, 0, 0)));
        $this->assertSame(100, $calc->score($this->sampleResult(0, 0, 1)), '99.5 rounds to 100');
        $this->assertSame(83, $calc->score($this->sampleResult(1, 5, 4)), '100 - 5 - 10 - 2 = 83');
        $this->assertSame(0, $calc->score($this->sampleResult(30, 0, 0)), 'never negative');
        $this->assertSame(90, (new ScoreCalculator(['error' => 10, 'warning' => 1, 'notice' => 0]))->score($this->sampleResult(1, 0, 5)));
    }

    public function test_grade_thresholds_and_error_caps(): void
    {
        $calc = new ScoreCalculator;

        $this->assertSame('A+', $calc->grade(100));
        $this->assertSame('A', $calc->grade(92));
        $this->assertSame('B', $calc->grade(95, errors: 1), 'any error caps the grade at B');
        $this->assertSame('D', $calc->grade(90, errors: 5), 'five or more errors cap at D');
        $this->assertSame('F', $calc->grade(40));
        $this->assertSame('C', $calc->grade(72));
    }

    public function test_stats_shape_and_from_config(): void
    {
        config()->set('bfsg.scoring.weights', ['error' => 1, 'warning' => 1, 'notice' => 1]);
        $stats = ScoreCalculator::fromConfig()->stats($this->sampleResult(1, 1, 1));

        $this->assertSame(['total_issues', 'errors', 'warnings', 'notices', 'by_category', 'compliance_score', 'grade'], array_keys($stats));
        $this->assertSame(97, $stats['compliance_score']);
        $this->assertSame(['x' => 3], $stats['by_category']);
        $this->assertSame('B', $stats['grade']);
    }
}
