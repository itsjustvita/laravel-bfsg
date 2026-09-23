<?php

namespace ItsJustVita\LaravelBfsg\Reports;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\View;
use InvalidArgumentException;
use ItsJustVita\LaravelBfsg\AnalysisResult;
use ItsJustVita\LaravelBfsg\Support\Locale;
use ItsJustVita\LaravelBfsg\Support\PackageVersion;
use ItsJustVita\LaravelBfsg\Violation;
use RuntimeException;

/**
 * Turns an AnalysisResult into a report: the JSON contract (spec §12), Markdown, HTML or PDF. Score and grade
 * come from ScoreCalculator; texts are rendered in the report locale.
 */
class ReportGenerator
{
    public const FORMATS = ['json', 'markdown', 'html', 'pdf'];

    private const EXTENSIONS = ['json' => 'json', 'markdown' => 'md', 'html' => 'html', 'pdf' => 'pdf'];

    private string $format = 'html';

    private string $locale;

    private ScoreCalculator $scores;

    private CarbonImmutable $analyzedAt;

    /** Makes defaultPath() unique when two reports are written within the same second. */
    private string $pathSuffix;

    /**
     * @throws InvalidArgumentException when an explicit or result locale is not well-formed (it becomes a path
     *                                  segment of the translation loader); config/app locales are trusted
     */
    public function __construct(private AnalysisResult $result, ?string $locale = null, ?ScoreCalculator $scores = null)
    {
        $given = $locale ?? $result->locale();

        if ($given !== null && ! Locale::isWellFormed($given)) {
            throw new InvalidArgumentException("Invalid locale [{$given}]. Use a language code such as en, de or de_AT.");
        }

        $this->locale = $given ?? (config('bfsg.locale') ?: app()->getLocale());
        $this->scores = $scores ?? ScoreCalculator::fromConfig();
        $this->analyzedAt = CarbonImmutable::now();
        $this->pathSuffix = bin2hex(random_bytes(3));
    }

    /** @throws InvalidArgumentException for a format outside FORMATS */
    public function format(string $format): static
    {
        if (! in_array($format, self::FORMATS, true)) {
            throw new InvalidArgumentException("Unknown report format [{$format}]. Use one of: ".implode(', ', self::FORMATS).'.');
        }

        $this->format = $format;

        return $this;
    }

    public function extension(): string
    {
        return self::EXTENSIONS[$this->format];
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function result(): AnalysisResult
    {
        return $this->result;
    }

    public function score(): int
    {
        return $this->scores->score($this->result);
    }

    public function grade(): string
    {
        return $this->scores->grade($this->score(), $this->result->countBySeverity()['error']);
    }

    /** @return array{total: int, errors: int, warnings: int, notices: int, score: int, grade: string, accessible: bool} */
    public function summary(): array
    {
        $counts = $this->result->countBySeverity();

        return [
            'total' => $this->result->count(),
            'errors' => $counts['error'],
            'warnings' => $counts['warning'],
            'notices' => $counts['notice'],
            'score' => $this->score(),
            'grade' => $this->grade(),
            'accessible' => $this->result->isAccessible(),
        ];
    }

    /**
     * The JSON contract as PHP arrays (spec §12). Use toJson() for the wire format: it keeps empty maps as objects.
     *
     * @return array{url: ?string, package_version: string, locale: string, analyzed_at: string, analyzers: list<string>, summary: array<string, int|string|bool>, violations: array<string, list<array<string, mixed>>>}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->result->url(),
            'package_version' => PackageVersion::get(),
            'locale' => $this->locale,
            'analyzed_at' => $this->analyzedAt->toIso8601String(),
            'analyzers' => $this->result->analyzersRun(),
            'summary' => $this->summary(),
            'violations' => array_map(
                fn (array $violations) => array_map(fn (Violation $violation) => $violation->toArray($this->locale), $violations),
                $this->result->byAnalyzer(),
            ),
        ];
    }

    public function toJson(): string
    {
        $report = $this->toArray();
        $report['violations'] = $report['violations'] === [] ? new \stdClass : array_map(
            fn (array $violations) => array_map(fn (array $violation) => Violation::objectifyMaps($violation), $violations),
            $report['violations'],
        );

        return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n";
    }

    public function render(): string
    {
        return match ($this->format) {
            'json' => $this->toJson(),
            'markdown' => View::make('bfsg::reports.markdown', $this->viewData())->render(),
            'html' => $this->html(),
            'pdf' => $this->pdf(),
        };
    }

    /** Render the report, then write it to $path (directories are created) and return the path. */
    public function saveTo(string $path): string
    {
        $content = $this->render();
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create the report directory {$directory}.");
        }

        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException("Could not write the report to {$path}.");
        }

        return $path;
    }

    /** `bfsg.reporting.output_path`/report_{Y-m-d_His}_{6 hex}.{extension}, stable for this report */
    public function defaultPath(): string
    {
        $directory = rtrim((string) (config('bfsg.reporting.output_path') ?: storage_path('app/bfsg-reports')), '/');

        return $directory.'/report_'.$this->analyzedAt->format('Y-m-d_His').'_'.$this->pathSuffix.'.'.$this->extension();
    }

    private function html(): string
    {
        return View::make('bfsg::reports.html', $this->viewData())->render();
    }

    private function pdf(): string
    {
        if (! $this->pdfAvailable()) {
            throw new RuntimeException('PDF reports require barryvdh/laravel-dompdf: composer require barryvdh/laravel-dompdf');
        }

        return Pdf::loadHTML($this->html())->output();
    }

    protected function pdfAvailable(): bool
    {
        return class_exists(Pdf::class);
    }

    /** @return array<string, mixed> */
    private function viewData(): array
    {
        return [
            'report' => $this->toArray(),
            'locale' => $this->locale,
            'analyzedAt' => $this->analyzedAt,
            'version' => PackageVersion::get(),
        ];
    }
}
