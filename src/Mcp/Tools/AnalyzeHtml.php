<?php

namespace ItsJustVita\LaravelBfsg\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Mcp\Tools\Concerns\ToolHelpers;
use ItsJustVita\LaravelBfsg\Reports\ReportGenerator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class AnalyzeHtml extends Tool
{
    use ToolHelpers;

    protected string $name = 'analyze_html';

    protected string $description = 'Analyze an HTML document or fragment for WCAG 2.1 / BFSG accessibility findings. Returns the laravel-bfsg JSON report: summary (counts, score 0-100, grade A+ to F) and the findings per analyzer with key, severity, WCAG rule, message, suggestion, element, selector and snippet.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'html' => $schema->string()->description('The HTML to analyze (a full document or a fragment)')->required(),
            'locale' => $schema->string()->description('Locale of messages and suggestions, e.g. en or de (default: the configured locale)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $html = $this->stringArgument($request, 'html');

        if ($html === null) {
            return Response::error('The html parameter is required.');
        }

        try {
            $locale = $this->localeArgument($request);
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        $result = app(Bfsg::class)->analyze($html, ['locale' => $locale]);

        return Response::text((new ReportGenerator($result, $locale))->toJson());
    }
}
