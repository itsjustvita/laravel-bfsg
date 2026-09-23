<?php

namespace ItsJustVita\LaravelBfsg\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use ItsJustVita\LaravelBfsg\Models\BfsgReport;
use ItsJustVita\LaravelBfsg\Models\BfsgViolation;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[IsReadOnly]
class GetReport extends Tool
{
    protected string $name = 'get_report';

    protected string $description = 'Get one stored accessibility report with all its findings (key, severity, WCAG rule, message, element, fingerprint, selector, snippet).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'report_id' => $schema->integer()->description('The id of the report')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $id = filter_var($request->get('report_id'), FILTER_VALIDATE_INT);

        if ($id === false || $id < 1) {
            return Response::error('The report_id parameter must be a positive integer.');
        }

        try {
            $report = BfsgReport::with('violations')->find($id);
        } catch (Throwable $e) {
            return Response::error('Database error: '.$e->getMessage().'. Run php artisan migrate.');
        }

        if ($report === null) {
            return Response::error("Report #{$id} not found.");
        }

        return Response::json([
            'report' => [
                'id' => $report->id,
                'url' => $report->url,
                'total_violations' => $report->total_violations,
                'score' => (int) round($report->score),
                'grade' => $report->grade,
                'metadata' => $report->metadata,
                'created_at' => $report->created_at?->toIso8601String(),
            ],
            'violations' => $report->violations->map(fn (BfsgViolation $violation) => [
                'key' => $violation->key,
                'analyzer' => $violation->analyzer,
                'severity' => $violation->severity,
                'rule' => $violation->wcag_rule,
                'message' => $violation->message,
                'suggestion' => $violation->suggestion,
                'element' => $violation->element,
                'fingerprint' => $violation->fingerprint,
                'selector' => $violation->context['selector'] ?? null,
                'snippet' => $violation->context['snippet'] ?? null,
            ])->values()->all(),
        ]);
    }
}
