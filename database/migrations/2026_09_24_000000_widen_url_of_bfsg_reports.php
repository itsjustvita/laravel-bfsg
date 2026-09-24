<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrade from v2 and early 3.x-dev installs: `bfsg_reports.url` becomes a text column (URLs up to
 * ReportRepository::URL_LENGTH characters) and the lookup moves to an indexed `url_hash` (sha256 of the stored URL),
 * because a text column cannot carry a plain index on every database. Existing rows get their hash. Migrations run
 * in file name order, so on a fresh install this file runs before the undated create_bfsg_tables (which already
 * creates both columns) and does nothing. When `url_hash` exists, only rows without a hash are hashed, so a rerun
 * finishes a backfill that was interrupted (MySQL cannot roll back the schema change of a failed run).
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('bfsg.reporting.database.connection') ?: null;
    }

    public function up(): void
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

    public function down(): void
    {
        if (! Schema::hasTable('bfsg_reports') || ! Schema::hasColumn('bfsg_reports', 'url_hash')) {
            return;
        }

        DB::table('bfsg_reports')->update(['url' => DB::raw('substr(url, 1, 255)')]);

        Schema::table('bfsg_reports', function (Blueprint $table) {
            $table->dropIndex(['url_hash']);
            $table->dropColumn('url_hash');
        });

        Schema::table('bfsg_reports', function (Blueprint $table) {
            $table->string('url')->change();
            $table->index('url');
        });
    }
};
