<?php

namespace ItsJustVita\LaravelBfsg\Http;

use Illuminate\Contracts\Auth\Authenticatable;

/** Per-call options of UrlFetcher::fetch(); null values fall back to the `bfsg.fetch` / `bfsg.authentication` config. */
final class FetchOptions
{
    /**
     * @param  AuthenticatedHttpClient|null  $client  client for remote requests (carries cookies, tokens, TLS setting)
     * @param  Authenticatable|null  $actingAs  user for in-process requests to this application
     * @param  list<string>|null  $allowedHosts  hostnames a URL (and every redirect) may point at; null = any
     */
    public function __construct(
        public readonly ?AuthenticatedHttpClient $client = null,
        public readonly ?Authenticatable $actingAs = null,
        public readonly ?string $guard = null,
        public readonly ?array $allowedHosts = null,
        public readonly ?bool $inlineStylesheets = null,
        public readonly ?string $loginUrl = null,
    ) {}
}
