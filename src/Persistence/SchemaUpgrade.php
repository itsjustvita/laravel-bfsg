<?php

namespace ItsJustVita\LaravelBfsg\Persistence;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The idempotent upgrade steps from the v2 schema, shared by the dated upgrade migrations and the undated
 * upgrade_bfsg_tables safety net. Each step only adds what is missing, so it is a no-op on the v3 schema. Call them
 * from a migration's up(): the migrator makes the migration's connection the default one while it runs.
 *
 * Published copies of those migrations call this class from the application, so its public methods stay stable
 * for all of 3.x.
 */
final class SchemaUpgrade
{
    /** Adds key, fingerprint and context to a v2 bfsg_violations table. */
    public static function addViolationColumns(): void
    {
        if (! Schema::hasTable('bfsg_violations')) {
            return;
        }

        $missing = array_values(array_filter(['key', 'fingerprint', 'context'], fn (string $column) => ! Schema::hasColumn('bfsg_violations', $column)));

        if ($missing === []) {
            return;
        }

        Schema::table('bfsg_violations', function (Blueprint $table) use ($missing) {
            if (in_array('key', $missing, true)) {
                $table->string('key')->nullable()->after('analyzer')->index();
            }
            if (in_array('fingerprint', $missing, true)) {
                $table->string('fingerprint', 40)->nullable()->index();
            }
            if (in_array('context', $missing, true)) {
                $table->json('context')->nullable();
            }
        });
    }

    /**
     * Turns bfsg_reports.url into a text column (URLs up to ReportRepository::URL_LENGTH characters) and moves the
     * lookup to an indexed url_hash (sha256 of the stored URL), because a text column cannot carry a plain index on
     * every database. Rows without a hash get one, so a rerun finishes a backfill that was interrupted (MySQL cannot
     * roll back the schema change of a failed run).
     */
    public static function widenReportUrl(): void
    {
        if (! Schema::hasTable('bfsg_reports')) {
            return;
        }

        if (! Schema::hasColumn('bfsg_reports', 'url_hash')) {
            // An index on url, whatever its name (v2 used the default bfsg_reports_url_index)
            foreach (Schema::getIndexes('bfsg_reports') as $index) {
                if ($index['columns'] === ['url'] && ! $index['primary']) {
                    Schema::table('bfsg_reports', fn (Blueprint $table) => $index['unique'] ? $table->dropUnique($index['name']) : $table->dropIndex($index['name']));
                }
            }

            Schema::table('bfsg_reports', function (Blueprint $table) {
                $table->text('url')->change();
                $table->string('url_hash', 64)->nullable()->after('url')->index();
            });
        }

        DB::table('bfsg_reports')->whereNull('url_hash')->select(['id', 'url'])->chunkById(500, function ($reports) {
            foreach ($reports as $report) {
                DB::table('bfsg_reports')->where('id', $report->id)->update(['url_hash' => hash('sha256', (string) $report->url)]);
            }
        });
    }
}
