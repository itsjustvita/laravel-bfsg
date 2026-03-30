<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bfsg_reports', function (Blueprint $table) {
            $table->id();
            $table->string('url');
            $table->integer('total_violations')->default(0);
            $table->decimal('score', 5, 2)->default(100);
            $table->string('grade', 2)->default('A+');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index('url');
            $table->index('created_at');
        });

        Schema::create('bfsg_violations', function (Blueprint $table) {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('bfsg_violations');
        Schema::dropIfExists('bfsg_reports');
    }
};
