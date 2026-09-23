<?php

namespace ItsJustVita\LaravelBfsg\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use ItsJustVita\LaravelBfsg\Css\Color;
use ItsJustVita\LaravelBfsg\Mcp\Tools\Concerns\ToolHelpers;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class CheckContrast extends Tool
{
    use ToolHelpers;

    protected string $name = 'check_contrast';

    protected string $description = 'Contrast ratio of a text colour on a background colour, with WCAG AA/AAA pass/fail for normal and large text. Accepts hex, rgb(), hsl(), oklch(), oklab() and named colours.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'foreground' => $schema->string()->description('Text colour, e.g. #767676 or oklch(55% 0 0)')->required(),
            'background' => $schema->string()->description('Background colour, e.g. #ffffff')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $foreground = $this->stringArgument($request, 'foreground');
        $background = $this->stringArgument($request, 'background');

        if ($foreground === null || $background === null) {
            return Response::error('Both foreground and background must be colour strings.');
        }

        $text = Color::parse($foreground);
        $surface = Color::parse($background);

        if ($text === null || $surface === null) {
            return Response::error('Could not parse the colours. Use hex, rgb(), hsl(), oklch(), oklab() or a named colour.');
        }

        if ($text->a < 1) {
            $text = $text->over($surface);
        }

        $ratio = $text->contrastRatio($surface);

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
