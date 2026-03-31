<?php

namespace ItsJustVita\LaravelBfsg\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use ItsJustVita\LaravelBfsg\Analyzers\ContrastAnalyzer;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CheckContrast extends Tool
{
    protected string $description = 'Check the contrast ratio between a foreground and background color. Returns ratio and WCAG AA/AAA pass/fail for normal and large text.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'foreground' => $schema->string()->description('Foreground color (hex, rgb, or named color)')->required(),
            'background' => $schema->string()->description('Background color (hex, rgb, or named color)')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $foreground = $request->get('foreground');
        $background = $request->get('background');

        if (empty($foreground) || empty($background)) {
            return Response::error('Both foreground and background parameters are required.');
        }

        $analyzer = new ContrastAnalyzer;
        $ratio = $analyzer->calculateContrastRatio($foreground, $background);

        if ($ratio === null) {
            return Response::error('Could not calculate contrast ratio. Check that colors are valid (hex, rgb, or named colors).');
        }

        return Response::json([
            'foreground' => $foreground,
            'background' => $background,
            'ratio' => round($ratio, 2),
            'ratio_formatted' => number_format($ratio, 2).':1',
            'aa_normal' => $ratio >= 4.5,
            'aa_large' => $ratio >= 3.0,
            'aaa_normal' => $ratio >= 7.0,
            'aaa_large' => $ratio >= 4.5,
        ]);
    }
}
