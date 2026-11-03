<?php

namespace ItsJustVita\LaravelBfsg\Mcp\Tools\Concerns;

use Closure;
use InvalidArgumentException;
use ItsJustVita\LaravelBfsg\Http\AuthenticatedHttpClient;
use ItsJustVita\LaravelBfsg\Http\FetchedPage;
use ItsJustVita\LaravelBfsg\Http\FetchFailed;
use ItsJustVita\LaravelBfsg\Http\FetchOptions;
use ItsJustVita\LaravelBfsg\Http\PrivateNetworkGuard;
use ItsJustVita\LaravelBfsg\Http\UrlFetcher;
use ItsJustVita\LaravelBfsg\Support\Locale;
use ItsJustVita\LaravelBfsg\Support\Sanitizer;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Throwable;

/** Argument, page-fetching and response helpers shared by the MCP tools. */
trait ToolHelpers
{
    /** Longest error text (in characters) a tool returns; a longer one ends in "…". */
    private const MAX_ERROR_LENGTH = 300;

    /** Longest URL (in characters) inside an error text, so that a long URL cannot crowd out the rest of it. */
    private const MAX_URL_IN_ERROR = 120;

    /**
     * Runs a tool's handler so that nothing it throws reaches laravel/mcp: with app.debug on, laravel/mcp 0.6 re-throws
     * it and ends the stdio server, 1.x returns the raw message. A FetchFailed becomes its own message; anything else is
     * passed to the application's exception handler (the log, never stdout) and becomes "Analysis failed: <message>".
     * Every message goes through cleanError().
     *
     * @param  Closure(): Response  $handler
     */
    protected function safely(Closure $handler): Response
    {
        try {
            return $handler();
        } catch (FetchFailed $e) {
            return Response::error(self::cleanError($e->getMessage()));
        } catch (Throwable $e) {
            try {
                report($e);
            } catch (Throwable) {
                // An exception handler that cannot log must not end the server either
            }

            return Response::error('Analysis failed: '.self::cleanError($e->getMessage()));
        }
    }

    /** A tool error (isError) with the text cleaned by cleanError(). */
    protected function toolError(string $message): Response
    {
        return Response::error(self::cleanError($message));
    }

    /**
     * Response::json() with invalid UTF-8 replaced by U+FFFD: a stored or page-derived string can never make the
     * encoding fail, and the JSON-RPC encoder never meets invalid UTF-8 (it would write an empty line instead).
     */
    protected function toolJson(mixed $content): Response
    {
        return Response::text(json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
    }

    /**
     * An error text fit for the client and the JSON-RPC channel: valid UTF-8 without control characters
     * (Sanitizer::terminal), credentials removed from every URL in it (UrlFetcher::redact; exception messages from
     * Guzzle or a database driver can carry them), each URL and the whole text shortened.
     */
    protected static function cleanError(string $message): string
    {
        $message = Sanitizer::terminal($message);
        $message = preg_replace_callback(
            '~\b[a-z][a-z0-9+.\-]*://[^\s"\'<>]+~iu',
            fn (array $url): string => Sanitizer::truncate(UrlFetcher::redact($url[0]), self::MAX_URL_IN_ERROR),
            $message,
        ) ?? $message;

        return Sanitizer::truncate($message, self::MAX_ERROR_LENGTH);
    }

    /**
     * Fetch through UrlFetcher, TLS verified per `bfsg.mcp.verify_ssl`. With `bfsg.mcp.allowed_hosts` the list governs
     * every hop; without one (null or []), hosts that resolve to non-public addresses (loopback, private, link-local,
     * reserved, see PrivateNetworkGuard) are refused on every hop that goes over the network. URLs of this application
     * (the origin of app.url: scheme, host and port) are exempt only while UrlFetcher renders them in-process; once a
     * remote page redirects there, that hop goes over HTTP and is guarded (and pinned) like any other.
     */
    protected function fetchPage(string $url): FetchedPage
    {
        $hosts = array_values(array_map('strval', (array) config('bfsg.mcp.allowed_hosts')));
        $fetcher = app(UrlFetcher::class);
        $guard = null;

        if ($hosts === []) {
            $guard = app(PrivateNetworkGuard::class)->check(...);
        }

        return $fetcher->fetch($url, new FetchOptions(
            client: new AuthenticatedHttpClient(verifySsl: AuthenticatedHttpClient::verifySslSetting(config('bfsg.mcp.verify_ssl', true))),
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
