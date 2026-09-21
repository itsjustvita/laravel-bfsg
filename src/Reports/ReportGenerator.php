<?php

namespace ItsJustVita\LaravelBfsg\Reports;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\View;
use ItsJustVita\LaravelBfsg\AnalysisResult;

class ReportGenerator
{
    protected array $violations = [];

    protected ?AnalysisResult $result = null;

    protected string $url = '';

    protected string $format = 'html';

    protected array $stats = [];

    /**
     * Create a new report generator
     *
     * @param  AnalysisResult|array<string, list<array>>  $violations
     */
    public function __construct(string $url, AnalysisResult|array $violations)
    {
        $this->url = $url;

        if ($violations instanceof AnalysisResult) {
            $this->result = $violations;
            $this->violations = $violations->toArray()['violations'];
        } else {
            $this->violations = $violations;
        }

        $this->calculateStats();
    }

    /**
     * Set report format
     */
    public function setFormat(string $format): self
    {
        $this->format = $format;

        return $this;
    }

    /**
     * Generate the report
     */
    public function generate(): string
    {
        return match ($this->format) {
            'json' => $this->generateJson(),
            'html' => $this->generateHtml(),
            'pdf' => $this->generatePdf(),
            'markdown' => $this->generateMarkdown(),
            default => $this->generateHtml(),
        };
    }

    /**
     * Save report to file
     */
    public function saveToFile(?string $path = null): string
    {
        if ($path === null) {
            $timestamp = now()->format('Y-m-d_His');
            $extension = match ($this->format) {
                'json' => 'json',
                'markdown' => 'md',
                'pdf' => 'pdf',
                default => 'html',
            };
            $path = storage_path("app/bfsg-reports/report_{$timestamp}.{$extension}");
        }

        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, $this->generate());

        return $path;
    }

    /**
     * Generate JSON report
     */
    protected function generateJson(): string
    {
        return json_encode([
            'meta' => [
                'url' => $this->url,
                'timestamp' => now()->toIso8601String(),
                'generator' => 'Laravel BFSG v1.5.0',
            ],
            'stats' => $this->stats,
            'violations' => $this->violations,
            'summary' => [
                'total_issues' => $this->stats['total_issues'],
                'compliance_score' => $this->stats['compliance_score'],
                'passed' => $this->stats['total_issues'] === 0,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Generate HTML report
     */
    protected function generateHtml(): string
    {
        return View::make('bfsg::reports.html', [
            'url' => $this->url,
            'violations' => $this->violations,
            'stats' => $this->stats,
            'timestamp' => now(),
        ])->render();
    }

    /**
     * Generate Markdown report
     */
    protected function generateMarkdown(): string
    {
        $md = "# BFSG Accessibility Report\n\n";
        $md .= "**URL:** {$this->url}\n";
        $md .= '**Date:** '.now()->format('Y-m-d H:i:s')."\n";
        $md .= "**Compliance Score:** {$this->stats['compliance_score']}%\n\n";

        $md .= "## Summary\n\n";
        $md .= "- **Total Issues:** {$this->stats['total_issues']}\n";
        $md .= "- **Errors:** {$this->stats['errors']}\n";
        $md .= "- **Warnings:** {$this->stats['warnings']}\n";
        $md .= "- **Notices:** {$this->stats['notices']}\n\n";

        if ($this->stats['total_issues'] === 0) {
            $md .= "✅ **No accessibility issues found!**\n\n";

            return $md;
        }

        $md .= "## Issues by Category\n\n";

        foreach ($this->violations as $category => $issues) {
            $md .= '### '.ucfirst($category)." ({$this->stats['by_category'][$category]} issues)\n\n";

            foreach ($issues as $idx => $issue) {
                $severity = $issue['type'] ?? $issue['severity'] ?? 'notice';
                $icon = $this->getSeverityIcon($severity);

                $md .= "{$icon} **[{$issue['rule']}]** {$issue['message']}\n";
                if (isset($issue['suggestion'])) {
                    $md .= "   💡 *{$issue['suggestion']}*\n";
                }
                $md .= "\n";
            }
        }

        return $md;
    }

    /**
     * Generate PDF report
     */
    protected function generatePdf(): string
    {
        if (! class_exists(Pdf::class)) {
            throw new \RuntimeException(
                'PDF generation requires barryvdh/laravel-dompdf. Install it with: composer require barryvdh/laravel-dompdf'
            );
        }

        $html = $this->generateHtml();
        $pdf = Pdf::loadHTML($html);

        return $pdf->output();
    }

    /**
     * Calculate statistics
     */
    protected function calculateStats(): void
    {
        $calculator = ScoreCalculator::fromConfig();

        if ($this->result !== null) {
            $this->stats = $calculator->stats($this->result);

            return;
        }

        // Legacy array input: the retired `critical` bucket is counted as an error.
        $counts = ['error' => 0, 'warning' => 0, 'notice' => 0];
        $byCategory = [];

        foreach ($this->violations as $category => $issues) {
            $byCategory[$category] = count($issues);

            foreach ($issues as $issue) {
                $bucket = match ($issue['type'] ?? $issue['severity'] ?? 'notice') {
                    'critical', 'error' => 'error',
                    'warning' => 'warning',
                    default => 'notice',
                };

                $counts[$bucket]++;
            }
        }

        $score = $calculator->score($counts);

        $this->stats = [
            'total_issues' => array_sum($byCategory),
            'errors' => $counts['error'],
            'warnings' => $counts['warning'],
            'notices' => $counts['notice'],
            'by_category' => $byCategory,
            'compliance_score' => $score,
            'grade' => $calculator->grade($score, $counts['error']),
        ];
    }

    /**
     * Get severity icon
     */
    protected function getSeverityIcon(string $severity): string
    {
        return match ($severity) {
            'critical' => '🔴',
            'error' => '❌',
            'warning' => '⚠️',
            default => 'ℹ️',
        };
    }

    /**
     * Get statistics
     */
    public function getStats(): array
    {
        return $this->stats;
    }
}
