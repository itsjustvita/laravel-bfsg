<?php

namespace ItsJustVita\LaravelBfsg\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use ItsJustVita\LaravelBfsg\Models\BfsgReport;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetReport extends Tool
{
    protected string $description = 'Get a single stored accessibility report with all its violations.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'report_id' => $schema->integer()->description('The ID of the report to retrieve')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $reportId = $request->get('report_id');

        if (empty($reportId)) {
            return Response::error('The report_id parameter is required.');
        }

        try {
            $report = BfsgReport::with('violations')->find((int) $reportId);

            if (! $report) {
                return Response::error("Report #{$reportId} not found.");
            }

            return Response::json([
                'report' => [
                    'id' => $report->id,
                    'url' => $report->url,
                    'total_violations' => $report->total_violations,
                    'score' => $report->score,
                    'grade' => $report->grade,
                    'metadata' => $report->metadata,
                    'created_at' => $report->created_at->toIso8601String(),
                ],
                'violations' => $report->violations->map(fn ($v) => [
                    'analyzer' => $v->analyzer,
                    'severity' => $v->severity,
                    'message' => $v->message,
                    'element' => $v->element,
                    'wcag_rule' => $v->wcag_rule,
                    'suggestion' => $v->suggestion,
                ])->toArray(),
            ]);
        } catch (\Exception $e) {
            return Response::error('Database error: '.$e->getMessage());
        }
    }
}
