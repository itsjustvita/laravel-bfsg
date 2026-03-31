<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMDocument;
use DOMXPath;
use ItsJustVita\LaravelBfsg\Services\CssParser;

class ContrastAnalyzer
{
    protected array $violations = [];

    protected CssParser $cssParser;

    // WCAG AA and AAA contrast requirements
    protected const CONTRAST_REQUIREMENTS = [
        'AA' => [
            'normal' => 4.5,  // Normal text
            'large' => 3.0,   // Large text (18pt+ or 14pt+ bold)
        ],
        'AAA' => [
            'normal' => 7.0,
            'large' => 4.5,
        ],
    ];

    protected const WCAG_AA_NORMAL = 4.5;

    protected const WCAG_AA_LARGE = 3.0;

    public function analyze(DOMDocument $dom): array
    {
        $this->violations = [];
        $xpath = new DOMXPath($dom);

        $this->cssParser = new CssParser;
        $this->cssParser->parse($dom);

        $this->checkCssColors($xpath, $dom);
        $this->checkProblematicPatterns($xpath);

        return ['issues' => $this->violations];
    }

    protected function checkCssColors(DOMXPath $xpath, DOMDocument $dom): void
    {
        // Query text-containing elements
        $textElements = $xpath->query('//p | //span | //a | //h1 | //h2 | //h3 | //h4 | //h5 | //h6 | //li | //td | //th | //label | //button | //dt | //dd | //figcaption | //blockquote | //cite');

        if ($textElements === false) {
            return;
        }

        foreach ($textElements as $element) {
            if (! $element instanceof \DOMElement) {
                continue;
            }

            // Skip elements without text content
            $text = trim($element->textContent);
            if ($text === '') {
                continue;
            }

            $colors = $this->cssParser->getResolvedColors($element);

            $fgRgb = $this->colorToRgb($colors['color']);
            $bgRgb = $this->colorToRgb($colors['backgroundColor']);

            if ($fgRgb === null || $bgRgb === null) {
                continue;
            }

            $ratio = $this->calculateContrastRatio($colors['color'], $colors['backgroundColor']);

            if ($ratio === null) {
                continue;
            }

            $requiredRatio = self::WCAG_AA_NORMAL;

            // Check if large text (lower requirement)
            $tagName = strtolower($element->tagName);
            if (in_array($tagName, ['h1', 'h2', 'h3', 'h4'])) {
                $requiredRatio = self::WCAG_AA_LARGE;
            }

            if ($ratio < $requiredRatio) {
                $issue = [
                    'type' => 'error',
                    'severity' => 'error',
                    'rule' => 'WCAG 1.4.3',
                    'message' => sprintf(
                        'Insufficient contrast ratio %.2f:1 (required %.1f:1) for text "%s" with color %s on background %s',
                        $ratio,
                        $requiredRatio,
                        mb_substr($text, 0, 30),
                        $colors['color'],
                        $colors['backgroundColor']
                    ),
                    'element' => $tagName,
                    'suggestion' => 'Increase the contrast between text color and background color to meet WCAG AA requirements.',
                ];

                if ($colors['approximate']) {
                    $issue['approximate'] = true;
                    $issue['message'] .= ' (approximate - colors may be inherited or defaulted)';
                }

                $this->violations[] = $issue;
            }
        }
    }

    protected function checkProblematicPatterns(DOMXPath $xpath): void
    {
        // Common problematic patterns
        $patterns = [
            'light_gray_text' => [
                'selector' => '//*[@style and contains(@style, "#999") or contains(@style, "#aaa") or contains(@style, "#bbb") or contains(@style, "#ccc")]',
                'message' => 'Light gray text may have insufficient contrast',
            ],
            'placeholder_styling' => [
                'selector' => '//input[@placeholder]',
                'message' => 'Placeholder text often has low contrast',
            ],
            'disabled_elements' => [
                'selector' => '//*[@disabled]',
                'message' => 'Disabled elements should still meet minimum contrast requirements',
            ],
        ];

        foreach ($patterns as $patternName => $pattern) {
            $elements = $xpath->query($pattern['selector']);

            if ($elements->length > 0) {
                $this->violations[] = [
                    'type' => 'warning',
                    'rule' => 'WCAG 1.4.3',
                    'element' => 'various',
                    'message' => $pattern['message'],
                    'count' => $elements->length,
                    'suggestion' => 'Review and test contrast ratios for these elements',
                    'auto_fixable' => false,
                ];
            }
        }
    }

    protected function extractColor(string $style, string $property): ?string
    {
        $pattern = '/'.preg_quote($property, '/').'\s*:\s*([^;]+)/i';

        if (preg_match($pattern, $style, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    public function calculateContrastRatio(string $color1, string $color2): ?float
    {
        $rgb1 = $this->colorToRgb($color1);
        $rgb2 = $this->colorToRgb($color2);

        if (! $rgb1 || ! $rgb2) {
            return null;
        }

        // Calculate relative luminance
        $l1 = $this->getRelativeLuminance($rgb1);
        $l2 = $this->getRelativeLuminance($rgb2);

        // Calculate contrast ratio
        $lighter = max($l1, $l2);
        $darker = min($l1, $l2);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    protected function colorToRgb(string $color): ?array
    {
        // Handle hex colors
        if (preg_match('/^#?([a-f0-9]{6}|[a-f0-9]{3})$/i', $color, $matches)) {
            $hex = $matches[1];

            if (strlen($hex) === 3) {
                $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
            }

            return [
                'r' => hexdec(substr($hex, 0, 2)),
                'g' => hexdec(substr($hex, 2, 2)),
                'b' => hexdec(substr($hex, 4, 2)),
            ];
        }

        // Handle rgb() colors
        if (preg_match('/rgb\s*\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)/i', $color, $matches)) {
            return [
                'r' => (int) $matches[1],
                'g' => (int) $matches[2],
                'b' => (int) $matches[3],
            ];
        }

        // Handle named colors (simplified - only common ones)
        $namedColors = [
            'white' => [255, 255, 255],
            'black' => [0, 0, 0],
            'red' => [255, 0, 0],
            'green' => [0, 128, 0],
            'blue' => [0, 0, 255],
            'gray' => [128, 128, 128],
            'grey' => [128, 128, 128],
        ];

        $colorLower = strtolower($color);
        if (isset($namedColors[$colorLower])) {
            return [
                'r' => $namedColors[$colorLower][0],
                'g' => $namedColors[$colorLower][1],
                'b' => $namedColors[$colorLower][2],
            ];
        }

        return null;
    }

    protected function getRelativeLuminance(array $rgb): float
    {
        $rsRGB = $rgb['r'] / 255;
        $gsRGB = $rgb['g'] / 255;
        $bsRGB = $rgb['b'] / 255;

        $r = $rsRGB <= 0.03928 ? $rsRGB / 12.92 : pow(($rsRGB + 0.055) / 1.055, 2.4);
        $g = $gsRGB <= 0.03928 ? $gsRGB / 12.92 : pow(($gsRGB + 0.055) / 1.055, 2.4);
        $b = $bsRGB <= 0.03928 ? $bsRGB / 12.92 : pow(($bsRGB + 0.055) / 1.055, 2.4);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
