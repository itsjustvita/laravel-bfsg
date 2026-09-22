<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMElement;
use ItsJustVita\LaravelBfsg\Css\CssParser;
use ItsJustVita\LaravelBfsg\Dom\Element;
use ItsJustVita\LaravelBfsg\Severity;

class ContrastAnalyzer extends BaseAnalyzer
{
    /** Hard cap of measured text elements per document. */
    public const MAX_MEASURED = 500;

    /** Wall-clock budget per document, in seconds. */
    public const TIME_BUDGET_SECONDS = 5.0;

    /** [normal, large] thresholds per level. */
    private const THRESHOLDS = ['AA' => [4.5, 3.0], 'AAA' => [7.0, 4.5]];

    private const SKIPPED_TAGS = ['script', 'style', 'noscript', 'template', 'title'];

    protected string $key = 'contrast';

    protected string $description = 'Colour contrast of text';

    protected array $rules = ['1.4.3', '1.4.6'];

    public function __construct(private ?CssParser $cssParser = null, private ?string $level = null) {}

    protected function inspect(): void
    {
        $parser = ($this->cssParser ?? new CssParser)->parse($this->document);
        $level = $this->level();
        $deadline = microtime(true) + self::TIME_BUDGET_SECONDS;
        $measured = 0;

        foreach ($this->query('//*[text()[normalize-space()]]') as $element) {
            if (in_array(Element::tag($element), self::SKIPPED_TAGS, true) || $this->ownText($element) === '' || $this->isHidden($element) || $parser->hidesElement($element)) {
                continue;
            }

            if ($measured >= self::MAX_MEASURED || microtime(true) > $deadline) {
                $this->report('analysis_truncated', Severity::Notice, '1.4.3', null, ['limit' => $measured]);

                return;
            }

            $measured++;
            $this->measure($parser, $element, $level);
        }
    }

    protected function measure(CssParser $parser, DOMElement $element, string $level): void
    {
        $colors = $parser->resolveColors($element);
        $ratio = $colors['foreground']->contrastRatio($colors['background']);
        $size = $parser->fontSizePx($element);
        $large = $size >= 24.0 || ($size >= 18.66 && $parser->isBold($element));
        [$aaNormal, $aaLarge] = self::THRESHOLDS['AA'];
        $aaRequired = $large ? $aaLarge : $aaNormal;

        if ($ratio >= $aaRequired && $level === 'AA') {
            return;
        }

        [$aaaNormal, $aaaLarge] = self::THRESHOLDS['AAA'];
        $aaaRequired = $large ? $aaaLarge : $aaaNormal;

        if ($ratio >= $aaaRequired) {
            return;
        }

        $failsAa = $ratio < $aaRequired;
        $tags = $failsAa ? [] : ['aaa'];

        if ($colors['approximate']) {
            $tags[] = 'approximate';
        }

        $this->report(
            'insufficient',
            $failsAa ? Severity::Error : Severity::Notice,
            $failsAa ? '1.4.3' : '1.4.6',
            $element,
            [
                'ratio' => number_format($ratio, 2),
                'required' => $failsAa ? $aaRequired : $aaaRequired,
                'foreground' => $colors['foreground']->toHex(),
                'background' => $colors['background']->toHex(),
            ],
            ['approximate' => $colors['approximate'], 'ratio' => round($ratio, 2), 'large' => $large],
            tags: $tags,
        );
    }

    protected function level(): string
    {
        $level = $this->level ?? (function_exists('config') ? (string) config('bfsg.compliance_level', 'AA') : 'AA');

        return strtoupper(trim($level)) === 'AAA' ? 'AAA' : 'AA';
    }
}
