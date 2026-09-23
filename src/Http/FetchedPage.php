<?php

namespace ItsJustVita\LaravelBfsg\Http;

/** An HTML page fetched by UrlFetcher, with linked same-origin stylesheets inlined. */
final class FetchedPage
{
    /** @param  list<string>  $warnings  non-fatal problems (stylesheets that could not be inlined) */
    public function __construct(
        public readonly string $url,
        public readonly string $finalUrl,
        public readonly string $html,
        public readonly int $status,
        public readonly bool $redirected = false,
        public readonly bool $landedOnLogin = false,
        public readonly string $contentType = 'text/html',
        public readonly array $warnings = [],
        public readonly bool $inProcess = false,
    ) {}
}
