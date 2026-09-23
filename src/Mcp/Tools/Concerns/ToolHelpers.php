<?php

namespace ItsJustVita\LaravelBfsg\Mcp\Tools\Concerns;

use InvalidArgumentException;
use ItsJustVita\LaravelBfsg\Http\AuthenticatedHttpClient;
use ItsJustVita\LaravelBfsg\Http\FetchedPage;
use ItsJustVita\LaravelBfsg\Http\FetchOptions;
use ItsJustVita\LaravelBfsg\Http\PrivateNetworkGuard;
use ItsJustVita\LaravelBfsg\Http\UrlFetcher;
use ItsJustVita\LaravelBfsg\Support\Locale;
use Laravel\Mcp\Request;

/** Argument and page-fetching helpers shared by the MCP tools. */
trait ToolHelpers
{
    /**
     * Fetch through UrlFetcher, TLS verified per `bfsg.mcp.verify_ssl`. With `bfsg.mcp.allowed_hosts` the list governs
     * every hop; without one (null or []), hosts that resolve to loopback, private, link-local or unspecified
     * addresses are refused on every hop, except the host of app.url.
     */
    protected function fetchPage(string $url): FetchedPage
    {
        $hosts = array_values(array_map('strval', (array) config('bfsg.mcp.allowed_hosts')));
        $guard = null;

        if ($hosts === []) {
            $appHost = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
            $network = app(PrivateNetworkGuard::class)->exempting($appHost === '' ? [] : [$appHost]);
            $guard = fn (string $hop) => $network->check($hop);
        }

        return app(UrlFetcher::class)->fetch($url, new FetchOptions(
            client: new AuthenticatedHttpClient(verifySsl: filter_var(config('bfsg.mcp.verify_ssl', true), FILTER_VALIDATE_BOOL)),
            allowedHosts: $hosts === [] ? null : $hosts,
            hopGuard: $guard,
        ));
    }

    /** A non-empty string argument, or null. */
    protected function stringArgument(Request $request, string $name): ?string
    {
        $value = $request->get($name);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * The `locale` argument validated with Support\Locale (well-formed and translated), or null for the configured
     * locale. It becomes a path segment of the translation loader, so it is never used unvalidated.
     *
     * @throws InvalidArgumentException for a non-string, malformed or unavailable locale
     */
    protected function localeArgument(Request $request): ?string
    {
        $value = $request->get('locale');

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('The locale parameter must be a string such as en or de.');
        }

        return Locale::validate($value);
    }
}
