<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMDocument;
use DOMXPath;

class ContrastAnalyzer
{
    protected array $violations = [];

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

    public function analyze(DOMDocument $dom): array
    {
        $this->violations = [];
        $xpath = new DOMXPath($dom);

        // Check inline styles for potential contrast issues
        $this->checkInlineStyles($xpath);

        // Check common problematic color combinations
        $this->checkProblematicPatterns($xpath);

        // Check for text without sufficient background definition
        $this->checkTextWithoutBackground($xpath);

        return $this->violations;
    }

    protected function checkInlineStyles(DOMXPath $xpath): void
    {
        // Find elements with inline color styles
        $elementsWithColor = $xpath->query('//*[@style and (contains(@style, "color:") or contains(@style, "background-color:"))]');

        foreach ($elementsWithColor as $element) {
            $style = $element->getAttribute('style');
            $color = $this->extractColor($style, 'color');
            $backgroundColor = $this->extractColor($style, 'background-color');

            if ($color && $backgroundColor) {
                $ratio = $this->calculateContrastRatio($color, $backgroundColor);

                if ($ratio !== null && $ratio < self::CONTRAST_REQUIREMENTS['AA']['normal']) {
                    $this->violations[] = [
                        'type' => 'error',
                        'rule' => 'WCAG 1.4.3',
                        'element' => $element->nodeName,
                        'message' => sprintf('Insufficient color contrast ratio: %.2f:1', $ratio),
                        'colors' => [
                            'foreground' => $color,
                            'background' => $backgroundColor,
                        ],
                        'suggestion' => sprintf('Increase contrast to at least %.1f:1 for WCAG AA compliance', self::CONTRAST_REQUIREMENTS['AA']['normal']),
                        'auto_fixable' => false,
                    ];
                }
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

    protected function checkTextWithoutBackground(DOMXPath $xpath): void
    {
        // Check for text elements without explicit background
        $textElements = $xpath->query('//p|//span|//div[text()]|//h1|//h2|//h3|//h4|//h5|//h6');
        $elementsWithoutBackground = 0;

        foreach ($textElements as $element) {
            $style = $element->getAttribute('style');

            // Check if element has color but no background
            if ($style && strpos($style, 'color:') !== false && strpos($style, 'background') === false) {
                $elementsWithoutBackground++;
            }
        }

        if ($elementsWithoutBackground > 0) {
            $this->violations[] = [
                'type' => 'notice',
                'rule' => 'WCAG 1.4.3',
                'element' => 'text elements',
                'message' => "Found {$elementsWithoutBackground} text element(s) with color but no explicit background",
                'suggestion' => 'Ensure sufficient contrast with inherited or default backgrounds',
                'auto_fixable' => false,
            ];
        }
    }

    protected function extractColor(string $style, string $property): ?string
    {
        $pattern = '/' . preg_quote($property, '/') . '\s*:\s*([^;]+)/i';

        if (preg_match($pattern, $style, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    protected function calculateContrastRatio(string $color1, string $color2): ?float
    {
        $rgb1 = $this->colorToRgb($color1);
        $rgb2 = $this->colorToRgb($color2);

        if (!$rgb1 || !$rgb2) {
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
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
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
