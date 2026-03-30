<?php

namespace ItsJustVita\LaravelBfsg\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use ItsJustVita\LaravelBfsg\Models\BfsgReport;

class BfsgHistoryCommand extends Command
{
    protected $signature = 'bfsg:history
                            {--url= : Filter by URL}
                            {--limit=20 : Number of reports to show}
                            {--trend : Show score trend for a URL}
                            {--cleanup : Delete old reports}
                            {--days=30 : Days to keep when using --cleanup}';

    protected $description = 'View accessibility check history and trends';

    public function handle(): int
    {
        if ($this->option('cleanup')) {
            return $this->cleanup();
        }

        if ($this->option('trend')) {
            return $this->showTrend();
        }

        return $this->listReports();
    }

    protected function listReports(): int
    {
        $query = BfsgReport::query()->latest();

        if ($url = $this->option('url')) {
            $query->forUrl($url);
        }

        $reports = $query->limit((int) $this->option('limit'))->get();

        if ($reports->isEmpty()) {
            $this->info('No reports found.');

            return Command::SUCCESS;
        }

        $rows = $reports->map(fn ($r) => [
            $r->id,
            Str::limit($r->url, 50),
            $r->total_violations,
            $r->score.'%',
            $r->grade,
            $r->created_at->format('Y-m-d H:i'),
        ])->toArray();

        $this->table(
            ['ID', 'URL', 'Violations', 'Score', 'Grade', 'Date'],
            $rows
        );

        return Command::SUCCESS;
    }

    protected function showTrend(): int
    {
        $url = $this->option('url');

        if (! $url) {
            $this->error('The --url option is required when using --trend');

            return Command::FAILURE;
        }

        $reports = BfsgReport::forUrl($url)
            ->oldest()
            ->limit((int) $this->option('limit'))
            ->get();

        if ($reports->isEmpty()) {
            $this->info("No reports found for: {$url}");

            return Command::SUCCESS;
        }

        $this->info("Score trend for: {$url}");
        $this->newLine();

        $rows = $reports->map(fn ($r) => [
            $r->created_at->format('Y-m-d H:i'),
            $r->score.'%',
            $r->grade,
            $r->total_violations,
        ])->toArray();

        $this->table(
            ['Date', 'Score', 'Grade', 'Violations'],
            $rows
        );

        if ($reports->count() >= 2) {
            $first = $reports->first()->score;
            $last = $reports->last()->score;
            $diff = $last - $first;
            $direction = $diff > 0 ? 'improved' : ($diff < 0 ? 'declined' : 'unchanged');
            $this->info('Trend: '.$direction.' by '.abs($diff).' points');
        }

        return Command::SUCCESS;
    }

    protected function cleanup(): int
    {
        $days = (int) $this->option('days');
        $count = BfsgReport::where('created_at', '<', now()->subDays($days))->count();

        if ($count === 0) {
            $this->info('No old reports to clean up.');

            return Command::SUCCESS;
        }

        BfsgReport::where('created_at', '<', now()->subDays($days))->delete();
        $this->info("Deleted {$count} reports older than {$days} days.");

        return Command::SUCCESS;
    }
}
