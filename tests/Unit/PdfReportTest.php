<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Reports\ReportGenerator;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class PdfReportTest extends TestCase
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
        ];
    }

    public function test_generates_pdf_when_dompdf_available(): void
    {
        $report = new ReportGenerator('https://example.com', $this->sampleViolations());
        $pdf = $report->setFormat('pdf')->generate();
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_pdf_save_to_file(): void
    {
        $report = new ReportGenerator('https://example.com', $this->sampleViolations());
        $report->setFormat('pdf');
        $path = storage_path('app/bfsg-reports/test-report.pdf');
        $savedPath = $report->saveToFile($path);
        $this->assertFileExists($savedPath);
        $content = file_get_contents($savedPath);
        $this->assertStringStartsWith('%PDF', $content);
        unlink($savedPath);
    }

    public function test_empty_violations_pdf(): void
    {
        $report = new ReportGenerator('https://example.com', []);
        $pdf = $report->setFormat('pdf')->generate();
        $this->assertStringStartsWith('%PDF', $pdf);
    }
}
