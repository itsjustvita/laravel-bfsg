<?php

namespace ItsJustVita\LaravelBfsg\Http;

use RuntimeException;

/** A response body (page or stylesheet) exceeded its byte limit; the transfer is aborted as early as the transport allows. */
final class ResponseTooLarge extends RuntimeException
{
    public function __construct(public readonly string $url, public readonly int $limit)
    {
        parent::__construct("{$url} is larger than {$limit} bytes.");
    }
}
