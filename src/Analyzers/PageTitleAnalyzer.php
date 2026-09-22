<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMElement;
use ItsJustVita\LaravelBfsg\Dom\Text;
use ItsJustVita\LaravelBfsg\Severity;

class PageTitleAnalyzer extends BaseAnalyzer
{
    private const MIN_LENGTH = 3;

    private const MAX_LENGTH = 70;

    /** Titles that identify no page (English + German), compared lowercased without trailing punctuation. */
    public const GENERIC_TITLES = [
        'home', 'homepage', 'home page', 'untitled', 'untitled document', 'page', 'new page', 'document', 'welcome',
        'index', 'test', 'website',
        'startseite', 'willkommen', 'seite', 'neue seite', 'dokument', 'unbenannt', 'unbenanntes dokument', 'neu', 'beispiel',
    ];

    protected string $key = 'page_title';

    protected string $description = 'Page title';

    protected array $rules = ['2.4.2'];

    protected function inspect(): void
    {
        if ($this->isFragment()) {
            return;
        }

        $titles = $this->query('/html/head/title');

        if ($titles === []) {
            $this->report('missing_title', Severity::Error, '2.4.2');

            return;
        }

        foreach (array_slice($titles, 1) as $extra) {
            $this->report('multiple_titles', Severity::Warning, '2.4.2', $extra);
        }

        $this->checkTitle($titles[0]);
    }

    protected function checkTitle(DOMElement $title): void
    {
        $text = $this->text($title);

        if ($text === '') {
            $this->report('empty_title', Severity::Error, '2.4.2', $title);

            return;
        }

        $length = Text::length($text);

        if ($length < self::MIN_LENGTH) {
            $this->report('short_title', Severity::Warning, '2.4.2', $title, ['length' => $length]);
        } elseif ($this->isGeneric($text)) {
            $this->report('generic_title', Severity::Warning, '2.4.2', $title, ['title' => $text]);
        } elseif ($length > self::MAX_LENGTH) {
            $this->report('long_title', Severity::Notice, '2.4.2', $title, ['length' => $length]);
        }
    }

    /** The whole title, or — for titles of at most two words — its first segment before | - – — is generic. */
    protected function isGeneric(string $title): bool
    {
        $candidates = [$title];

        if (preg_match_all('/[\pL\pN]+/u', $title) <= 2) {
            $candidates[] = preg_split('/\s*[|\-–—]\s*/u', $title)[0] ?? $title;
        }

        foreach ($candidates as $candidate) {
            if (in_array(Text::lower(Text::stripTrailingPunctuation(Text::normalize($candidate))), self::GENERIC_TITLES, true)) {
                return true;
            }
        }

        return false;
    }
}
