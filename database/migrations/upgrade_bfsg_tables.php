<?php

use Illuminate\Database\Migrations\Migration;
use ItsJustVita\LaravelBfsg\Persistence\SchemaUpgrade;

/**
 * Safety net for apps that published the migrations in 2.x. Their copy of create_bfsg_tables.php shadows the package
 * copy (the migrator keys files by name), so a fresh database gets the 2.x tables after both dated upgrade migrations
 * have already run as no-ops. This file sorts after create_bfsg_tables in every layout (undated names sort after the
 * dated ones, "upgrade" after "create", whether it is loaded from the package or published) and repeats both upgrade
 * steps. Each step only adds what is missing, so on every other database this migration does nothing.
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
        SchemaUpgrade::widenReportUrl();
    }

    public function down(): void
    {
        // Nothing to undo: create_bfsg_tables and the dated upgrade migrations own the schema
    }
};
