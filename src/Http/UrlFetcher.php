<?php

namespace ItsJustVita\LaravelBfsg\Http;

use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;

/**
 * Fetches an HTML page for analysis. URLs of this application (a path, or the host of `app.url`) go through the
 * HTTP kernel in-process; everything else through AuthenticatedHttpClient. Redirects are followed (at most
 * MAX_REDIRECTS, each hop checked against the allowed hosts), non-2xx and non-HTML answers are FetchFailed,
 * and same-origin stylesheets are inlined so the contrast analyzer sees them.
 */
class UrlFetcher
{
    public const MAX_REDIRECTS = 5;

    public function __construct(private InProcessFetcher $inProcess) {}

    /** @throws FetchFailed */
    public function fetch(string $url, ?FetchOptions $options = null): FetchedPage
    {
        $options ??= new FetchOptions;
        $client = $options->client ?? new AuthenticatedHttpClient;
        $requested = $this->absolute($url);
        $current = $requested;
        $redirects = 0;

        while (true) {
            $this->assertAllowed($current, $options->allowedHosts);
            $response = $this->request($current, $client, $options);

            if ($response['status'] < 300 || $response['status'] >= 400 || $response['location'] === null) {
                break;
            }

            if (++$redirects > self::MAX_REDIRECTS) {
                throw FetchFailed::tooManyRedirects($requested);
            }

            $current = $this->resolve($current, $response['location']);
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw FetchFailed::status($current, $response['status']);
        }

        if (! $this->isHtml($response['contentType'])) {
            throw FetchFailed::notHtml($current, $response['contentType']);
        }

        $html = $response['body'];
        $warnings = [];

        if ($options->inlineStylesheets ?? filter_var(config('bfsg.fetch.inline_stylesheets', true), FILTER_VALIDATE_BOOL)) {
            [$html, $warnings] = (new StylesheetInliner)->inline($html, $current, fn (string $href) => $this->stylesheet($href, $client, $options));
        }

        return new FetchedPage(
            url: $requested,
            finalUrl: $current,
            html: $html,
            status: $response['status'],
            redirected: $redirects > 0,
            landedOnLogin: $redirects > 0 && $this->isLoginPage($current, $options),
            contentType: $response['contentType'] === '' ? 'text/html' : $response['contentType'],
            warnings: $warnings,
            inProcess: $options->inProcess && $this->isSameApp($current),
        );
    }

    /** A path becomes a URL of this application (app.url); absolute URLs must be http(s) with a host. */
    public function absolute(string $url): string
    {
        $url = trim($url);

        if ($url === '' || (str_starts_with($url, '/') && ! str_starts_with($url, '//'))) {
            $url = rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true) || (string) parse_url($url, PHP_URL_HOST) === '') {
            throw FetchFailed::invalidUrl($url);
        }

        return $url;
    }

    /** Same application: the host equals the host of `app.url`. */
    public function isSameApp(string $url): bool
    {
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return $appHost !== '' && strtolower((string) parse_url($url, PHP_URL_HOST)) === $appHost;
    }

    /** @return array{status: int, location: ?string, contentType: string, body: string} */
    private function request(string $url, AuthenticatedHttpClient $client, FetchOptions $options): array
    {
        if ($options->inProcess && $this->isSameApp($url)) {
            return $this->inProcess->get($url, $options->actingAs, $options->guard);
        }

        try {
            $response = $client->get($url, ['Accept' => 'text/html,application/xhtml+xml']);
        } catch (ConnectionException $e) {
            throw FetchFailed::connection($url, $e->getMessage());
        }

        return [
            'status' => $response->status(),
            'location' => $response->header('Location') === '' ? null : $response->header('Location'),
            'contentType' => $response->header('Content-Type'),
            'body' => $response->body(),
        ];
    }

    private function stylesheet(string $url, AuthenticatedHttpClient $client, FetchOptions $options): ?string
    {
        if ($options->inProcess && $this->isSameApp($url)) {
            $file = $this->inProcess->publicFile((string) parse_url($url, PHP_URL_PATH));

            if ($file !== null) {
                return $file;
            }
        }

        $response = $this->request($url, $client, $options);

        return $response['status'] >= 200 && $response['status'] < 300 ? $response['body'] : null;
    }

    /** @param  list<string>|null  $allowedHosts */
    private function assertAllowed(string $url, ?array $allowedHosts): void
    {
        if ($allowedHosts === null) {
            return;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (! in_array($host, array_map('strtolower', $allowedHosts), true)) {
            throw FetchFailed::hostNotAllowed($url, $host);
        }
    }

    private function resolve(string $base, string $location): string
    {
        return (string) UriResolver::resolve(Utils::uriFor($base), Utils::uriFor($location));
    }

    private function isHtml(string $contentType): bool
    {
        $type = strtolower(trim(explode(';', $contentType)[0]));

        return $type === '' || $type === 'text/html' || $type === 'application/xhtml+xml';
    }

    private function isLoginPage(string $url, FetchOptions $options): bool
    {
        $login = $this->resolve($url, $options->loginUrl ?? (string) config('bfsg.authentication.default_login_url', '/login'));
        $path = fn (string $value): string => rtrim((string) parse_url($value, PHP_URL_PATH), '/');

        return strtolower((string) parse_url($url, PHP_URL_HOST)) === strtolower((string) parse_url($login, PHP_URL_HOST)) && $path($url) === $path($login);
    }
}
