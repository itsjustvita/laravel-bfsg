<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMElement;
use ItsJustVita\LaravelBfsg\Dom\Text;
use ItsJustVita\LaravelBfsg\Severity;

class PageTitleAnalyzer extends BaseAnalyzer
{
    private const MIN_LENGTH = 3;

    private const MAX_LENGTH = 70;

    protected string $key = 'page_title';

    protected string $description = 'Page title';

    protected array $rules = ['2.4.2'];

    /** @var list<string> */
    protected array $genericTitles = [
        // English
        'home',
        'untitled',
        'page',
        'document',
        'welcome',
        'index',
        'test',
        'website',
        // German
        'startseite',
        'willkommen',
        'seite',
        'dokument',
        'unbenannt',
        'neu',
        'beispiel',
    ];

    protected function inspect(): void
    {
        $this->checkTitleExists();
    }

    protected function checkTitleExists(): void
    {
        $title = $this->query('//title')[0] ?? null;

        if ($title === null) {
            $this->report('missing_title', Severity::Error, '2.4.2');

            return;
        }

        $titleText = $this->text($title);

        if ($titleText === '') {
            $this->report('empty_title', Severity::Error, '2.4.2', $title);

            return;
        }

        $this->checkGenericTitle($title, $titleText);
        $this->checkTitleLength($title, $titleText);
    }

    protected function checkGenericTitle(DOMElement $title, string $titleText): void
    {
        if (in_array(Text::lower($titleText), $this->genericTitles, true)) {
            $this->report('generic_title', Severity::Warning, '2.4.2', $title, ['title' => $titleText]);
        }
    }

    protected function checkTitleLength(DOMElement $title, string $titleText): void
    {
        $length = Text::length($titleText);

        if ($length < self::MIN_LENGTH) {
            $this->report('short_title', Severity::Warning, '2.4.2', $title, ['length' => $length]);
        } elseif ($length > self::MAX_LENGTH) {
            $this->report('long_title', Severity::Warning, '2.4.2', $title, ['length' => $length]);
        }
    }
}
