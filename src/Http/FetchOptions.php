<?php

namespace ItsJustVita\LaravelBfsg\Http;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

/** Per-call options of UrlFetcher::fetch(); null values fall back to the `bfsg.fetch` / `bfsg.authentication` config. */
final class FetchOptions
{
    /**
     * @param  AuthenticatedHttpClient|null  $client  client for remote requests (carries cookies, tokens, TLS setting)
     * @param  Authenticatable|null  $actingAs  user for in-process requests to this application
     * @param  list<string>|null  $allowedHosts  hostnames a URL (and every redirect) may point at; null = any. Plain hosts
     *                                           without scheme or port, compared case-insensitively; IPv6 with or without
     *                                           brackets (`::1`, `[::1]`); a trailing dot is ignored (`example.com.`)
     * @param  bool  $inProcess  false = request URLs of this application over HTTP too (needed for cookie/token logins)
     * @param  (Closure(string): (list<string>|null))|null  $hopGuard  called with the URL, every redirect target and
     *                                                                 every stylesheet on another origin before it is
     *                                                                 requested; throws FetchFailed to refuse it (see
     *                                                                 PrivateNetworkGuard). The addresses it returns are
     *                                                                 pinned (CURLOPT_RESOLVE) for that request
     */
    public function __construct(
        public readonly ?AuthenticatedHttpClient $client = null,
        public readonly ?Authenticatable $actingAs = null,
        public readonly ?string $guard = null,
        public readonly ?array $allowedHosts = null,
        public readonly ?bool $inlineStylesheets = null,
        public readonly ?string $loginUrl = null,
        public readonly bool $inProcess = true,
        public readonly ?Closure $hopGuard = null,
    ) {}
}
