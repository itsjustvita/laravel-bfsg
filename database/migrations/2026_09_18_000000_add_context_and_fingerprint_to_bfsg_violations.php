<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ItsJustVita\LaravelBfsg\Persistence\SchemaUpgrade;

/**
 * Upgrade from v2: adds key, fingerprint and context to an existing bfsg_violations table. Migrations run in file
 * name order, so on a fresh install this file runs before the undated create_bfsg_tables (which already creates
 * the columns) and does nothing. Apps that still carry a published 2.x create_bfsg_tables get the step again from
 * upgrade_bfsg_tables, which sorts after it.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('bfsg.reporting.database.connection') ?: null;
    }

    public function up(): void
    {
        SchemaUpgrade::addViolationColumns();
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
