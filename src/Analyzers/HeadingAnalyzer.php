<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMElement;
use ItsJustVita\LaravelBfsg\Dom\Element;
use ItsJustVita\LaravelBfsg\Dom\Roles;
use ItsJustVita\LaravelBfsg\Dom\Text;
use ItsJustVita\LaravelBfsg\Severity;

class HeadingAnalyzer extends BaseAnalyzer
{
    private const CANDIDATES = '//h1|//h2|//h3|//h4|//h5|//h6|//*[@role]';

    private const MAX_CONTENT = 50;

    protected string $key = 'headings';

    protected string $description = 'Heading hierarchy and heading text';

    protected array $rules = ['1.3.1', '2.4.6'];

    protected function inspect(): void
    {
        $previousLevel = 0;
        $firstLevelOne = null;

        foreach ($this->headings() as [$heading, $level]) {
            $name = $this->name($heading);

            if ($previousLevel > 0 && $level > $previousLevel + 1) {
                $this->report('skipped_level', Severity::Warning, '1.3.1', $heading, [
                    'from' => 'h'.$previousLevel,
                    'to' => 'h'.$level,
                    'content' => Text::truncate($name, self::MAX_CONTENT),
                ]);
            }

            $previousLevel = $level;

            if ($name === '') {
                $this->report('empty_heading', Severity::Error, '1.3.1', $heading, ['level' => 'h'.$level], related: ['2.4.6']);
            } elseif (Text::length($name) < 3) {
                $this->report('short_heading', Severity::Notice, '2.4.6', $heading, ['content' => $name]);
            }

            if ($level !== 1) {
                continue;
            }

            if ($firstLevelOne === null) {
                $firstLevelOne = $heading;
            } else {
                $this->report('multiple_h1', Severity::Notice, '1.3.1', $heading, ['content' => Text::truncate($name, self::MAX_CONTENT)]);
            }
        }

        if ($firstLevelOne === null && ! $this->isFragment()) {
            $this->report('missing_h1', Severity::Notice, '1.3.1');
        }
    }

    /**
     * Visible headings in document order: h1–h6 (unless re-roled) and role="heading" elements.
     *
     * @return list<array{0: DOMElement, 1: int}>
     */
    protected function headings(): array
    {
        $headings = [];

        foreach ($this->queryVisible(self::CANDIDATES) as $element) {
            if (Roles::of($element) !== 'heading') {
                continue;
            }

            $headings[] = [$element, $this->level($element)];
        }

        return $headings;
    }

    /** aria-level wins; otherwise the tag level; role="heading" without a valid aria-level is level 2. */
    protected function level(DOMElement $heading): int
    {
        $ariaLevel = trim($heading->getAttribute('aria-level'));

        if (preg_match('/^[1-9]\d*$/', $ariaLevel) === 1) {
            return (int) $ariaLevel;
        }

        $tag = Element::tag($heading);

        return preg_match('/^h([1-6])$/', $tag, $m) === 1 ? (int) $m[1] : 2;
    }
}
