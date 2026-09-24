<?php

namespace ItsJustVita\LaravelBfsg\Persistence;

use DateTimeInterface;
use ItsJustVita\LaravelBfsg\AnalysisResult;
use ItsJustVita\LaravelBfsg\Models\BfsgReport;
use ItsJustVita\LaravelBfsg\Models\BfsgViolation;
use ItsJustVita\LaravelBfsg\Reports\ScoreCalculator;
use ItsJustVita\LaravelBfsg\Violation;
use Throwable;

/** The only writer of reports (bfsg:check --save, middleware, MCP generate_report). */
final class ReportRepository
{
    private const CHUNK = 200;

    /** Length of the `bfsg_reports.url` column. */
    public const URL_LENGTH = 255;

    public function __construct(private ?ScoreCalculator $scores = null)
    {
        $this->scores ??= ScoreCalculator::fromConfig();
    }

    /**
     * Store a report and all its findings in one transaction on the bfsg connection. The URL is stored like the
     * middleware stores it: without query string and fragment (signatures, tokens), cut to the column length.
     *
     * @param  array<string, mixed>  $metadata  merged into the report's metadata column
     */
    public function store(AnalysisResult $result, array $metadata = []): BfsgReport
    {
        return (new BfsgReport)->getConnection()->transaction(function () use ($result, $metadata) {
            $stats = $this->scores->stats($result);

            $report = BfsgReport::create([
                'url' => self::storedUrl((string) ($result->url() ?? '')),
                'total_violations' => $stats['total_issues'],
                'score' => $stats['compliance_score'],
                'grade' => $stats['grade'],
                'metadata' => array_merge(['compliance_level' => config('bfsg.compliance_level', 'AA'), 'locale' => $result->locale()], $metadata),
            ]);

            $now = now();

            foreach (array_chunk($result->all(), self::CHUNK) as $chunk) {
                BfsgViolation::query()->insert(array_map(fn (Violation $violation) => $this->row($violation, $report->id, $result->locale(), $now), $chunk));
            }

            return $report;
        });
    }

    /** $url without query string and fragment, at most URL_LENGTH characters. */
    public static function storedUrl(string $url): string
    {
        return mb_substr(explode('#', explode('?', $url, 2)[0], 2)[0], 0, self::URL_LENGTH);
    }

    /** Whether the tables exist with the v3 columns (`php artisan migrate` has run). */
    public function isMigrated(): bool
    {
        try {
            $schema = (new BfsgReport)->getConnection()->getSchemaBuilder();

            return $schema->hasTable('bfsg_reports') && $schema->hasColumn('bfsg_violations', 'fingerprint');
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function row(Violation $violation, int $reportId, ?string $locale, DateTimeInterface $now): array
    {
        return [
            'report_id' => $reportId,
            'analyzer' => $violation->analyzer,
            'key' => $violation->key,
            'severity' => $violation->severity->value,
            'message' => $violation->message($locale),
            'element' => $violation->element,
            'wcag_rule' => $violation->rule,
            'suggestion' => $violation->suggestion($locale),
            'fingerprint' => $violation->fingerprint(),
            'context' => json_encode([
                'selector' => $violation->selector,
                'snippet' => $violation->snippet,
                'params' => $violation->params,
                'meta' => $violation->meta,
                'related' => $violation->related,
                'tags' => $violation->tags,
                'auto_fixable' => $violation->autoFixable,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            'created_at' => $now,
        ];
    }
}
