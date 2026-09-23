<?php

namespace ItsJustVita\LaravelBfsg\Mcp;

use ItsJustVita\LaravelBfsg\Mcp\Tools\AnalyzeHtml;
use ItsJustVita\LaravelBfsg\Mcp\Tools\AnalyzeUrl;
use ItsJustVita\LaravelBfsg\Mcp\Tools\CheckContrast;
use ItsJustVita\LaravelBfsg\Mcp\Tools\GenerateReport;
use ItsJustVita\LaravelBfsg\Mcp\Tools\GetHistory;
use ItsJustVita\LaravelBfsg\Mcp\Tools\GetReport;
use ItsJustVita\LaravelBfsg\Mcp\Tools\ListAnalyzers;
use ItsJustVita\LaravelBfsg\Support\PackageVersion;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;

class BfsgMcpServer extends Server
{
    protected string $name = 'laravel-bfsg';

    protected string $version = 'unknown';

    protected string $instructions = 'BFSG/WCAG 2.1 accessibility checks for this Laravel application: analyze HTML or pages (paths of this app are fetched in-process), check colour contrast, list analyzers, and read stored reports. Findings carry a stable key (e.g. images.missing_alt), a severity and the WCAG success criterion.';

    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        AnalyzeHtml::class,
        AnalyzeUrl::class,
        CheckContrast::class,
        ListAnalyzers::class,
        GetHistory::class,
        GetReport::class,
        GenerateReport::class,
    ];

    protected function boot(): void
    {
        $this->version = PackageVersion::get();
    }
}
