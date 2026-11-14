<?php

namespace ItsJustVita\LaravelBfsg\Http;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * The Guzzle `sink` of a capped download: a php://temp stream that throws ResponseTooLarge as soon as more than
 * $maxBytes were written. Writes carry decoded bytes (curl inflates a gzip/deflate/br body before the write
 * callback), so the cap counts what is kept, not what crossed the wire.
 */
final class LimitedSink implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    private int $written = 0;

    public function __construct(private readonly string $url, private readonly int $maxBytes)
    {
        $this->stream = Utils::streamFor(Utils::tryFopen('php://temp', 'r+'));
    }

    public function write(string $string): int
    {
        $this->written += strlen($string);

        if ($this->written > $this->maxBytes) {
            throw new ResponseTooLarge($this->url, $this->maxBytes);
        }

        return $this->stream->write($string);
    }
}
