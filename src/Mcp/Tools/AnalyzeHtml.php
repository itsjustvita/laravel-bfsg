<?php

namespace ItsJustVita\LaravelBfsg\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Reports\ReportGenerator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class AnalyzeHtml extends Tool
{
    protected string $name = 'analyze_html';

    protected string $description = 'Analyze raw HTML for WCAG/BFSG accessibility violations. Returns violations grouped by analyzer, compliance score (0-100), and letter grade (A+ to F).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'html' => $schema->string()->description('The HTML content to analyze for accessibility issues')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $html = $request->get('html');

        if (empty($html)) {
            return Response::error('The html parameter is required.');
        }

        $result = app(Bfsg::class)->analyze($html);
        $summary = (new ReportGenerator($result))->summary();

        return Response::json([
            'violations' => $result->toArray()['violations'],
            'total_issues' => $summary['total'],
            'score' => $summary['score'],
            'grade' => $summary['grade'],
        ]);
    }
}
