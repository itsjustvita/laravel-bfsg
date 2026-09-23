<?php

namespace ItsJustVita\LaravelBfsg\Http;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Fetches an HTML page for analysis. URLs of this application (a path, or the origin of `app.url`: scheme, host
 * and port) go through the HTTP kernel in-process; everything else through AuthenticatedHttpClient. Redirects are
 * followed (at most MAX_REDIRECTS, every hop validated as an http(s) URL and checked against the allowed hosts);
 * once a hop has gone over HTTP, later hops into this application go over HTTP too, without `actingAs`. Non-2xx
 * and non-HTML answers and every other error are FetchFailed, and same-origin stylesheets are inlined so the
 * contrast analyzer sees them.
 */
class UrlFetcher
{
    public const MAX_REDIRECTS = 5;

    /** Largest page body analyzed (bytes); remote transfers are aborted beyond it. Stylesheets use `bfsg.fetch.max_stylesheet_bytes`. */
    public const MAX_PAGE_BYTES = 5 * 1024 * 1024;

    public function __construct(private InProcessFetcher $inProcess) {}

    /** @throws FetchFailed */
    public function fetch(string $url, ?FetchOptions $options = null): FetchedPage
    {
        $options ??= new FetchOptions;
        $client = $options->client ?? new AuthenticatedHttpClient;
        $requested = $this->absolute($url);
        $current = $requested;
        // Credentials given without an origin belong to the page asked for, never to a host it redirects to
        $client->bindUnboundHeadersTo($requested);
        $redirects = 0;
        $viaKernel = $options->inProcess;

        while (true) {
            $this->assertAllowed($current, $options->allowedHosts);
            // In-process only while every hop so far was in-process: a remote page must not redirect into the kernel
            $viaKernel = $viaKernel && $this->isSameApp($current);
            try {
                $response = $this->request($current, $client, $options, $viaKernel, self::MAX_PAGE_BYTES);
            } catch (ResponseTooLarge $e) {
                throw FetchFailed::tooLarge($current, $e->limit);
            }

            if ($response['status'] < 300 || $response['status'] >= 400 || $response['location'] === null) {
                break;
            }

            if (++$redirects > self::MAX_REDIRECTS) {
                throw FetchFailed::tooManyRedirects($requested);
            }

            $current = $this->validated($this->resolve($current, $response['location']));
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw FetchFailed::status($current, $response['status']);
        }

        if (! $this->isHtml($response['contentType'])) {
            throw FetchFailed::notHtml($current, $response['contentType']);
        }

        if (strlen($response['body']) > self::MAX_PAGE_BYTES) {
            throw FetchFailed::tooLarge($current, self::MAX_PAGE_BYTES);
        }

        $html = $response['body'];
        $warnings = [];

        if ($options->inlineStylesheets ?? filter_var(config('bfsg.fetch.inline_stylesheets', true), FILTER_VALIDATE_BOOL)) {
            [$html, $warnings] = (new StylesheetInliner)->inline($html, $current, fn (string $href) => $this->stylesheet($href, $client, $options, $viaKernel));
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
            inProcess: $viaKernel,
        );
    }

    /** A path becomes a URL of this application (app.url); absolute URLs must be http(s) with a host. */
    public function absolute(string $url): string
    {
        $url = trim($url);

        if ($url === '' || (str_starts_with($url, '/') && ! str_starts_with($url, '//'))) {
            $url = rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
        }

        return $this->validated($url);
    }

    /** Same application: scheme, host and (effective) port equal those of `app.url`. */
    public function isSameApp(string $url): bool
    {
        $appUrl = (string) config('app.url');

        return (string) parse_url($appUrl, PHP_URL_HOST) !== '' && (string) parse_url($url, PHP_URL_HOST) !== ''
            && AuthenticatedHttpClient::origin($url) === AuthenticatedHttpClient::origin($appUrl);
    }

    /** $url if it is a well-formed http(s) URL with a host, else FetchFailed::invalidUrl. */
    private function validated(string $url): string
    {
        try {
            $uri = new Uri($url);
        } catch (Throwable) {
            throw FetchFailed::invalidUrl($url);
        }

        if (! in_array(strtolower($uri->getScheme()), ['http', 'https'], true) || $uri->getHost() === '') {
            throw FetchFailed::invalidUrl($url);
        }

        return $url;
    }

    /** @return array{status: int, location: ?string, contentType: string, body: string} */
    private function request(string $url, AuthenticatedHttpClient $client, FetchOptions $options, bool $viaKernel, int $maxBytes): array
    {
        try {
            if ($viaKernel) {
                return $this->inProcess->get($url, $options->actingAs, $options->guard);
            }

            $response = $client->get($url, ['Accept' => 'text/html,application/xhtml+xml'], $maxBytes);
        } catch (FetchFailed|ResponseTooLarge $e) {
            throw $e;
        } catch (ConnectionException $e) {
            throw FetchFailed::connection($url, $e->getMessage());
        } catch (Throwable $e) {
            throw FetchFailed::error($url, $e->getMessage());
        }

        return [
            'status' => $response->status(),
            'location' => $response->header('Location') === '' ? null : $response->header('Location'),
            'contentType' => $response->header('Content-Type'),
            'body' => $response->body(),
        ];
    }

    private function stylesheet(string $url, AuthenticatedHttpClient $client, FetchOptions $options, bool $viaKernel): ?string
    {
        $viaKernel = $viaKernel && $this->isSameApp($url);
        $maxBytes = (int) config('bfsg.fetch.max_stylesheet_bytes', 524288);

        if ($viaKernel) {
            $file = $this->inProcess->publicFile((string) parse_url($url, PHP_URL_PATH), $maxBytes);

            if ($file !== null) {
                return $file;
            }
        }

        $response = $this->request($url, $client, $options, $viaKernel, $maxBytes);

        return $response['status'] >= 200 && $response['status'] < 300 ? $response['body'] : null;
    }

    /** @param  list<string>|null  $allowedHosts */
    private function assertAllowed(string $url, ?array $allowedHosts): void
    {
        if ($allowedHosts === null) {
            return;
        }

        $host = self::normalizeHost((string) parse_url($url, PHP_URL_HOST));

        if (! in_array($host, array_map(self::normalizeHost(...), $allowedHosts), true)) {
            throw FetchFailed::hostNotAllowed($url, $host);
        }
    }

    /** Allow-list form of a host: lowercase, without IPv6 brackets and without a trailing dot. */
    private static function normalizeHost(string $host): string
    {
        return rtrim(trim(strtolower(trim($host)), '[]'), '.');
    }

    private function resolve(string $base, string $location): string
    {
        try {
            return (string) UriResolver::resolve(Utils::uriFor($base), Utils::uriFor($location));
        } catch (Throwable) {
            throw FetchFailed::invalidUrl($location);
        }
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
