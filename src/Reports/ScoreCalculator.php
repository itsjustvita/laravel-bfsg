<?php

namespace ItsJustVita\LaravelBfsg\Reports;

use ItsJustVita\LaravelBfsg\AnalysisResult;

final class ScoreCalculator
{
    /** @param  array{error: float|int, warning: float|int, notice: float|int}  $weights */
    public function __construct(private array $weights = ['error' => 5, 'warning' => 2, 'notice' => 0.5]) {}

    public static function fromConfig(): self
    {
        $weights = function_exists('config') ? (array) config('bfsg.scoring.weights', []) : [];

        return new self(array_merge(['error' => 5, 'warning' => 2, 'notice' => 0.5], $weights));
    }

    /** @param  AnalysisResult|array{error: int, warning: int, notice: int}  $input */
    public function score(AnalysisResult|array $input): int
    {
        $counts = $input instanceof AnalysisResult ? $input->countBySeverity() : $input;

        $penalty = ($counts['error'] ?? 0) * $this->weights['error']
            + ($counts['warning'] ?? 0) * $this->weights['warning']
            + ($counts['notice'] ?? 0) * $this->weights['notice'];

        return (int) max(0, min(100, round(100 - $penalty)));
    }

    public function grade(int $score, int $errors = 0): string
    {
        $grade = match (true) {
            $score >= 95 => 'A+',
            $score >= 90 => 'A',
            $score >= 85 => 'B+',
            $score >= 80 => 'B',
            $score >= 75 => 'C+',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default => 'F',
        };

        $order = ['A+', 'A', 'B+', 'B', 'C+', 'C', 'D', 'F'];
        $cap = match (true) {
            $errors >= 5 => 'D',
            $errors >= 1 => 'B',
            default => null,
        };

        if ($cap !== null && array_search($grade, $order, true) < array_search($cap, $order, true)) {
            return $cap;
        }

        return $grade;
    }

    /** @return array{total_issues: int, errors: int, warnings: int, notices: int, by_category: array<string, int>, compliance_score: int, grade: string} */
    public function stats(AnalysisResult $result): array
    {
        $counts = $result->countBySeverity();
        $score = $this->score($counts);

        return [
            'total_issues' => $result->count(),
            'errors' => $counts['error'],
            'warnings' => $counts['warning'],
            'notices' => $counts['notice'],
            'by_category' => array_map('count', $result->byAnalyzer()),
            'compliance_score' => $score,
            'grade' => $this->grade($score, $counts['error']),
        ];
    }
}
