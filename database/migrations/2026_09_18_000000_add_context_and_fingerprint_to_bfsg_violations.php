<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrade from v2: adds key, fingerprint and context to an existing bfsg_violations table. Migrations run in file
 * name order, so on a fresh install this file runs before the undated create_bfsg_tables (which already creates
 * the columns) and does nothing.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('bfsg.reporting.database.connection') ?: null;
    }

    public function up(): void
    {
        if (! Schema::hasTable('bfsg_violations') || Schema::hasColumn('bfsg_violations', 'fingerprint')) {
            return;
        }

        Schema::table('bfsg_violations', function (Blueprint $table) {
            $table->string('key')->nullable()->after('analyzer')->index();
            $table->string('fingerprint', 40)->nullable()->index();
            $table->json('context')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bfsg_violations') || ! Schema::hasColumn('bfsg_violations', 'fingerprint')) {
            return;
        }

        Schema::table('bfsg_violations', function (Blueprint $table) {
            $table->dropIndex(['key']);
            $table->dropIndex(['fingerprint']);
            $table->dropColumn(['key', 'fingerprint', 'context']);
        });
    }
};
