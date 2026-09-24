<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_programmatic_store_strips_credentials_query_and_fragment_from_the_url(): void
    {
        $result = new AnalysisResult([], ['images'], 'https://deploy:s3cret@staging.example.com/page?token=t#top', 'en');

        $report = (new ReportRepository)->store($result);

        $this->assertSame('https://staging.example.com/page', $report->fresh()->url);
        $this->assertSame('https://staging.example.com/page', ReportRepository::storedUrl('https://deploy:s3cret@staging.example.com/page?token=t#top'));
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

    public function test_long_urls_are_stored_whole_up_to_2048_characters_and_found_through_the_hash(): void
    {
        $url = 'https://example.com/'.str_repeat('segment/', 200);
        $report = (new ReportRepository)->store(new AnalysisResult([], ['images'], $url.'?page=2', 'en'));

        $this->assertSame(1620, mb_strlen($report->fresh()->url));
        $this->assertSame($url, $report->fresh()->url);
        $this->assertSame(hash('sha256', $url), $report->fresh()->url_hash);
        $this->assertSame([$report->id], BfsgReport::forUrl($url.'#top')->pluck('id')->all());
        $this->assertSame(2048, mb_strlen(ReportRepository::storedUrl('https://example.com/'.str_repeat('x', 5000))));

        $report->update(['url' => 'https://example.com/moved']);
        $this->assertSame(hash('sha256', 'https://example.com/moved'), $report->fresh()->url_hash, 'the hash follows every save');
    }

    public function test_is_migrated_checks_for_the_v3_columns(): void
    {
        $repository = new ReportRepository;
        $this->assertTrue($repository->isMigrated());

        Schema::table('bfsg_reports', fn (Blueprint $table) => $table->dropIndex(['url_hash']));
        Schema::table('bfsg_reports', fn (Blueprint $table) => $table->dropColumn('url_hash'));
        $this->assertFalse($repository->isMigrated(), 'without url_hash');
        Schema::table('bfsg_reports', fn (Blueprint $table) => $table->string('url_hash', 64)->nullable()->index());
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

    public function test_the_url_migration_widens_an_earlier_table_keeps_its_rows_and_is_a_no_op_otherwise(): void
    {
        $migration = require __DIR__.'/../../database/migrations/2026_09_24_000000_widen_url_of_bfsg_reports.php';

        $migration->up(); // fresh install: create_bfsg_tables made both columns
        $this->assertSame('text', Schema::getColumnType('bfsg_reports', 'url'));

        DB::table('bfsg_reports')->insert(['url' => 'https://example.com/'.str_repeat('a', 300), 'url_hash' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('bfsg_reports', 'url_hash'), 'earlier schema');
        $this->assertSame('varchar', Schema::getColumnType('bfsg_reports', 'url'));
        $this->assertTrue(Schema::hasIndex('bfsg_reports', ['url']));
        $this->assertSame(255, mb_strlen(DB::table('bfsg_reports')->value('url')), 'rolled back rows fit the old column');

        DB::table('bfsg_reports')->insert(['url' => 'https://example.com/b', 'created_at' => now(), 'updated_at' => now()]);
        $migration->up();
        $this->assertSame('text', Schema::getColumnType('bfsg_reports', 'url'));
        $this->assertFalse(Schema::hasIndex('bfsg_reports', ['url']));
        $this->assertTrue(Schema::hasIndex('bfsg_reports', ['url_hash']));
        $this->assertSame(hash('sha256', 'https://example.com/b'), DB::table('bfsg_reports')->where('url', 'https://example.com/b')->value('url_hash'));
        $this->assertSame(2, DB::table('bfsg_reports')->whereNotNull('url_hash')->count(), 'every row got its hash');
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

    private function migrateOn(string $connection): void
    {
        config()->set("database.connections.$connection", ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        config()->set('bfsg.reporting.database.connection', $connection);
    }

    public function test_a_fresh_install_through_the_migrator_on_the_configured_connection(): void
    {
        $this->migrateOn('bfsg_fresh');

        $this->artisan('migrate', ['--database' => 'bfsg_fresh', '--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();

        $schema = Schema::connection('bfsg_fresh');
        $this->assertTrue($schema->hasColumns('bfsg_violations', ['key', 'fingerprint', 'context']));
        $this->assertTrue($schema->hasColumn('bfsg_reports', 'url_hash'));
        $this->assertSame('text', $schema->getColumnType('bfsg_reports', 'url'));
        $this->assertSame(
            ['2026_09_18_000000_add_context_and_fingerprint_to_bfsg_violations', '2026_09_24_000000_widen_url_of_bfsg_reports', 'create_bfsg_tables'],
            DB::connection('bfsg_fresh')->table('migrations')->orderBy('id')->pluck('migration')->all(),
            'the dated upgrades sort first and are no-ops on a fresh install',
        );

        $report = (new ReportRepository)->store($this->sampleResult());
        $this->assertSame('bfsg_fresh', $report->getConnectionName());
        $this->assertSame(2, DB::connection('bfsg_fresh')->table('bfsg_violations')->count());
    }

    public function test_the_migrator_upgrades_a_v2_table_with_rows(): void
    {
        $this->migrateOn('bfsg_v2');
        $schema = Schema::connection('bfsg_v2');
        $schema->create('bfsg_reports', function (Blueprint $table) {
            $table->id();
            $table->string('url');
            $table->integer('total_violations')->default(0);
            $table->float('score')->default(0);
            $table->string('grade')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        $schema->create('bfsg_violations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('bfsg_reports')->cascadeOnDelete();
            $table->string('analyzer');
            $table->string('severity');
            $table->text('message');
            $table->text('element')->nullable();
            $table->string('wcag_rule')->nullable();
            $table->text('suggestion')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('analyzer');
            $table->index('severity');
        });
        $db = DB::connection('bfsg_v2');
        $reportId = $db->table('bfsg_reports')->insertGetId(['url' => 'https://v2.example.com/', 'total_violations' => 1, 'score' => 95, 'grade' => 'A']);
        $db->table('bfsg_violations')->insert(['report_id' => $reportId, 'analyzer' => 'images', 'severity' => 'error', 'message' => 'v2 message']);
        $repository = app('migration.repository');
        $repository->setSource('bfsg_v2');
        $repository->createRepository();
        $repository->log('create_bfsg_tables', 1);

        $this->artisan('migrate', ['--database' => 'bfsg_v2', '--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();

        $this->assertTrue($schema->hasColumns('bfsg_violations', ['key', 'fingerprint', 'context']));
        $this->assertSame('text', $schema->getColumnType('bfsg_reports', 'url'));
        $this->assertSame(hash('sha256', 'https://v2.example.com/'), $db->table('bfsg_reports')->value('url_hash'));
        $this->assertSame([$reportId], BfsgReport::forUrl('https://v2.example.com/')->pluck('id')->all());
        $row = $db->table('bfsg_violations')->sole();
        $this->assertSame('v2 message', $row->message);
        $this->assertNull($row->key);
        $this->assertNull($row->fingerprint);
        $this->assertTrue((new ReportRepository)->isMigrated());

        $this->artisan('migrate:rollback', ['--database' => 'bfsg_v2', '--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();
        $this->assertFalse($schema->hasColumn('bfsg_violations', 'fingerprint'));
        $this->assertFalse($schema->hasColumn('bfsg_reports', 'url_hash'));
        $this->assertSame('https://v2.example.com/', $db->table('bfsg_reports')->value('url'));
        $this->assertSame('v2 message', $db->table('bfsg_violations')->value('message'));
    }
}
