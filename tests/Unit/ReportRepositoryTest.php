<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use ItsJustVita\LaravelBfsg\AnalysisResult;
use ItsJustVita\LaravelBfsg\Models\BfsgReport;
use ItsJustVita\LaravelBfsg\Models\BfsgViolation;
use ItsJustVita\LaravelBfsg\Persistence\ReportRepository;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use ItsJustVita\LaravelBfsg\Violation;

class ReportRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function sampleResult(): AnalysisResult
    {
        return new AnalysisResult([
            'images' => [new Violation('images', 'images.missing_alt', Severity::Error, '1.1.1', ['src' => 'a.jpg'], 'img', '/html[1]/body[1]/img[1]', '<img src="a.jpg">', ['approximate' => false], ['1.3.1'], ['best-practice'])],
            'links' => [new Violation('links', 'links.missing_noopener', Severity::Notice, null, ['href' => 'https://x.test'], 'a', '/html[1]/body[1]/a[1]', null, [], [], ['security'], true)],
        ], ['images', 'links'], 'https://example.com/', 'de');
    }

    public function test_stores_report_and_violations_with_key_fingerprint_and_context(): void
    {
        $report = (new ReportRepository)->store($this->sampleResult(), ['source' => 'test']);

        $this->assertInstanceOf(BfsgReport::class, $report);
        $this->assertSame('https://example.com/', $report->url);
        $this->assertSame(2, $report->total_violations);
        $this->assertSame(95.0, $report->fresh()->score);
        $this->assertInstanceOf(CarbonInterface::class, $report->fresh()->created_at);
        $this->assertSame('B', $report->grade);
        $this->assertSame(['compliance_level' => 'AA', 'locale' => 'de', 'source' => 'test'], $report->metadata);

        $image = $report->violations()->where('key', 'images.missing_alt')->sole();
        $this->assertSame('images', $image->analyzer);
        $this->assertSame('error', $image->severity);
        $this->assertSame('1.1.1', $image->wcag_rule);
        $this->assertSame('img', $image->element);
        $this->assertSame(sha1('images|images.missing_alt|1.1.1|/html[1]/body[1]/img[1]'), $image->fingerprint);
        $this->assertSame('Bild ohne Textalternative (a.jpg)', $image->message, 'stored in the locale of the result');
        $this->assertSame([
            'selector' => '/html[1]/body[1]/img[1]',
            'snippet' => '<img src="a.jpg">',
            'params' => ['src' => 'a.jpg'],
            'meta' => ['approximate' => false],
            'related' => ['1.3.1'],
            'tags' => ['best-practice'],
            'auto_fixable' => false,
        ], $image->context);
        $this->assertInstanceOf(CarbonInterface::class, $image->created_at);

        $link = $report->violations()->where('key', 'links.missing_noopener')->sole();
        $this->assertNull($link->wcag_rule);
        $this->assertTrue($link->context['auto_fixable']);
    }

    public function test_a_clean_result_is_stored_as_a_report_without_violations(): void
    {
        $report = (new ReportRepository)->store(new AnalysisResult([], ['images'], 'https://example.com/clean'));

        $this->assertSame(0, $report->total_violations);
        $this->assertSame(100.0, $report->fresh()->score);
        $this->assertSame('A+', $report->grade);
        $this->assertSame(0, $report->violations()->count());
    }

    public function test_store_is_one_transaction(): void
    {
        Schema::drop('bfsg_violations');

        try {
            (new ReportRepository)->store($this->sampleResult());
            $this->fail('storing without the violations table must fail');
        } catch (QueryException) {
            // expected
        }

        $this->assertSame(0, BfsgReport::query()->count(), 'the report row is rolled back with the failed violations');
    }

    public function test_large_results_are_inserted_in_chunks(): void
    {
        $violations = array_map(fn (int $i) => new Violation('images', 'images.missing_alt', Severity::Error, '1.1.1', selector: "/html[1]/body[1]/img[$i]"), range(1, 450));

        $report = (new ReportRepository)->store(new AnalysisResult(['images' => $violations], ['images'], 'https://example.com/many'));

        $this->assertSame(450, $report->violations()->count());
        $this->assertSame(450, $report->violations()->distinct()->count('fingerprint'));
    }

    public function test_is_migrated_checks_for_the_v3_columns(): void
    {
        $repository = new ReportRepository;
        $this->assertTrue($repository->isMigrated());

        Schema::table('bfsg_violations', fn (Blueprint $table) => $table->dropIndex(['fingerprint']));
        Schema::table('bfsg_violations', fn (Blueprint $table) => $table->dropColumn('fingerprint'));
        $this->assertFalse($repository->isMigrated());

        Schema::drop('bfsg_violations');
        Schema::drop('bfsg_reports');
        $this->assertFalse($repository->isMigrated());
    }

    public function test_the_upgrade_migration_adds_the_columns_to_a_v2_table_and_is_a_no_op_otherwise(): void
    {
        $migration = require __DIR__.'/../../database/migrations/2026_09_18_000000_add_context_and_fingerprint_to_bfsg_violations.php';

        $migration->up(); // fresh install: columns exist already
        $this->assertTrue(Schema::hasColumns('bfsg_violations', ['key', 'fingerprint', 'context']));

        $migration->down();
        $this->assertFalse(Schema::hasColumn('bfsg_violations', 'fingerprint'), 'v2 schema');

        $migration->up();
        $this->assertTrue(Schema::hasColumns('bfsg_violations', ['key', 'fingerprint', 'context']));
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
        $this->assertSame('testing', (new BfsgViolation)->getConnectionName());

        config()->set('bfsg.reporting.database.connection', null);
        $this->assertNull((new BfsgReport)->getConnectionName());
    }

    public function test_factories_create_reports_and_violations(): void
    {
        $violation = BfsgViolation::factory()->create();

        $this->assertSame('images.missing_alt', $violation->key);
        $this->assertSame('/html[1]/body[1]/img[1]', $violation->context['selector']);
        $this->assertInstanceOf(BfsgReport::class, $violation->report);
        $this->assertSame(3, BfsgReport::factory()->count(3)->create()->count());
    }
}
