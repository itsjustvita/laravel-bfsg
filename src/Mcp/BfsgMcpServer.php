<?php

namespace ItsJustVita\LaravelBfsg\Mcp;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelBfsg\Analyzers\ContrastAnalyzer;
use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Models\BfsgReport;
use ItsJustVita\LaravelBfsg\Reports\ReportGenerator;
use Mcp\Server\McpServer;

class BfsgMcpServer
{
    public function create(): McpServer
    {
        $server = new McpServer('laravel-bfsg');

        $this->registerAnalyzeHtml($server);
        $this->registerAnalyzeUrl($server);
        $this->registerCheckContrast($server);
        $this->registerListAnalyzers($server);
        $this->registerGetHistory($server);
        $this->registerGetReport($server);
        $this->registerGenerateReport($server);

        return $server;
    }

    protected function registerAnalyzeHtml(McpServer $server): void
    {
        $server->tool(
            'analyze_html',
            'Analyze raw HTML for WCAG/BFSG accessibility violations. Returns violations grouped by analyzer, compliance score, and grade.',
            function (string $html): string {
                $bfsg = new Bfsg;
                $violations = $bfsg->analyze($html);

                $report = new ReportGenerator('inline-html', $violations);
                $stats = $report->getStats();

                return json_encode([
                    'violations' => $violations,
                    'total_issues' => $stats['total_issues'],
                    'score' => $stats['compliance_score'],
                    'grade' => $stats['grade'],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
        );
    }

    protected function registerAnalyzeUrl(McpServer $server): void
    {
        $server->tool(
            'analyze_url',
            'Fetch a URL and analyze its HTML for WCAG/BFSG accessibility violations.',
            function (string $url, bool $verify_ssl = false): string {
                $response = Http::withOptions(['verify' => $verify_ssl])
                    ->timeout(30)
                    ->withUserAgent('BFSG-MCP/2.0')
                    ->get($url);

                if ($response->failed()) {
                    throw new \InvalidArgumentException("Failed to fetch URL: {$url} (HTTP {$response->status()})");
                }

                $html = $response->body();
                $bfsg = new Bfsg;
                $violations = $bfsg->analyze($html);

                $report = new ReportGenerator($url, $violations);
                $stats = $report->getStats();

                return json_encode([
                    'url' => $url,
                    'violations' => $violations,
                    'total_issues' => $stats['total_issues'],
                    'score' => $stats['compliance_score'],
                    'grade' => $stats['grade'],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
        );
    }

    protected function registerCheckContrast(McpServer $server): void
    {
        $server->tool(
            'check_contrast',
            'Check the contrast ratio between a foreground and background color. Returns ratio and WCAG AA/AAA pass/fail status.',
            function (string $foreground, string $background): string {
                $analyzer = new ContrastAnalyzer;
                $ratio = $analyzer->calculateContrastRatio($foreground, $background);

                if ($ratio === null) {
                    throw new \InvalidArgumentException('Could not calculate contrast ratio. Check that colors are valid (hex, rgb, or named colors).');
                }

                return json_encode([
                    'foreground' => $foreground,
                    'background' => $background,
                    'ratio' => round($ratio, 2),
                    'ratio_formatted' => number_format($ratio, 2).':1',
                    'aa_normal' => $ratio >= 4.5,
                    'aa_large' => $ratio >= 3.0,
                    'aaa_normal' => $ratio >= 7.0,
                    'aaa_large' => $ratio >= 4.5,
                ], JSON_PRETTY_PRINT);
            }
        );
    }

    protected function registerListAnalyzers(McpServer $server): void
    {
        $server->tool(
            'list_analyzers',
            'List all available BFSG/WCAG accessibility analyzers with their enabled status.',
            function (): string {
                $checks = config('bfsg.checks', []);

                $analyzers = [
                    ['name' => 'images', 'wcag_rules' => '1.1.1', 'description' => 'Image alt text validation'],
                    ['name' => 'forms', 'wcag_rules' => '1.3.1, 4.1.2', 'description' => 'Form label and ARIA validation'],
                    ['name' => 'headings', 'wcag_rules' => '1.3.1, 2.4.6', 'description' => 'Heading hierarchy and structure'],
                    ['name' => 'contrast', 'wcag_rules' => '1.4.3, 1.4.6', 'description' => 'Color contrast ratios with CSS parsing'],
                    ['name' => 'aria', 'wcag_rules' => '4.1.2', 'description' => 'ARIA roles, attributes, and references'],
                    ['name' => 'links', 'wcag_rules' => '2.4.4, 2.4.9', 'description' => 'Link text and purpose'],
                    ['name' => 'keyboard', 'wcag_rules' => '2.1.1, 2.4.7', 'description' => 'Keyboard navigation and focus management'],
                    ['name' => 'language', 'wcag_rules' => '3.1.1, 3.1.2', 'description' => 'Language attributes (BFSG S3)'],
                    ['name' => 'tables', 'wcag_rules' => '1.3.1', 'description' => 'Table captions, headers, and scope'],
                    ['name' => 'media', 'wcag_rules' => '1.2.1-1.2.5', 'description' => 'Video captions, audio transcripts'],
                    ['name' => 'semantic', 'wcag_rules' => '1.3.1, 4.1.1', 'description' => 'Semantic HTML landmarks and structure'],
                    ['name' => 'page_title', 'wcag_rules' => '2.4.2', 'description' => 'Page title presence and quality'],
                    ['name' => 'input_purpose', 'wcag_rules' => '1.3.5', 'description' => 'Input autocomplete attributes'],
                    ['name' => 'focus', 'wcag_rules' => '2.4.7', 'description' => 'Focus indicator visibility'],
                    ['name' => 'error_handling', 'wcag_rules' => '3.3.1, 3.3.3', 'description' => 'Form error identification'],
                    ['name' => 'status_messages', 'wcag_rules' => '4.1.3', 'description' => 'ARIA live regions for status updates'],
                ];

                foreach ($analyzers as &$analyzer) {
                    $analyzer['enabled'] = $checks[$analyzer['name']] ?? true;
                }

                return json_encode($analyzers, JSON_PRETTY_PRINT);
            }
        );
    }

    protected function registerGetHistory(McpServer $server): void
    {
        $server->tool(
            'get_history',
            'Retrieve stored accessibility check reports from the database. Optionally filter by URL.',
            function (string $url = '', int $limit = 20): string {
                $query = BfsgReport::query()->latest();

                if ($url !== '') {
                    $query->forUrl($url);
                }

                $reports = $query->limit($limit)->get();

                if ($reports->isEmpty()) {
                    return json_encode(['message' => 'No reports found.', 'reports' => []], JSON_PRETTY_PRINT);
                }

                $result = $reports->map(fn ($r) => [
                    'id' => $r->id,
                    'url' => $r->url,
                    'total_violations' => $r->total_violations,
                    'score' => $r->score,
                    'grade' => $r->grade,
                    'created_at' => $r->created_at->toIso8601String(),
                ])->toArray();

                return json_encode(['reports' => $result], JSON_PRETTY_PRINT);
            }
        );
    }

    protected function registerGetReport(McpServer $server): void
    {
        $server->tool(
            'get_report',
            'Get a single stored accessibility report with all its violations.',
            function (int $report_id): string {
                $report = BfsgReport::with('violations')->find($report_id);

                if (! $report) {
                    throw new \InvalidArgumentException("Report #{$report_id} not found.");
                }

                return json_encode([
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
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
        );
    }

    protected function registerGenerateReport(McpServer $server): void
    {
        $server->tool(
            'generate_report',
            'Analyze a URL and generate a formatted accessibility report (json, html, markdown, or pdf).',
            function (string $url, string $format = 'json', bool $save = false, bool $verify_ssl = false): string {
                $validFormats = ['json', 'html', 'markdown', 'pdf'];
                if (! in_array($format, $validFormats)) {
                    throw new \InvalidArgumentException("Invalid format: {$format}. Use one of: ".implode(', ', $validFormats));
                }

                if ($format === 'pdf' && ! class_exists(Pdf::class)) {
                    throw new \InvalidArgumentException('PDF format requires barryvdh/laravel-dompdf. Install with: composer require barryvdh/laravel-dompdf');
                }

                $response = Http::withOptions(['verify' => $verify_ssl])
                    ->timeout(30)
                    ->withUserAgent('BFSG-MCP/2.0')
                    ->get($url);

                if ($response->failed()) {
                    throw new \InvalidArgumentException("Failed to fetch URL: {$url} (HTTP {$response->status()})");
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
                                'severity' => $issue['severity'] ?? 'notice',
                                'message' => $issue['message'],
                                'element' => $issue['element'] ?? null,
                                'wcag_rule' => $issue['rule'] ?? null,
                                'suggestion' => $issue['suggestion'] ?? null,
                            ]);
                        }
                    }

                    $result['report_id'] = $dbReport->id;
                }

                return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
        );
    }
}
