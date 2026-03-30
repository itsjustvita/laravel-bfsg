<?php

namespace ItsJustVita\LaravelBfsg\Tests\Feature;

use ItsJustVita\LaravelBfsg\Models\BfsgReport;
use ItsJustVita\LaravelBfsg\Models\BfsgViolation;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DatabasePersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    public function test_can_create_report(): void
    {
        $report = BfsgReport::create([
            'url' => 'https://example.com',
            'total_violations' => 5,
            'score' => 82.50,
            'grade' => 'B',
        ]);

        $this->assertDatabaseHas('bfsg_reports', [
            'url' => 'https://example.com',
            'grade' => 'B',
        ]);
    }

    public function test_can_create_violations(): void
    {
        $report = BfsgReport::create([
            'url' => 'https://example.com',
            'total_violations' => 1,
            'score' => 95,
            'grade' => 'A',
        ]);

        BfsgViolation::create([
            'report_id' => $report->id,
            'analyzer' => 'images',
            'severity' => 'error',
            'message' => 'Image missing alt attribute',
            'element' => '<img src="test.jpg">',
            'wcag_rule' => 'WCAG 1.1.1',
            'suggestion' => 'Add alt attribute',
        ]);

        $this->assertDatabaseHas('bfsg_violations', [
            'analyzer' => 'images',
            'severity' => 'error',
        ]);
    }

    public function test_report_has_many_violations(): void
    {
        $report = BfsgReport::create([
            'url' => 'https://example.com',
            'total_violations' => 2,
            'score' => 90,
            'grade' => 'A',
        ]);

        BfsgViolation::create(['report_id' => $report->id, 'analyzer' => 'images', 'severity' => 'error', 'message' => 'Missing alt']);
        BfsgViolation::create(['report_id' => $report->id, 'analyzer' => 'headings', 'severity' => 'warning', 'message' => 'Skipped heading']);

        $this->assertCount(2, $report->violations);
    }

    public function test_violation_belongs_to_report(): void
    {
        $report = BfsgReport::create(['url' => 'https://example.com', 'total_violations' => 1, 'score' => 95, 'grade' => 'A']);
        $violation = BfsgViolation::create(['report_id' => $report->id, 'analyzer' => 'images', 'severity' => 'error', 'message' => 'Missing alt']);
        $this->assertEquals($report->id, $violation->report->id);
    }

    public function test_cascade_delete(): void
    {
        $report = BfsgReport::create(['url' => 'https://example.com', 'total_violations' => 1, 'score' => 95, 'grade' => 'A']);
        BfsgViolation::create(['report_id' => $report->id, 'analyzer' => 'images', 'severity' => 'error', 'message' => 'Missing alt']);
        $report->delete();
        $this->assertDatabaseMissing('bfsg_violations', ['report_id' => $report->id]);
    }

    public function test_scope_for_url(): void
    {
        BfsgReport::create(['url' => 'https://a.com', 'total_violations' => 1, 'score' => 90, 'grade' => 'A']);
        BfsgReport::create(['url' => 'https://b.com', 'total_violations' => 2, 'score' => 80, 'grade' => 'B']);
        BfsgReport::create(['url' => 'https://a.com', 'total_violations' => 0, 'score' => 100, 'grade' => 'A+']);
        $this->assertCount(2, BfsgReport::forUrl('https://a.com')->get());
    }

    public function test_metadata_cast(): void
    {
        $report = BfsgReport::create([
            'url' => 'https://example.com',
            'total_violations' => 1,
            'score' => 95,
            'grade' => 'A',
            'metadata' => ['compliance_level' => 'AA', 'checks_enabled' => 11],
        ]);
        $report->refresh();
        $this->assertIsArray($report->metadata);
        $this->assertEquals('AA', $report->metadata['compliance_level']);
    }
}
