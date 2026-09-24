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
 *
 * Credentials in the URL (`https://user:pass@host/`) are used for the request only: they become an
 * `Authorization: Basic` header bound to that URL's origin (unless the client already carries an Authorization
 * header) and are removed from every URL the fetch hands back or puts into an exception message.
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
        $userInfo = (new Uri($requested))->getUserInfo();
        $requested = self::redact($requested);
        $current = $requested;

        if ($userInfo !== '' && ! $this->hasAuthorization($client)) {
            [$user, $password] = array_pad(explode(':', $userInfo, 2), 2, '');
            $client->withHeaders(['Authorization' => 'Basic '.base64_encode(rawurldecode($user).':'.rawurldecode($password))], $requested);
        }

        // Credentials given without an origin belong to the page asked for, never to a host it redirects to
        $client->bindUnboundHeadersTo($requested);
        $redirects = 0;
        $viaKernel = $options->inProcess;

        while (true) {
            $this->assertAllowed($current, $options->allowedHosts);
            $pin = $this->guard($current, $options);

            // In-process only while every hop so far was in-process: a remote page must not redirect into the kernel
            $viaKernel = $viaKernel && $this->isSameApp($current);
            try {
                $response = $this->request($current, $client, $options, $viaKernel, self::MAX_PAGE_BYTES, $pin);
            } catch (ResponseTooLarge $e) {
                throw FetchFailed::tooLarge($current, $e->limit);
            }

            if ($response['status'] < 300 || $response['status'] >= 400 || $response['location'] === null) {
                break;
            }

            if (++$redirects > self::MAX_REDIRECTS) {
                throw FetchFailed::tooManyRedirects($requested);
            }

            $current = self::redact($this->validated($this->resolve($current, $response['location'])));
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
            [$html, $warnings] = (new StylesheetInliner)->inline($html, $current, fn (string $href) => $this->stylesheet($href, $client, $options, $viaKernel, $current, $pin));
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

    /** $url without the user information of its authority (`https://user:pass@host/` becomes `https://host/`). */
    public static function redact(string $url): string
    {
        return (string) preg_replace('~^((?:[a-z][a-z0-9+.\-]*:)?//)[^/?#]*@~i', '$1', $url);
    }

    private function hasAuthorization(AuthenticatedHttpClient $client): bool
    {
        return in_array('authorization', array_map('strtolower', array_keys($client->headers())), true);
    }

    /** Same application: scheme, host and (effective) port equal those of `app.url`. */
    public function isSameApp(string $url): bool
    {
        $appUrl = (string) config('app.url');

        if ((string) parse_url($appUrl, PHP_URL_HOST) === '' || (string) parse_url($url, PHP_URL_HOST) === '') {
            return false;
        }

        try {
            return AuthenticatedHttpClient::origin($url) === AuthenticatedHttpClient::origin($appUrl);
        } catch (\InvalidArgumentException) {
            return false;
        }
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

    /**
     * Runs the hop guard for $url and turns the addresses it vetted into a CURLOPT_RESOLVE pin, so the connection goes
     * to exactly those addresses and a DNS answer that changes after the check (rebinding) is never used. IP
     * literals and numeric hosts are not resolved by curl and need no pin.
     *
     * @return list<string> CURLOPT_RESOLVE entries (host:port:address,…), empty when nothing is pinned
     *
     * @throws FetchFailed when the guard refuses $url, or a pin is needed but curl is not available
     */
    private function guard(string $url, FetchOptions $options): array
    {
        $addresses = $options->hopGuard === null ? null : ($options->hopGuard)($url);

        if (! is_array($addresses) || $addresses === []) {
            return [];
        }

        $uri = new Uri($url);
        $host = strtolower($uri->getHost());

        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false || PrivateNetworkGuard::parseNumericHost(rtrim($host, '.')) !== null) {
            return [];
        }

        if (! $this->canPinAddresses()) {
            throw FetchFailed::error($url, 'pinning the vetted address of '.$host.' needs the curl extension (the curl handler of Guzzle); without it the page is not fetched.');
        }

        $port = $uri->getPort() ?? (strtolower($uri->getScheme()) === 'https' ? 443 : 80);
        $list = implode(',', array_map(fn (string $ip) => str_contains($ip, ':') ? '['.trim($ip, '[]').']' : $ip, $addresses));
        $name = rtrim($host, '.');

        // curl looks the host up as written: pin it with and without a trailing dot
        return ["{$name}:{$port}:{$list}", "{$name}.:{$port}:{$list}"];
    }

    /** Whether Guzzle uses its curl handler, the only one that honours CURLOPT_RESOLVE. */
    protected function canPinAddresses(): bool
    {
        return defined('CURLOPT_RESOLVE') && function_exists('curl_exec') && function_exists('curl_multi_exec');
    }

    /**
     * @param  list<string>  $pin  CURLOPT_RESOLVE entries for a remote request
     * @return array{status: int, location: ?string, contentType: string, body: string}
     */
    private function request(string $url, AuthenticatedHttpClient $client, FetchOptions $options, bool $viaKernel, int $maxBytes, array $pin = []): array
    {
        try {
            if ($viaKernel) {
                return $this->inProcess->get($url, $options->actingAs, $options->guard);
            }

            $response = $client->get($url, ['Accept' => 'text/html,application/xhtml+xml'], $maxBytes, $pin === [] ? [] : ['curl' => [CURLOPT_RESOLVE => $pin]]);
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

    /**
     * A same-origin stylesheet of the page at $pageUrl. It goes through the allow-list and the hop guard like the page;
     * on the page's own host and port it reuses the page's pin instead of resolving the host again.
     *
     * @param  list<string>  $pagePin
     */
    private function stylesheet(string $url, AuthenticatedHttpClient $client, FetchOptions $options, bool $viaKernel, string $pageUrl, array $pagePin): ?string
    {
        $this->assertAllowed($url, $options->allowedHosts);
        $pin = AuthenticatedHttpClient::origin($url) === AuthenticatedHttpClient::origin($pageUrl) ? $pagePin : $this->guard($url, $options);
        $viaKernel = $viaKernel && $this->isSameApp($url);
        $maxBytes = (int) config('bfsg.fetch.max_stylesheet_bytes', 524288);

        if ($viaKernel) {
            $file = $this->inProcess->publicFile((string) parse_url($url, PHP_URL_PATH), $maxBytes);

            if ($file !== null) {
                return $file;
            }
        }

        $response = $this->request($url, $client, $options, $viaKernel, $maxBytes, $pin);

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
