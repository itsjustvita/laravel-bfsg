<?php

namespace ItsJustVita\LaravelBfsg\Reports;

use Illuminate\Support\Facades\View;

class ReportGenerator
{
    protected array $violations = [];
    protected string $url = '';
    protected string $format = 'html';
    protected array $stats = [];

    /**
     * Create a new report generator
     */
    public function __construct(string $url, array $violations)
    {
        $this->url = $url;
        $this->violations = $violations;
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
    public function saveToFile(string $path = null): string
    {
        if ($path === null) {
            $timestamp = now()->format('Y-m-d_His');
            $extension = $this->format === 'json' ? 'json' : 'html';
            $path = storage_path("app/bfsg-reports/report_{$timestamp}.{$extension}");
        }

        $directory = dirname($path);
        if (!is_dir($directory)) {
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
        $md .= "**Date:** " . now()->format('Y-m-d H:i:s') . "\n";
        $md .= "**Compliance Score:** {$this->stats['compliance_score']}%\n\n";

        $md .= "## Summary\n\n";
        $md .= "- **Total Issues:** {$this->stats['total_issues']}\n";
        $md .= "- **Critical:** {$this->stats['critical']}\n";
        $md .= "- **Errors:** {$this->stats['errors']}\n";
        $md .= "- **Warnings:** {$this->stats['warnings']}\n";
        $md .= "- **Notices:** {$this->stats['notices']}\n\n";

        if ($this->stats['total_issues'] === 0) {
            $md .= "✅ **No accessibility issues found!**\n\n";
            return $md;
        }

        $md .= "## Issues by Category\n\n";

        foreach ($this->violations as $category => $issues) {
            $md .= "### " . ucfirst($category) . " ({$this->stats['by_category'][$category]} issues)\n\n";

            foreach ($issues as $idx => $issue) {
                $severity = $issue['severity'] ?? 'notice';
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
     * Generate PDF report (placeholder - would need PDF library)
     */
    protected function generatePdf(): string
    {
        // This would require a PDF library like DomPDF or wkhtmltopdf
        // For now, return HTML that can be printed to PDF
        return $this->generateHtml();
    }

    /**
     * Calculate statistics
     */
    protected function calculateStats(): void
    {
        $totalIssues = 0;
        $critical = 0;
        $errors = 0;
        $warnings = 0;
        $notices = 0;
        $byCategory = [];

        foreach ($this->violations as $category => $issues) {
            $count = count($issues);
            $totalIssues += $count;
            $byCategory[$category] = $count;

            foreach ($issues as $issue) {
                $severity = $issue['severity'] ?? 'notice';
                match ($severity) {
                    'critical' => $critical++,
                    'error' => $errors++,
                    'warning' => $warnings++,
                    default => $notices++,
                };
            }
        }

        // Calculate compliance score (0-100%)
        // Simple formula: max(0, 100 - (critical * 10 + errors * 5 + warnings * 2 + notices * 0.5))
        $score = 100 - ($critical * 10 + $errors * 5 + $warnings * 2 + $notices * 0.5);
        $complianceScore = max(0, min(100, (int) $score));

        $this->stats = [
            'total_issues' => $totalIssues,
            'critical' => $critical,
            'errors' => $errors,
            'warnings' => $warnings,
            'notices' => $notices,
            'by_category' => $byCategory,
            'compliance_score' => $complianceScore,
            'grade' => $this->getGrade($complianceScore),
        ];
    }

    /**
     * Get letter grade based on score
     */
    protected function getGrade(int $score): string
    {
        return match (true) {
            $score >= 95 => 'A+',
            $score >= 90 => 'A',
            $score >= 85 => 'B+',
            $score >= 80 => 'B',
            $score >= 75 => 'C+',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default => 'F',
        };
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
