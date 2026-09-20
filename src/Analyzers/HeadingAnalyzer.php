<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMElement;
use ItsJustVita\LaravelBfsg\Dom\Element;
use ItsJustVita\LaravelBfsg\Dom\Text;
use ItsJustVita\LaravelBfsg\Severity;

class HeadingAnalyzer extends BaseAnalyzer
{
    private const HEADINGS = '//h1|//h2|//h3|//h4|//h5|//h6';

    private const MAX_CONTENT = 50;

    protected string $key = 'headings';

    protected string $description = 'Heading hierarchy and heading text';

    protected array $rules = ['1.3.1', '2.4.6'];

    protected function inspect(): void
    {
        $this->checkHeadingHierarchy();
        $this->checkForMainHeading();
        $this->checkHeadingText();
        $this->checkMultipleH1Tags();
    }

    protected function checkHeadingHierarchy(): void
    {
        $previousLevel = 0;

        foreach ($this->query(self::HEADINGS) as $heading) {
            $currentLevel = $this->level($heading);

            if ($previousLevel > 0 && $currentLevel > $previousLevel + 1) {
                $this->report('skipped_level', Severity::Error, '1.3.1', $heading, [
                    'from' => 'h'.$previousLevel,
                    'to' => Element::tag($heading),
                    'content' => $this->content($heading),
                ]);
            }

            $previousLevel = $currentLevel;
        }
    }

    protected function checkForMainHeading(): void
    {
        if ($this->query('//h1') === []) {
            $this->report('missing_h1', Severity::Warning, '1.3.1', related: ['2.4.6']);
        }
    }

    protected function checkHeadingText(): void
    {
        foreach ($this->query(self::HEADINGS) as $heading) {
            $text = $this->text($heading);

            if ($text === '') {
                $this->report('empty_heading', Severity::Error, '1.3.1', $heading, ['level' => Element::tag($heading)], related: ['2.4.6']);
            } elseif (Text::length($text) < 3) {
                $this->report('short_heading', Severity::Warning, '2.4.6', $heading, ['content' => $this->content($heading)]);
            }
        }
    }

    protected function checkMultipleH1Tags(): void
    {
        foreach ($this->query('//h1') as $index => $h1) {
            if ($index === 0) {
                continue;
            }

            $this->report('multiple_h1', Severity::Notice, '1.3.1', $h1, [
                'index' => $index + 1,
                'content' => $this->content($h1),
            ]);
        }
    }

    protected function level(DOMElement $heading): int
    {
        return (int) ltrim(Element::tag($heading), 'h');
    }

    protected function content(DOMElement $heading): string
    {
        return Text::truncate($this->text($heading), self::MAX_CONTENT);
    }
}
