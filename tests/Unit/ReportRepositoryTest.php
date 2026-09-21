<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use ItsJustVita\LaravelBfsg\AnalysisResult;
use ItsJustVita\LaravelBfsg\Models\BfsgReport;
use ItsJustVita\LaravelBfsg\Persistence\ReportRepository;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use ItsJustVita\LaravelBfsg\Violation;

class ReportRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_stores_report_and_violations(): void
    {
        $result = new AnalysisResult([
            'images' => [new Violation('images', 'images.missing_alt', Severity::Error, '1.1.1', ['src' => 'a.jpg'], 'img', '/html[1]/body[1]/img[1]', '<img src="a.jpg">')],
            'headings' => [new Violation('headings', 'headings.missing_h1', Severity::Notice, '1.3.1')],
        ], ['images', 'headings'], 'https://example.com/');

        $report = (new ReportRepository)->store($result, ['source' => 'test']);

        $this->assertInstanceOf(BfsgReport::class, $report);
        $this->assertSame('https://example.com/', $report->url);
        $this->assertSame(2, $report->total_violations);
        $this->assertSame(95, (int) $report->score);
        $this->assertSame('B', $report->grade);
        $this->assertSame('test', $report->metadata['source']);
        $this->assertSame('AA', $report->metadata['compliance_level']);

        $violation = $report->violations()->where('analyzer', 'images')->first();
        $this->assertSame('error', $violation->severity);
        $this->assertSame('1.1.1', $violation->wcag_rule);
        $this->assertSame('img', $violation->element);
        $this->assertNotSame('', $violation->message);
    }

    public function test_container_resolution_honours_configured_weights(): void
    {
        config()->set('bfsg.scoring.weights', ['error' => 50, 'warning' => 2, 'notice' => 0.5]);

        $result = new AnalysisResult([
            'images' => [new Violation('images', 'images.missing_alt', Severity::Error, '1.1.1')],
        ], ['images'], 'https://example.com/');

        $report = app(ReportRepository::class)->store($result);

        $this->assertSame(50, (int) $report->score, 'the container-resolved repository must score with the configured weights');
    }

    public function test_uses_configured_connection_name(): void
    {
        config()->set('bfsg.reporting.database.connection', 'testing');

        $this->assertSame('testing', (new BfsgReport)->getConnectionName());

        config()->set('bfsg.reporting.database.connection', null);
        $this->assertNull((new BfsgReport)->getConnectionName());
    }
}
