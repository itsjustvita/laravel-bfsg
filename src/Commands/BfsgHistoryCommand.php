<?php

namespace ItsJustVita\LaravelBfsg\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use ItsJustVita\LaravelBfsg\Models\BfsgReport;
use ItsJustVita\LaravelBfsg\Persistence\ReportRepository;
use ItsJustVita\LaravelBfsg\Support\Sanitizer;
use Symfony\Component\Console\Formatter\OutputFormatter;

class BfsgHistoryCommand extends Command
{
    /** Invalid options, a missing table or a database error (1 stays free for findings-style failures). */
    public const OPERATIONAL_ERROR = 2;

    protected $signature = 'bfsg:history
                            {--url= : Filter by URL}
                            {--limit=20 : Number of reports to show}
                            {--trend : Show score trend for a URL}
                            {--cleanup : Delete old reports}
                            {--days=30 : Days to keep when using --cleanup}';

    protected $description = 'View accessibility check history and trends';

    public function handle(ReportRepository $repository): int
    {
        if (! $repository->isMigrated()) {
            $this->error('The bfsg tables with the v3 columns are missing: run php artisan migrate');

            return self::OPERATIONAL_ERROR;
        }

        $name = $this->option('cleanup') ? 'days' : 'limit';

        if (! preg_match('/^[1-9][0-9]*$/', (string) $this->option($name))) {
            $this->error("The --{$name} option must be a positive integer, got: ".self::escape((string) $this->option($name)));

            return self::OPERATIONAL_ERROR;
        }

        try {
            return $this->dispatch();
        } catch (QueryException $e) {
            $this->error('Database error: '.self::escape($e->getMessage()));

            return self::OPERATIONAL_ERROR;
        }
    }

    private function dispatch(): int
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
            self::escape(Str::limit(Sanitizer::terminal((string) $r->url), 50)),
            $r->total_violations,
            $this->score($r->score),
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

            return self::OPERATIONAL_ERROR;
        }

        // The latest N reports, shown oldest first so the trend reads left to right.
        $reports = BfsgReport::forUrl($url)
            ->latest()
            ->latest('id')
            ->limit((int) $this->option('limit'))
            ->get()
            ->reverse()
            ->values();

        if ($reports->isEmpty()) {
            $this->info('No reports found for: '.self::escape($url));

            return Command::SUCCESS;
        }

        $this->info('Score trend for: '.self::escape($url));
        $this->newLine();

        $rows = $reports->map(fn ($r) => [
            $r->created_at->format('Y-m-d H:i'),
            $this->score($r->score),
            $r->grade,
            $r->total_violations,
        ])->toArray();

        $this->table(
            ['Date', 'Score', 'Grade', 'Violations'],
            $rows
        );

        if ($reports->count() >= 2) {
            $first = (int) round($reports->first()->score);
            $last = (int) round($reports->last()->score);
            $diff = $last - $first;
            $direction = $diff > 0 ? 'improved' : ($diff < 0 ? 'declined' : 'unchanged');
            $this->info('Trend: '.$direction.' by '.abs($diff).' points');
        }

        return Command::SUCCESS;
    }

    protected function cleanup(): int
    {
        $days = (int) $this->option('days');
        $deleted = BfsgReport::query()->where('created_at', '<', now()->subDays($days))->delete();

        if ($deleted === 0) {
            $this->info('No old reports to clean up.');

            return Command::SUCCESS;
        }

        $this->info("Deleted {$deleted} reports older than {$days} days.");

        return Command::SUCCESS;
    }

    /** Stored URLs and options are page or user text: control characters out, one line (no "::command" lines for CI), then formatter tags. */
    private static function escape(string $text): string
    {
        return OutputFormatter::escape((string) preg_replace('/\s*\R\s*/u', ' ', Sanitizer::terminal($text)));
    }

    /** Scores are whole numbers (ScoreCalculator); the decimal column may still hold v2 fractions. */
    protected function score(float|int|string|null $score): string
    {
        return (int) round((float) $score).'%';
    }
}
