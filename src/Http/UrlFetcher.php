<?php

namespace ItsJustVita\LaravelBfsg\Http;

use GuzzleHttp\Handler\ProxyEnvironment;
use GuzzleHttp\ProxyOptions;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\Utils as GuzzleUtils;
use Illuminate\Http\Client\ConnectionException;
use Psr\Http\Message\UriInterface;
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
 *
 * Hops checked by a hop guard (FetchOptions::$hopGuard, the MCP tools) are not sent through a proxy (see
 * proxyInEffect()): a proxy resolves the host itself, so the pin to the vetted addresses would be void. With
 * `bfsg.mcp.allow_proxy` they are, and the page carries a warning.
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
        $basic = self::basicAuthorization($requested);
        $requested = self::redact($requested);
        $current = $requested;

        if ($basic !== null && ! $this->hasAuthorization($client)) {
            $client->withHeaders(['Authorization' => $basic], $requested);
        }

        // Credentials given without an origin belong to the page asked for, never to a host it redirects to
        $client->bindUnboundHeadersTo($requested);
        $redirects = 0;
        $viaKernel = $options->inProcess;
        // In-process hops report output the application wrote outside the response
        $warnings = [];

        while (true) {
            $this->assertAllowed($current, $options->allowedHosts);
            // In-process only while every hop so far was in-process: a remote page must not redirect into the kernel
            $viaKernel = $viaKernel && $this->isSameApp($current);
            // The hop guard covers every hop that goes over the network, the app origin included; only in-process hops skip it
            $pin = $viaKernel ? [] : $this->guard($current, $options, $client, $warnings);
            try {
                $response = $this->request($current, $client, $options, $viaKernel, self::MAX_PAGE_BYTES, $pin);
            } catch (ResponseTooLarge $e) {
                throw FetchFailed::tooLarge($current, $e->limit);
            }

            $warnings = [...$warnings, ...$response['warnings']];

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

        if ($options->inlineStylesheets ?? filter_var(config('bfsg.fetch.inline_stylesheets', true), FILTER_VALIDATE_BOOL)) {
            [$html, $inlinerWarnings] = (new StylesheetInliner)->inline($html, $current, function (string $href) use ($client, $options, $viaKernel, $current, $pin, &$warnings) {
                return $this->stylesheet($href, $client, $options, $viaKernel, $current, $pin, $warnings);
            });
            $warnings = [...$warnings, ...$inlinerWarnings];
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

    /** The `Authorization: Basic …` value for the credentials in $url (`https://user:pass@host/`), null when it has none. */
    public static function basicAuthorization(string $url): ?string
    {
        try {
            $userInfo = (new Uri($url))->getUserInfo();
        } catch (Throwable) {
            return null;
        }

        if ($userInfo === '') {
            return null;
        }

        [$user, $password] = array_pad(explode(':', $userInfo, 2), 2, '');

        return 'Basic '.base64_encode(rawurldecode($user).':'.rawurldecode($password));
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
     * A guarded hop that would go through a proxy is refused unless `bfsg.mcp.allow_proxy` is true; then it is fetched
     * and $warnings gets (once) a note that the pin is not enforced.
     *
     * @param  list<string>  $warnings
     * @return list<string> CURLOPT_RESOLVE entries (host:port:address,…), empty when nothing is pinned
     *
     * @throws FetchFailed when the guard refuses $url, a proxy is used without allow_proxy, or a pin is needed but curl
     *                     is not available
     */
    private function guard(string $url, FetchOptions $options, AuthenticatedHttpClient $client, array &$warnings): array
    {
        if ($options->hopGuard === null) {
            return [];
        }

        $addresses = ($options->hopGuard)($url);

        if (self::proxyInEffect($url, $client->request()->getOptions())) {
            if (! filter_var(config('bfsg.mcp.allow_proxy', false), FILTER_VALIDATE_BOOL)) {
                throw FetchFailed::proxyNotAllowed($url);
            }

            $warning = (string) __('bfsg::report.fetch_warnings.proxy_unpinned');
            $warnings = in_array($warning, $warnings, true) ? $warnings : [...$warnings, $warning];
        }

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
     * Whether a request for $url with these Guzzle request options goes through a proxy, decided the way the installed
     * Guzzle (7 or 8) and libcurl decide it:
     *
     * 1. The `proxy` request option, or else Guzzle's default built from HTTPS_PROXY, HTTP_PROXY (only in the CLI: a
     *    CGI request header `Proxy:` becomes HTTP_PROXY) and NO_PROXY, read from $_SERVER or, in the CLI, the
     *    environment. A string is the proxy ('' switches proxies off, the environment's too); an array names one per
     *    scheme, unless its `no` list covers the host by Guzzle's own match, which leaves the request direct (Guzzle 8
     *    and 7.12+) or to step 2 (earlier Guzzle 7).
     * 2. Otherwise the process environment: http_proxy (never HTTP_PROXY) or https_proxy, then HTTPS_PROXY, then
     *    all_proxy and ALL_PROXY, unless no_proxy (or else NO_PROXY) covers the host.
     *
     * Guzzle 8 and 7.12+ resolve all of this themselves and pin the result (CURLOPT_PROXY, CURLOPT_NOPROXY). Earlier
     * Guzzle 7 versions leave step 2 to libcurl, which also applies no_proxy/NO_PROXY of the environment to a proxy from
     * step 1 (curlNoProxy()). Hence the differences: there, a lowercase no_proxy exempts from HTTPS_PROXY too, and
     * `.example.com` covers example.com itself, which in Guzzle 7.12+ it does not (only its subdomains). Empty
     * variables count as unset; what cannot be decided counts as a proxy.
     *
     * @param  array<string, mixed>  $options  Guzzle request options (PendingRequest::getOptions())
     */
    public static function proxyInEffect(string $url, array $options): bool
    {
        try {
            $uri = new Uri($url);
            // Guzzle merges request options over its defaults key by key: a `proxy` key, even null, replaces the default
            $option = array_key_exists('proxy', $options) ? $options['proxy'] : self::guzzleProxyDefault();
            $noProxy = self::processEnv('no_proxy') ?? self::processEnv('NO_PROXY');

            if (class_exists(ProxyOptions::class)) {
                return self::guzzle8Option($uri, $option)
                    ?? (self::environmentProxy($uri) && ($noProxy === null || ! ProxyOptions::isUriInNoProxy($uri, preg_split('/[\s,]+/', $noProxy, -1, PREG_SPLIT_NO_EMPTY) ?: [])));
            }

            if (class_exists(ProxyEnvironment::class)) {
                return self::guzzle7Option($uri, $option, noMatchIsFinal: true)
                    ?? (self::environmentProxy($uri) && ($noProxy === null || ! GuzzleUtils::isUriInNoProxy($uri, ProxyEnvironment::splitNoProxy($noProxy))));
            }

            if ($noProxy !== null && self::curlNoProxy($uri, $noProxy)) {
                return false;
            }

            return self::guzzle7Option($uri, $option, noMatchIsFinal: false) ?? self::environmentProxy($uri);
        } catch (Throwable) {
            return true;
        }
    }

    /** @return array<string, mixed>|null Guzzle's default `proxy` option (Client::configureDefaults()), null when none */
    private static function guzzleProxyDefault(): ?array
    {
        $default = [];

        if (PHP_SAPI === 'cli' && ($proxy = self::guzzleEnv('HTTP_PROXY'))) {
            $default['http'] = $proxy;
        }

        if ($proxy = self::guzzleEnv('HTTPS_PROXY')) {
            $default['https'] = $proxy;
        }

        if ($noProxy = self::guzzleEnv('NO_PROXY')) {
            // Guzzle 8 splits at commas and blanks (ProxyOptions::resolve() does it), Guzzle 7 at commas only
            $default['no'] = class_exists(ProxyOptions::class) ? $noProxy : explode(',', str_replace(' ', '', $noProxy));
        }

        return $default === [] ? null : $default;
    }

    /** A variable as Guzzle's client reads it (Utils::getenv(), Env::get()): $_SERVER, then the environment in the CLI. */
    private static function guzzleEnv(string $name): ?string
    {
        if (isset($_SERVER[$name]) && is_scalar($_SERVER[$name])) {
            return (string) $_SERVER[$name];
        }

        return PHP_SAPI === 'cli' && is_string($value = getenv($name)) ? $value : null;
    }

    /** A variable as libcurl and Guzzle's handlers read it: the process environment only; empty = unset. */
    private static function processEnv(string $name): ?string
    {
        $value = getenv($name, true);

        return $value === false || $value === '' ? null : $value;
    }

    /** @return bool|null the decision of Guzzle 8's `proxy` option; null when it leaves it to the environment */
    private static function guzzle8Option(UriInterface $uri, mixed $option): ?bool
    {
        $selection = ProxyOptions::resolve($uri, $option);

        return $selection->hasProxy() ? true : ($selection->shouldDisableProxy() ? false : null);
    }

    /**
     * @param  bool  $noMatchIsFinal  a `no` match means direct (7.12+), not "ask the environment" (earlier versions)
     * @return bool|null the decision of Guzzle 7's `proxy` option (CurlFactory); null when it leaves it to the environment
     */
    private static function guzzle7Option(UriInterface $uri, mixed $option, bool $noMatchIsFinal): ?bool
    {
        if (! is_array($option)) {
            return $option === null ? null : (string) $option !== '';
        }

        if (! isset($option[$uri->getScheme()])) {
            return null;
        }

        // Guzzle 7.11+ matches the URI (ports included), earlier versions the host
        $exempt = isset($option['no']) && (method_exists(GuzzleUtils::class, 'isUriInNoProxy')
            ? GuzzleUtils::isUriInNoProxy($uri, $option['no'])
            : GuzzleUtils::isHostInNoProxy($uri->getHost(), $option['no']));

        if (! $exempt) {
            return (string) $option[$uri->getScheme()] !== '';
        }

        return $noMatchIsFinal ? false : null;
    }

    /** Whether the process environment names a proxy for the URL's scheme (libcurl's lookup order). */
    private static function environmentProxy(UriInterface $uri): bool
    {
        $scheme = strtolower($uri->getScheme());

        foreach ([...($scheme === 'http' ? ['http_proxy'] : [$scheme.'_proxy', strtoupper($scheme).'_PROXY']), 'all_proxy', 'ALL_PROXY'] as $name) {
            if (self::processEnv($name) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * libcurl's no_proxy match (Curl_check_noproxy), for Guzzle 7 before 7.12: `*` only as the whole value; entries
     * separated by commas, blanks around them skipped (a blank inside an entry ends the list); a leading and a trailing
     * dot ignored; case-insensitive; the host itself or a parent domain. An IP host matches an identical entry only
     * (CIDR entries are not read, so such a host counts as proxied).
     */
    private static function curlNoProxy(UriInterface $uri, string $noProxy): bool
    {
        if ($noProxy === '*') {
            return true;
        }

        $host = strtolower(trim($uri->getHost(), '[]'));
        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $host = $ip ? $host : (string) preg_replace('/\.$/', '', $host);
        $rest = $noProxy;

        while (preg_match('/^[ \t]*([^ \t,]*)[ \t]*/', $rest, $match) === 1) {
            $rest = substr($rest, strlen($match[0]));
            $entry = strtolower($ip ? $match[1] : (string) preg_replace('/^\.|\.$/', '', $match[1]));

            if ($entry !== '' && ($entry === $host || (! $ip && str_ends_with($host, '.'.$entry)))) {
                return true;
            }

            if (! str_starts_with($rest, ',')) {
                return false;
            }

            $rest = ltrim($rest, ',');
        }

        return false;
    }

    /**
     * @param  list<string>  $pin  CURLOPT_RESOLVE entries for a remote request
     * @return array{status: int, location: ?string, contentType: string, body: string, warnings: list<string>}
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
            'warnings' => [],
        ];
    }

    /**
     * A same-origin stylesheet of the page at $pageUrl. It goes through the allow-list and (unless it is read in-process)
     * the hop guard like the page; on the page's own host and port it reuses the page's pin instead of resolving the host
     * again.
     *
     * @param  list<string>  $pagePin
     * @param  list<string>  $warnings
     */
    private function stylesheet(string $url, AuthenticatedHttpClient $client, FetchOptions $options, bool $viaKernel, string $pageUrl, array $pagePin, array &$warnings): ?string
    {
        $this->assertAllowed($url, $options->allowedHosts);
        $viaKernel = $viaKernel && $this->isSameApp($url);
        $pin = match (true) {
            $viaKernel => [],
            AuthenticatedHttpClient::origin($url) === AuthenticatedHttpClient::origin($pageUrl) => $pagePin,
            default => $this->guard($url, $options, $client, $warnings),
        };
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
