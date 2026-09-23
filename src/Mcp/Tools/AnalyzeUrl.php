<?php

namespace ItsJustVita\LaravelBfsg\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Http\FetchFailed;
use ItsJustVita\LaravelBfsg\Mcp\Tools\Concerns\ToolHelpers;
use ItsJustVita\LaravelBfsg\Reports\ReportGenerator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsOpenWorld]
class AnalyzeUrl extends Tool
{
    use ToolHelpers;

    protected string $name = 'analyze_url';

    protected string $description = 'Fetch a page and analyze it for WCAG 2.1 / BFSG accessibility findings. Paths of this application (e.g. /contact) are rendered in-process; other URLs are fetched over HTTP, limited to the configured allowed hosts. Returns the laravel-bfsg JSON report.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->description('A URL, or a path of this application such as /contact')->required(),
            'locale' => $schema->string()->description('Locale of messages and suggestions, e.g. en or de (default: the configured locale)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $url = $this->stringArgument($request, 'url');

        if ($url === null) {
            return Response::error('The url parameter is required.');
        }

        try {
            $locale = $this->localeArgument($request);
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        try {
            $page = $this->fetchPage($url);
        } catch (FetchFailed $e) {
            return Response::error($e->getMessage());
        }

        $result = app(Bfsg::class)->analyze($page->html, ['url' => $page->finalUrl, 'locale' => $locale, 'fragment' => false]);

        return Response::text((new ReportGenerator($result, $locale))->toJson());
    }
}
