<?php

namespace ItsJustVita\LaravelBfsg\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use ItsJustVita\LaravelBfsg\Bfsg;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListAnalyzers extends Tool
{
    protected string $description = 'List every available BFSG/WCAG accessibility analyzer with its WCAG rules and enabled/disabled status.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $checks = config('bfsg.checks', []);
        $registry = app(Bfsg::class)->analyzers();
        $analyzers = [];

        foreach (Bfsg::ANALYZERS as $key => $class) {
            $analyzer = $registry[$key] ?? app($class);
            $meta = method_exists($analyzer, 'describe')
                ? $analyzer->describe()
                : ['key' => $key, 'description' => '', 'rules' => []];

            $analyzers[] = [
                'name' => $key,
                'class' => $analyzer::class,
                'description' => $meta['description'],
                'rules' => $meta['rules'],
                'enabled' => (bool) ($checks[$key] ?? true),
            ];
        }

        return Response::json($analyzers);
    }
}
