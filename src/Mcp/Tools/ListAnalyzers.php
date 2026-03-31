<?php

namespace ItsJustVita\LaravelBfsg\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListAnalyzers extends Tool
{
    protected string $description = 'List all 16 available BFSG/WCAG accessibility analyzers with their WCAG rules and enabled/disabled status.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $checks = config('bfsg.checks', []);

        $analyzers = [
            ['name' => 'images', 'wcag_rules' => '1.1.1', 'description' => 'Image alt text validation'],
            ['name' => 'forms', 'wcag_rules' => '1.3.1, 4.1.2', 'description' => 'Form label and ARIA validation'],
            ['name' => 'headings', 'wcag_rules' => '1.3.1, 2.4.6', 'description' => 'Heading hierarchy and structure'],
            ['name' => 'contrast', 'wcag_rules' => '1.4.3, 1.4.6', 'description' => 'Color contrast ratios with CSS parsing'],
            ['name' => 'aria', 'wcag_rules' => '4.1.2', 'description' => 'ARIA roles, attributes, and references'],
            ['name' => 'links', 'wcag_rules' => '2.4.4, 2.4.9', 'description' => 'Link text and purpose'],
            ['name' => 'keyboard', 'wcag_rules' => '2.1.1, 2.4.7', 'description' => 'Keyboard navigation and focus management'],
            ['name' => 'language', 'wcag_rules' => '3.1.1, 3.1.2', 'description' => 'Language attributes (BFSG S3)'],
            ['name' => 'tables', 'wcag_rules' => '1.3.1', 'description' => 'Table captions, headers, and scope'],
            ['name' => 'media', 'wcag_rules' => '1.2.1-1.2.5', 'description' => 'Video captions, audio transcripts'],
            ['name' => 'semantic', 'wcag_rules' => '1.3.1, 4.1.1', 'description' => 'Semantic HTML landmarks and structure'],
            ['name' => 'page_title', 'wcag_rules' => '2.4.2', 'description' => 'Page title presence and quality'],
            ['name' => 'input_purpose', 'wcag_rules' => '1.3.5', 'description' => 'Input autocomplete attributes'],
            ['name' => 'focus', 'wcag_rules' => '2.4.7', 'description' => 'Focus indicator visibility'],
            ['name' => 'error_handling', 'wcag_rules' => '3.3.1, 3.3.3', 'description' => 'Form error identification'],
            ['name' => 'status_messages', 'wcag_rules' => '4.1.3', 'description' => 'ARIA live regions for status updates'],
        ];

        foreach ($analyzers as &$analyzer) {
            $analyzer['enabled'] = $checks[$analyzer['name']] ?? true;
        }

        return Response::json($analyzers);
    }
}
