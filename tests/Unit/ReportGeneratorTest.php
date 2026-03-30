<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Reports\ReportGenerator;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class ReportGeneratorTest extends TestCase
{
    protected function sampleViolations(): array
    {
        return [
            'images' => [
                [
                    'type' => 'error',
                    'severity' => 'error',
                    'rule' => 'WCAG 1.1.1',
                    'message' => 'Image missing alt attribute',
                    'element' => 'img',
                    'suggestion' => 'Add an alt attribute',
                ],
            ],
            'headings' => [
                [
                    'type' => 'warning',
                    'severity' => 'warning',
                    'rule' => 'WCAG 1.3.1',
                    'message' => 'Heading hierarchy skipped',
                    'element' => 'h3',
                    'suggestion' => 'Use proper heading levels',
                ],
            ],
        ];
    }

    protected function mixedSeverityViolations(): array
    {
        return [
            'images' => [
                [
                    'type' => 'critical',
                    'severity' => 'critical',
                    'rule' => 'WCAG 1.1.1',
                    'message' => 'Critical image issue',
                    'element' => 'img',
                    'suggestion' => 'Fix critical issue',
                ],
                [
                    'type' => 'error',
                    'severity' => 'error',
                    'rule' => 'WCAG 1.1.2',
                    'message' => 'Error image issue',
                    'element' => 'img',
                    'suggestion' => 'Fix error issue',
                ],
            ],
            'headings' => [
                [
                    'type' => 'warning',
                    'severity' => 'warning',
                    'rule' => 'WCAG 1.3.1',
                    'message' => 'Warning heading issue',
                    'element' => 'h3',
                    'suggestion' => 'Fix warning issue',
                ],
                [
                    'type' => 'notice',
                    'severity' => 'notice',
                    'rule' => 'WCAG 2.4.6',
                    'message' => 'Notice heading issue',
                    'element' => 'h4',
                    'suggestion' => 'Fix notice issue',
                ],
            ],
        ];
    }

    public function test_generates_json_report(): void
    {
        $generator = new ReportGenerator('https://example.com', $this->sampleViolations());
        $output = $generator->setFormat('json')->generate();

        $data = json_decode($output, true);
        $this->assertNotNull($data, 'Output should be valid JSON');

        $this->assertArrayHasKey('meta', $data);
        $this->assertArrayHasKey('stats', $data);
        $this->assertArrayHasKey('violations', $data);
        $this->assertArrayHasKey('summary', $data);

        $this->assertEquals('https://example.com', $data['meta']['url']);
        $this->assertEquals(2, $data['stats']['total_issues']);
    }

    public function test_generates_html_report(): void
    {
        $generator = new ReportGenerator('https://example.com', $this->sampleViolations());
        $output = $generator->setFormat('html')->generate();

        $this->assertStringContainsString('https://example.com', $output);
        $this->assertStringContainsString('WCAG 1.1.1', $output);
        $this->assertStringContainsString('Image missing alt attribute', $output);
    }

    public function test_generates_markdown_report(): void
    {
        $generator = new ReportGenerator('https://example.com', $this->sampleViolations());
        $output = $generator->setFormat('markdown')->generate();

        $this->assertStringContainsString('# BFSG Accessibility Report', $output);
        $this->assertStringContainsString('https://example.com', $output);
        $this->assertStringContainsString('Total Issues', $output);
        $this->assertStringContainsString('WCAG 1.1.1', $output);
    }

    public function test_pdf_falls_back_to_html(): void
    {
        $generator = new ReportGenerator('https://example.com', $this->sampleViolations());

        $pdfOutput = $generator->setFormat('pdf')->generate();
        $htmlOutput = $generator->setFormat('html')->generate();

        $this->assertEquals($htmlOutput, $pdfOutput);
    }

    public function test_calculates_stats_correctly(): void
    {
        $generator = new ReportGenerator('https://example.com', $this->mixedSeverityViolations());
        $stats = $generator->getStats();

        $this->assertEquals(4, $stats['total_issues']);
        $this->assertEquals(1, $stats['critical']);
        $this->assertEquals(1, $stats['errors']);
        $this->assertEquals(1, $stats['warnings']);
        $this->assertEquals(1, $stats['notices']);

        // Score = 100 - (10 + 5 + 2 + 0.5) = 82.5, cast to int = 82
        $this->assertEquals(82, $stats['compliance_score']);
        $this->assertEquals('B', $stats['grade']);
    }

    public function test_empty_violations_gives_perfect_score(): void
    {
        $generator = new ReportGenerator('https://example.com', []);
        $stats = $generator->getStats();

        $this->assertEquals(0, $stats['total_issues']);
        $this->assertEquals(100, $stats['compliance_score']);
        $this->assertEquals('A+', $stats['grade']);
    }

    public function test_grade_assignment_f(): void
    {
        $criticalViolations = [
            'images' => array_fill(0, 15, [
                'type' => 'critical',
                'severity' => 'critical',
                'rule' => 'WCAG 1.1.1',
                'message' => 'Critical issue',
                'element' => 'img',
                'suggestion' => 'Fix it',
            ]),
        ];

        $generator = new ReportGenerator('https://example.com', $criticalViolations);
        $stats = $generator->getStats();

        // Score = 100 - (15 * 10) = -50, clamped to 0
        $this->assertEquals(0, $stats['compliance_score']);
        $this->assertEquals('F', $stats['grade']);
    }

    public function test_save_to_file(): void
    {
        $generator = new ReportGenerator('https://example.com', $this->sampleViolations());
        $generator->setFormat('json');

        $tempPath = sys_get_temp_dir() . '/bfsg_test_report_' . uniqid() . '.json';

        try {
            $savedPath = $generator->saveToFile($tempPath);

            $this->assertEquals($tempPath, $savedPath);
            $this->assertFileExists($tempPath);

            $content = file_get_contents($tempPath);
            $data = json_decode($content, true);
            $this->assertNotNull($data, 'Saved file should contain valid JSON');
            $this->assertArrayHasKey('meta', $data);
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    public function test_markdown_with_no_violations_shows_success(): void
    {
        $generator = new ReportGenerator('https://example.com', []);
        $output = $generator->setFormat('markdown')->generate();

        $this->assertStringContainsString('No accessibility issues found', $output);
    }
}
