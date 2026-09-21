<?php

namespace ItsJustVita\LaravelBfsg\Persistence;

use ItsJustVita\LaravelBfsg\AnalysisResult;
use ItsJustVita\LaravelBfsg\Models\BfsgReport;
use ItsJustVita\LaravelBfsg\Reports\ScoreCalculator;
use ItsJustVita\LaravelBfsg\Violation;

final class ReportRepository
{
    public function __construct(private ?ScoreCalculator $scores = null)
    {
        $this->scores ??= ScoreCalculator::fromConfig();
    }

    /** @param  array<string, mixed>  $metadata  merged into the report's metadata column */
    public function store(AnalysisResult $result, array $metadata = []): BfsgReport
    {
        $stats = $this->scores->stats($result);

        $report = BfsgReport::create([
            'url' => (string) ($result->url() ?? ''),
            'total_violations' => $stats['total_issues'],
            'score' => $stats['compliance_score'],
            'grade' => $stats['grade'],
            'metadata' => array_merge(['compliance_level' => config('bfsg.compliance_level', 'AA')], $metadata),
        ]);

        foreach ($result->all() as $violation) {
            $report->violations()->create($this->row($violation, $result->locale()));
        }

        return $report;
    }

    /** @return array<string, mixed> */
    private function row(Violation $violation, ?string $locale): array
    {
        return [
            'analyzer' => $violation->analyzer,
            'severity' => $violation->severity->value,
            'message' => $violation->message($locale),
            'element' => $violation->element,
            'wcag_rule' => $violation->rule,
            'suggestion' => $violation->suggestion($locale),
        ];
    }
}
