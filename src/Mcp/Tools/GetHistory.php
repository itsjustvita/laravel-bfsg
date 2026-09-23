<?php

namespace ItsJustVita\LaravelBfsg\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use ItsJustVita\LaravelBfsg\Mcp\Tools\Concerns\ToolHelpers;
use ItsJustVita\LaravelBfsg\Models\BfsgReport;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[IsReadOnly]
class GetHistory extends Tool
{
    use ToolHelpers;

    protected string $name = 'get_history';

    protected string $description = 'List stored accessibility reports, newest first, optionally for one URL.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->description('Only reports of this exact URL'),
            'limit' => $schema->integer()->description('Maximum number of reports, 1-100 (default: 20)'),
        ];
    }

    public function handle(Request $request): Response
    {
        try {
            $query = BfsgReport::query()->latest()->latest('id');

            if (($url = $this->stringArgument($request, 'url')) !== null) {
                $query->forUrl($url);
            }

            $reports = $query->limit(max(1, min(100, (int) $request->get('limit', 20))))->get();
        } catch (Throwable $e) {
            return Response::error('Database error: '.$e->getMessage().'. Run php artisan migrate.');
        }

        return Response::json(['reports' => $reports->map(fn (BfsgReport $report) => [
            'id' => $report->id,
            'url' => $report->url,
            'total_violations' => $report->total_violations,
            'score' => (int) round($report->score),
            'grade' => $report->grade,
            'created_at' => $report->created_at?->toIso8601String(),
        ])->values()->all()]);
    }
}
