<?php

namespace ItsJustVita\LaravelBfsg\Mcp;

use ItsJustVita\LaravelBfsg\Mcp\Tools\AnalyzeHtml;
use ItsJustVita\LaravelBfsg\Mcp\Tools\AnalyzeUrl;
use ItsJustVita\LaravelBfsg\Mcp\Tools\CheckContrast;
use ItsJustVita\LaravelBfsg\Mcp\Tools\GenerateReport;
use ItsJustVita\LaravelBfsg\Mcp\Tools\GetHistory;
use ItsJustVita\LaravelBfsg\Mcp\Tools\GetReport;
use ItsJustVita\LaravelBfsg\Mcp\Tools\ListAnalyzers;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;

class BfsgMcpServer extends Server
{
    protected string $name = 'laravel-bfsg';

    protected string $version = '2.1.0';

    protected string $instructions = 'BFSG/WCAG accessibility analysis MCP server for Laravel applications.';

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
}
