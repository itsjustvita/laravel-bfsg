<?php

namespace ItsJustVita\LaravelBfsg\Mcp\Tools;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Models\BfsgReport;
use ItsJustVita\LaravelBfsg\Reports\ReportGenerator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GenerateReport extends Tool
{
    protected string $description = 'Analyze a URL and generate a formatted accessibility report. Supports json, html, markdown, and pdf formats. Optionally saves results to database.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->description('The URL to analyze')->required(),
            'format' => $schema->string()->description('Report format: json, html, markdown, or pdf (default: json)'),
            'save' => $schema->boolean()->description('Save results to database (default: false)'),
            'verify_ssl' => $schema->boolean()->description('Whether to verify SSL certificates (default: false)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $url = $request->get('url');
        $format = $request->get('format', 'json');
        $save = $request->get('save', false);
        $verifySsl = $request->get('verify_ssl', false);

        if (empty($url)) {
            return Response::error('The url parameter is required.');
        }

        $validFormats = ['json', 'html', 'markdown', 'pdf'];
        if (! in_array($format, $validFormats)) {
            return Response::error("Invalid format: {$format}. Use one of: ".implode(', ', $validFormats));
        }

        if ($format === 'pdf' && ! class_exists(Pdf::class)) {
            return Response::error('PDF format requires barryvdh/laravel-dompdf. Install with: composer require barryvdh/laravel-dompdf');
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

        $reportGenerator = new ReportGenerator($url, $violations);
        $stats = $reportGenerator->getStats();
        $reportContent = $reportGenerator->setFormat($format)->generate();

        $result = [
            'stats' => [
                'total_issues' => $stats['total_issues'],
                'score' => $stats['compliance_score'],
                'grade' => $stats['grade'],
            ],
            'format' => $format,
        ];

        if ($format === 'pdf') {
            $path = $reportGenerator->saveToFile();
            $result['report'] = "PDF saved to: {$path}";
        } else {
            $result['report'] = $reportContent;
        }

        if ($save) {
            try {
                $dbReport = BfsgReport::create([
                    'url' => $url,
                    'total_violations' => $stats['total_issues'],
                    'score' => $stats['compliance_score'],
                    'grade' => $stats['grade'],
                ]);

                foreach ($violations as $analyzer => $issues) {
                    foreach ($issues as $issue) {
                        $dbReport->violations()->create([
                            'analyzer' => $analyzer,
                            'severity' => $issue['type'] ?? $issue['severity'] ?? 'notice',
                            'message' => $issue['message'],
                            'element' => $issue['element'] ?? null,
                            'wcag_rule' => $issue['rule'] ?? null,
                            'suggestion' => $issue['suggestion'] ?? null,
                        ]);
                    }
                }

                $result['report_id'] = $dbReport->id;
            } catch (\Exception $e) {
                $result['save_error'] = 'Failed to save to database: '.$e->getMessage();
            }
        }

        return Response::json($result);
    }
}
