<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\AnalysisResult;
use ItsJustVita\LaravelBfsg\Reports\ReportGenerator;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use ItsJustVita\LaravelBfsg\Violation;

class PdfReportTest extends TestCase
{
    private function sampleResult(): AnalysisResult
    {
        return new AnalysisResult([
            'images' => [new Violation('images', 'images.missing_alt', Severity::Error, '1.1.1', ['src' => 'a.jpg'], 'img', '/html[1]/body[1]/img[1]', '<img src="a.jpg">')],
        ], ['images'], 'https://example.com');
    }

    public function test_generates_pdf_when_dompdf_available(): void
    {
        $this->assertStringStartsWith('%PDF', (new ReportGenerator($this->sampleResult()))->format('pdf')->render());
    }

    public function test_pdf_save_to_file(): void
    {
        $path = storage_path('app/bfsg-reports/test-report.pdf');
        $savedPath = (new ReportGenerator($this->sampleResult()))->format('pdf')->saveTo($path);

        try {
            $this->assertSame($path, $savedPath);
            $this->assertStringStartsWith('%PDF', (string) file_get_contents($savedPath));
        } finally {
            unlink($savedPath);
        }
    }

    public function test_empty_result_pdf(): void
    {
        $this->assertStringStartsWith('%PDF', (new ReportGenerator(new AnalysisResult([], [], 'https://example.com')))->format('pdf')->render());
    }
}
