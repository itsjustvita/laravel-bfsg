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
 * creates both columns) and does nothing; it also does nothing when `url_hash` exists.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('bfsg.reporting.database.connection') ?: null;
    }

    public function up(): void
    {
        if (! Schema::hasTable('bfsg_reports') || Schema::hasColumn('bfsg_reports', 'url_hash')) {
            return;
        }

        if (Schema::hasIndex('bfsg_reports', ['url'])) {
            Schema::table('bfsg_reports', fn (Blueprint $table) => $table->dropIndex(['url']));
        }

        Schema::table('bfsg_reports', function (Blueprint $table) {
            $table->text('url')->change();
            $table->string('url_hash', 64)->nullable()->after('url')->index();
        });

        DB::table('bfsg_reports')->select(['id', 'url'])->orderBy('id')->chunkById(500, function ($reports) {
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
