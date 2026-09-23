<?php

namespace ItsJustVita\LaravelBfsg\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use ItsJustVita\LaravelBfsg\Models\BfsgReport;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

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

    public function test_trend_shows_the_latest_reports_in_chronological_order(): void
    {
        foreach ([[20, '2026-01-01 10:00'], [45, '2026-02-01 10:00'], [60, '2026-03-01 10:00'], [80, '2026-04-01 10:00']] as [$score, $date]) {
            BfsgReport::create(['url' => 'https://example.com', 'total_violations' => 1, 'score' => $score, 'grade' => 'C'])
                ->forceFill(['created_at' => $date])
                ->save();
        }

        $this->artisan('bfsg:history', ['--url' => 'https://example.com', '--trend' => true, '--limit' => 2])
            ->assertSuccessful()
            ->expectsTable(['Date', 'Score', 'Grade', 'Violations'], [
                ['2026-03-01 10:00', '60%', 'C', 1],
                ['2026-04-01 10:00', '80%', 'C', 1],
            ])
            ->expectsOutputToContain('Trend: improved by 20 points');
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

    public function test_scores_are_printed_as_whole_numbers(): void
    {
        BfsgReport::create(['url' => 'https://example.com', 'total_violations' => 3, 'score' => 82.5, 'grade' => 'B'])
            ->forceFill(['created_at' => '2026-05-01 10:00'])->save();
        BfsgReport::create(['url' => 'https://example.com', 'total_violations' => 1, 'score' => 90.4, 'grade' => 'A'])
            ->forceFill(['created_at' => '2026-06-01 10:00'])->save();

        $this->artisan('bfsg:history', ['--url' => 'https://example.com', '--trend' => true])
            ->assertSuccessful()
            ->expectsTable(['Date', 'Score', 'Grade', 'Violations'], [
                ['2026-05-01 10:00', '83%', 'B', 3],
                ['2026-06-01 10:00', '90%', 'A', 1],
            ])
            ->expectsOutputToContain('Trend: improved by 7 points');
    }

    public function test_cleanup_reports_the_number_actually_deleted(): void
    {
        foreach ([90, 60, 45] as $days) {
            BfsgReport::create(['url' => 'https://example.com', 'total_violations' => 0, 'score' => 100, 'grade' => 'A+'])
                ->forceFill(['created_at' => now()->subDays($days)])->save();
        }

        $this->artisan('bfsg:history', ['--cleanup' => true, '--days' => 50])
            ->assertSuccessful()
            ->expectsOutputToContain('Deleted 2 reports older than 50 days.');

        $this->assertSame(1, BfsgReport::query()->count());
    }
}
