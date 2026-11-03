<?php

namespace ItsJustVita\LaravelBfsg\Mcp\Tools;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Mcp\Tools\Concerns\ToolHelpers;
use ItsJustVita\LaravelBfsg\Persistence\ReportRepository;
use ItsJustVita\LaravelBfsg\Reports\ReportGenerator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Throwable;

#[IsOpenWorld]
class GenerateReport extends Tool
{
    use ToolHelpers;

    protected string $name = 'generate_report';

    protected string $description = 'Fetch a page, analyze it and return a report as json, markdown or html (pdf is written to the report directory and its path returned). Optionally store the report in the database.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->description('A URL, or a path of this application such as /contact')->required(),
            'format' => $schema->string()->description('json, markdown, html or pdf (default: json)'),
            'locale' => $schema->string()->description('Locale of the report, e.g. en or de (default: the configured locale)'),
            'save' => $schema->boolean()->description('Store the report in the database (default: false)'),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->safely(function () use ($request): Response {
            $url = $this->stringArgument($request, 'url');
            $format = $this->stringArgument($request, 'format') ?? 'json';

            if ($url === null) {
                return $this->toolError('The url parameter is required.');
            }

            if (! in_array($format, ReportGenerator::FORMATS, true)) {
                return $this->toolError("Invalid format [{$format}]. Use one of: ".implode(', ', ReportGenerator::FORMATS).'.');
            }

            if ($format === 'pdf' && ! class_exists(Pdf::class)) {
                return $this->toolError('PDF reports require barryvdh/laravel-dompdf: composer require barryvdh/laravel-dompdf');
            }

            try {
                $locale = $this->localeArgument($request);
            } catch (InvalidArgumentException $e) {
                return $this->toolError($e->getMessage());
            }

            // A FetchFailed (the page is capped at UrlFetcher::MAX_PAGE_BYTES) becomes a tool error (safely())
            $page = $this->fetchPage($url);
            $result = app(Bfsg::class)->analyze($page->html, ['url' => $page->finalUrl, 'locale' => $locale, 'fragment' => false]);
            $report = (new ReportGenerator($result, $locale))->format($format);
            $payload = ['format' => $format, 'summary' => $report->summary()];

            try {
                if ($format === 'pdf') {
                    $payload['path'] = $report->saveTo($report->defaultPath());
                } else {
                    $payload['report'] = $report->render();
                }
            } catch (Throwable $e) {
                return Response::error('Could not generate the report: '.self::cleanError($e->getMessage()));
            }

            if ($request->get('save') === true) {
                try {
                    $payload['report_id'] = app(ReportRepository::class)->store($result, ['source' => 'mcp'])->id;
                } catch (Throwable $e) {
                    $payload['save_error'] = 'Could not store the report: '.self::cleanError($e->getMessage());
                }
            }

            return $this->toolJson($payload);
        });
    }
}
