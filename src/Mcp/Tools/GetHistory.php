<?php

namespace ItsJustVita\LaravelBfsg\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use ItsJustVita\LaravelBfsg\Models\BfsgReport;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetHistory extends Tool
{
    protected string $description = 'Retrieve stored accessibility check reports from the database. Optionally filter by URL.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->description('Filter reports by URL'),
            'limit' => $schema->integer()->description('Maximum number of reports to return (default: 20)'),
        ];
    }

    public function handle(Request $request): Response
    {
        try {
            $query = BfsgReport::query()->latest();

            $url = $request->get('url');
            if (! empty($url)) {
                $query->forUrl($url);
            }

            $limit = $request->get('limit', 20);
            $reports = $query->limit((int) $limit)->get();

            if ($reports->isEmpty()) {
                return Response::json(['message' => 'No reports found.', 'reports' => []]);
            }

            $result = $reports->map(fn ($r) => [
                'id' => $r->id,
                'url' => $r->url,
                'total_violations' => $r->total_violations,
                'score' => $r->score,
                'grade' => $r->grade,
                'created_at' => $r->created_at->toIso8601String(),
            ])->toArray();

            return Response::json(['reports' => $result]);
        } catch (\Exception $e) {
            return Response::error('Database error: '.$e->getMessage().'. Make sure you have run: php artisan vendor:publish --tag=bfsg-migrations && php artisan migrate');
        }
    }
}
