<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use ItsJustVita\LaravelBfsg\Css\CssParser;
use ItsJustVita\LaravelBfsg\Dom\Text;
use ItsJustVita\LaravelBfsg\Severity;

class ContrastAnalyzer extends BaseAnalyzer
{
    protected string $key = 'contrast';

    protected string $description = 'Colour contrast of text';

    protected array $rules = ['1.4.3'];

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

    /** Hard cap — don't resolve contrast for more than this many text elements. */
    protected const MAX_TEXT_ELEMENTS = 500;

    /** Soft wall-clock budget (seconds). Stop analyzing once exceeded. */
    protected const TIME_BUDGET_SECONDS = 5.0;

    public function __construct(private ?CssParser $cssParser = null) {}

    protected function inspect(): void
    {
        $this->cssParser ??= new CssParser;
        $this->cssParser->parse($this->document);

        $this->checkCssColors($this->cssParser);
        $this->checkProblematicPatterns();
    }

    protected function checkCssColors(CssParser $cssParser): void
    {
        // Query text-containing elements
        $textElements = $this->query('//p | //span | //a | //h1 | //h2 | //h3 | //h4 | //h5 | //h6 | //li | //td | //th | //label | //button | //dt | //dd | //figcaption | //blockquote | //cite');

        $budgetDeadline = microtime(true) + self::TIME_BUDGET_SECONDS;
        $processed = 0;

        foreach ($textElements as $element) {
            if ($processed >= self::MAX_TEXT_ELEMENTS) {
                break;
            }
            if (microtime(true) > $budgetDeadline) {
                break;
            }
            $processed++;

            // Skip elements without text content
            $text = $this->text($element);
            if ($text === '') {
                continue;
            }

            $colors = $cssParser->resolveColors($element);
            $foreground = $colors['foreground'];
            $background = $colors['background'];

            $ratio = $foreground->contrastWith($background);

            // Large text has a lower requirement
            $required = in_array(strtolower($element->tagName), ['h1', 'h2', 'h3', 'h4'], true)
                ? self::WCAG_AA_LARGE
                : self::WCAG_AA_NORMAL;

            if ($ratio >= $required) {
                continue;
            }

            $this->report(
                'insufficient',
                Severity::Error,
                '1.4.3',
                $element,
                [
                    'ratio' => number_format($ratio, 2),
                    'required' => $required,
                    'foreground' => $foreground->toHex(),
                    'background' => $background->toHex(),
                    'content' => Text::truncate($text, 30),
                ],
                [
                    'approximate' => (bool) $colors['approximate'],
                    'ratio' => $ratio,
                ],
            );
        }
    }

    protected function checkProblematicPatterns(): void
    {
        // Only flag patterns that actually indicate a contrast problem:
        // hard-coded light gray tones in inline styles.
        // The former placeholder/disabled heuristics were pure noise: every
        // form with a placeholder attribute was flagged without the CSS colour
        // ever being checked, which penalised modern sites unfairly.
        $inlineGrayElements = $this->query(
            '//*[@style and (contains(@style, "#999") or contains(@style, "#aaa") or contains(@style, "#bbb") or contains(@style, "#ccc"))]'
        );

        foreach ($inlineGrayElements as $element) {
            $this->report('light_gray_inline', Severity::Warning, '1.4.3', $element);
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
}
