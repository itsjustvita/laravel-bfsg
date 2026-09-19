<?php

namespace ItsJustVita\LaravelBfsg\Contracts;

use ItsJustVita\LaravelBfsg\Dom\HtmlDocument;
use ItsJustVita\LaravelBfsg\Violation;

interface Analyzer
{
    /** Registry key, e.g. "images". Also the first segment of every translation key. */
    public function key(): string;

    /** @return list<Violation> */
    public function analyze(HtmlDocument $document): array;
}
