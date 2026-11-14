<?php

namespace ItsJustVita\LaravelBfsg\Http;

/** An HTML page fetched by UrlFetcher, with linked same-origin stylesheets inlined. */
final class FetchedPage
{
    /** @param  list<string>  $warnings  non-fatal problems (stylesheets that could not be inlined, output the application wrote outside the response) */
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

    /** The sentence both the CLI and the MCP tools use when a page redirected to the login page (credentials removed from the URLs). */
    public static function loginRedirectMessage(string $requestedUrl, string $loginUrl): string
    {
        return UrlFetcher::redact($requestedUrl)." redirected to the login page {$loginUrl}.";
    }
}
