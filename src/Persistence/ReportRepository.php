<?php

namespace ItsJustVita\LaravelBfsg\Persistence;

use DateTimeInterface;
use ItsJustVita\LaravelBfsg\AnalysisResult;
use ItsJustVita\LaravelBfsg\Http\UrlFetcher;
use ItsJustVita\LaravelBfsg\Models\BfsgReport;
use ItsJustVita\LaravelBfsg\Models\BfsgViolation;
use ItsJustVita\LaravelBfsg\Reports\ScoreCalculator;
use ItsJustVita\LaravelBfsg\Violation;
use Throwable;

/** The only writer of reports (bfsg:check --save, middleware, MCP generate_report). */
final class ReportRepository
{
    private const CHUNK = 200;

    /** Longest URL stored (`bfsg_reports.url` is a text column; lookups go through the indexed `url_hash`). */
    public const URL_LENGTH = 2048;

    public function __construct(private ?ScoreCalculator $scores = null)
    {
        $this->scores ??= ScoreCalculator::fromConfig();
    }

    /**
     * Store a report and all its findings in one transaction on the bfsg connection. The URL is stored like the
     * middleware stores it: without query string and fragment (signatures, tokens), cut to URL_LENGTH characters.
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

    /** $url as stored: without credentials (`user:pass@`), query string and fragment, at most URL_LENGTH characters. */
    public static function storedUrl(string $url): string
    {
        return self::normalizeUrl($url);
    }

    /**
     * The canonical form of $url for storing and comparing: a relative path is resolved against app.url, scheme and
     * host are lowercased, the default port (:80 for http, :443 for https) is dropped, then credentials, query string
     * and fragment are removed and the length is capped. The trailing slash is kept. Anything that is not a URL or
     * path (empty, invalid) is only redacted, stripped and capped.
     */
    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        $url = explode('#', explode('?', $url, 2)[0], 2)[0];

        if ($url !== '' && (str_starts_with($url, '/') && ! str_starts_with($url, '//'))) {
            try {
                $url = app(UrlFetcher::class)->absolute($url);
            } catch (Throwable) {
                // not resolvable: keep the path as given
            }
        }

        $url = UrlFetcher::redact($url);

        if (preg_match('~^([a-z][a-z0-9+.\-]*)://([^/]*)(.*)$~is', $url, $m) === 1) {
            $scheme = strtolower($m[1]);
            $authority = strtolower($m[2]);

            if (($scheme === 'http' && str_ends_with($authority, ':80')) || ($scheme === 'https' && str_ends_with($authority, ':443'))) {
                $authority = substr($authority, 0, (int) strrpos($authority, ':'));
            }

            $url = $scheme.'://'.$authority.$m[3];
        }

        return mb_substr($url, 0, self::URL_LENGTH);
    }

    /** Whether the tables exist with the v3 columns (`php artisan migrate` has run). */
    public function isMigrated(): bool
    {
        try {
            $schema = (new BfsgReport)->getConnection()->getSchemaBuilder();

            return $schema->hasTable('bfsg_reports') && $schema->hasColumn('bfsg_reports', 'url_hash') && $schema->hasColumn('bfsg_violations', 'fingerprint');
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
