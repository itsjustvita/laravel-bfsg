<?php

namespace ItsJustVita\LaravelBfsg\Http;

use RuntimeException;

/** A page could not be fetched as HTML; `status` is the final HTTP status when there was one. */
final class FetchFailed extends RuntimeException
{
    public function __construct(string $message, public readonly string $url, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }

    public static function invalidUrl(string $url): self
    {
        return new self("Invalid URL: {$url} (expected an http(s) URL or a path of this application).", $url);
    }

    public static function hostNotAllowed(string $url, string $host): self
    {
        return new self("The host {$host} is not in the list of allowed hosts.", $url);
    }

    public static function privateAddress(string $url, string $host, string $address): self
    {
        return new self("The host {$host} resolves to {$address}, a loopback, private, link-local or unspecified address. List it in bfsg.mcp.allowed_hosts to allow it.", $url);
    }

    public static function malformedAddress(string $url, string $host): self
    {
        return new self("The host {$host} looks like a numeric address but is not a valid IPv4 address.", $url);
    }

    public static function unresolvable(string $url, string $host): self
    {
        return new self("The host {$host} does not resolve to any address.", $url);
    }

    public static function status(string $url, int $status): self
    {
        return new self("Fetching {$url} failed with HTTP {$status}.", $url, $status);
    }

    public static function notHtml(string $url, string $contentType): self
    {
        return new self("{$url} is not an HTML page (Content-Type: {$contentType}).", $url, 200);
    }

    public static function tooLarge(string $url, int $limit): self
    {
        return new self("{$url} is larger than {$limit} bytes.", $url);
    }

    public static function tooManyRedirects(string $url): self
    {
        return new self("Fetching {$url} stopped after ".UrlFetcher::MAX_REDIRECTS.' redirects.', $url);
    }

    public static function error(string $url, string $detail): self
    {
        return new self("Fetching {$url} failed: {$detail}", $url);
    }

    public static function connection(string $url, string $detail): self
    {
        return new self("Could not connect to {$url}: {$detail}", $url);
    }
}
