<?php

namespace ItsJustVita\LaravelBfsg\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use ItsJustVita\LaravelBfsg\Analyzers\BaseAnalyzer;
use ItsJustVita\LaravelBfsg\Bfsg;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListAnalyzers extends Tool
{
    protected string $name = 'list_analyzers';

    protected string $description = 'List the accessibility analyzers: registry key, class, description, WCAG success criteria and whether it runs (built-ins disabled in config/bfsg.php are listed as disabled; custom analyzers registered by the app are included).';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $registry = app(Bfsg::class);
        $running = $registry->analyzers();
        $analyzers = [];

        foreach (array_unique([...array_keys(Bfsg::ANALYZERS), ...array_keys($running)]) as $key) {
            $analyzer = $running[$key] ?? app(Bfsg::ANALYZERS[$key]);
            $meta = $analyzer instanceof BaseAnalyzer ? $analyzer->describe() : ['description' => '', 'rules' => []];

            $analyzers[] = [
                'name' => $key,
                'class' => $analyzer::class,
                'description' => $meta['description'],
                'rules' => $meta['rules'],
                'enabled' => isset($running[$key]),
            ];
        }

        return Response::json($analyzers);
    }
}
