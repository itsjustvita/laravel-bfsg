<?php

namespace ItsJustVita\LaravelBfsg\Tests\Feature;

use ItsJustVita\LaravelBfsg\Models\BfsgReport;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class BfsgHistoryCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    public function test_command_exists(): void
    {
        $this->artisan('list')
            ->assertSuccessful()
            ->expectsOutputToContain('bfsg:history');
    }

    public function test_shows_empty_message(): void
    {
        $this->artisan('bfsg:history')
            ->assertSuccessful()
            ->expectsOutputToContain('No reports found');
    }

    public function test_lists_reports(): void
    {
        BfsgReport::create(['url' => 'https://example.com', 'total_violations' => 5, 'score' => 82, 'grade' => 'B']);
        BfsgReport::create(['url' => 'https://other.com', 'total_violations' => 0, 'score' => 100, 'grade' => 'A+']);

        $this->artisan('bfsg:history')
            ->assertSuccessful()
            ->expectsOutputToContain('example.com')
            ->expectsOutputToContain('other.com');
    }

    public function test_filters_by_url(): void
    {
        BfsgReport::create(['url' => 'https://example.com', 'total_violations' => 5, 'score' => 82, 'grade' => 'B']);
        BfsgReport::create(['url' => 'https://other.com', 'total_violations' => 0, 'score' => 100, 'grade' => 'A+']);

        $this->artisan('bfsg:history', ['--url' => 'https://example.com'])
            ->assertSuccessful()
            ->expectsOutputToContain('example.com');
    }

    public function test_shows_trend(): void
    {
        $old = BfsgReport::create(['url' => 'https://example.com', 'total_violations' => 10, 'score' => 50, 'grade' => 'F']);
        $old->update(['created_at' => now()->subDays(3)]);

        $new = BfsgReport::create(['url' => 'https://example.com', 'total_violations' => 5, 'score' => 82, 'grade' => 'B']);
        $new->update(['created_at' => now()->subDay()]);

        $this->artisan('bfsg:history', ['--url' => 'https://example.com', '--trend' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('trend');
    }

    public function test_trend_requires_url(): void
    {
        $this->artisan('bfsg:history', ['--trend' => true])
            ->assertFailed();
    }

    public function test_cleanup_deletes_old_reports(): void
    {
        $oldReport = BfsgReport::create(['url' => 'https://example.com', 'total_violations' => 5, 'score' => 82, 'grade' => 'B']);
        BfsgReport::where('id', $oldReport->id)->update(['created_at' => now()->subDays(60)]);

        BfsgReport::create(['url' => 'https://example.com', 'total_violations' => 0, 'score' => 100, 'grade' => 'A+']);

        $this->artisan('bfsg:history', ['--cleanup' => true, '--days' => 30])
            ->assertSuccessful()
            ->expectsOutputToContain('Deleted 1');

        $this->assertCount(1, BfsgReport::all());
    }

    public function test_cleanup_nothing_to_delete(): void
    {
        $this->artisan('bfsg:history', ['--cleanup' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('No old reports');
    }
}
