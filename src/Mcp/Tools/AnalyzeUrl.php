<?php

namespace ItsJustVita\LaravelBfsg\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Reports\ReportGenerator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class AnalyzeUrl extends Tool
{
    protected string $description = 'Fetch a URL and analyze its HTML for WCAG/BFSG accessibility violations.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->description('The URL to fetch and analyze')->required(),
            'verify_ssl' => $schema->boolean()->description('Whether to verify SSL certificates (default: false)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $url = $request->get('url');
        $verifySsl = $request->get('verify_ssl', false);

        if (empty($url)) {
            return Response::error('The url parameter is required.');
        }

        try {
            $response = Http::withOptions(['verify' => $verifySsl])
                ->timeout(30)
                ->withUserAgent('BFSG-MCP/2.1')
                ->get($url);

            if ($response->failed()) {
                return Response::error("Failed to fetch URL: {$url} (HTTP {$response->status()})");
            }
        } catch (\Exception $e) {
            return Response::error("Failed to fetch URL: {$url} - {$e->getMessage()}");
        }

        $bfsg = new Bfsg;
        $violations = $bfsg->analyze($response->body());

        $report = new ReportGenerator($url, $violations);
        $stats = $report->getStats();

        return Response::json([
            'url' => $url,
            'violations' => $violations,
            'total_issues' => $stats['total_issues'],
            'score' => $stats['compliance_score'],
            'grade' => $stats['grade'],
        ]);
    }
}
