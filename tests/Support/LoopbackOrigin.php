<?php

namespace ItsJustVita\LaravelBfsg\Tests\Support;

use Closure;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Promise\Is;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A fake origin on 127.0.0.1 inside the test process (no child process): a non-blocking listening socket, served
 * between the ticks of Guzzle's curl multi handler. install() hands every request of the package, with the Guzzle
 * options the package set (on_headers, progress, decode_content, curl), to real libcurl.
 */
final class LoopbackOrigin
{
    /** @var resource */
    private $socket;

    public int $port;

    /** @var array<int, array{s: resource, in: string, out: ?string, off: int}> */
    private array $conns = [];

    /** @var list<string> what happened to each response: "sent all N bytes" or "client hung up after N of M bytes" */
    public array $sent = [];

    /** @param  Closure(string): string  $route  request target => raw HTTP response */
    public function __construct(private Closure $route)
    {
        $this->socket = stream_socket_server('tcp://127.0.0.1:0');
        stream_set_blocking($this->socket, false);
        $this->port = (int) substr((string) strrchr((string) stream_socket_get_name($this->socket, false), ':'), 1);
    }

    public function url(string $path): string
    {
        return "http://127.0.0.1:{$this->port}{$path}";
    }

    /** @param  array<string, string>  $headers */
    public static function response(int $status, array $headers, string $body, bool $contentLength = true): string
    {
        $head = "HTTP/1.1 {$status} X\r\nConnection: close\r\n";

        foreach ($headers + ($contentLength ? ['Content-Length' => (string) strlen($body)] : []) as $name => $value) {
            $head .= "{$name}: {$value}\r\n";
        }

        return $head."\r\n".$body;
    }

    /** gzip of $prefix followed by at least $bytes bytes of repeated $unit, built in 256 KiB steps. */
    public static function gzip(int $bytes, string $unit, string $prefix = ''): string
    {
        $deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 9]);
        $out = deflate_add($deflate, $prefix, ZLIB_NO_FLUSH);
        $chunk = str_repeat($unit, (int) ceil(262144 / strlen($unit)));

        for ($done = 0; $done < $bytes; $done += strlen($chunk)) {
            $out .= deflate_add($deflate, $chunk, ZLIB_NO_FLUSH);
        }

        return $out.deflate_add($deflate, '', ZLIB_FINISH);
    }

    public function pump(): void
    {
        while ($conn = @stream_socket_accept($this->socket, 0)) {
            stream_set_blocking($conn, false);
            $this->conns[] = ['s' => $conn, 'in' => '', 'out' => null, 'off' => 0];
        }

        foreach ($this->conns as $i => $conn) {
            if ($conn['out'] === null) {
                $this->conns[$i]['in'] .= (string) @fread($conn['s'], 65536);

                if (str_contains($this->conns[$i]['in'], "\r\n\r\n")) {
                    preg_match('~^\S+ (\S+)~', $this->conns[$i]['in'], $m);
                    $this->conns[$i]['out'] = ($this->route)($m[1] ?? '/');
                }

                continue;
            }

            $written = @fwrite($conn['s'], substr($conn['out'], $conn['off'], 262144));
            $done = $written === false || ($written === 0 && feof($conn['s']));
            $this->conns[$i]['off'] += (int) $written;

            if ($done || $this->conns[$i]['off'] >= strlen($conn['out'])) {
                $this->sent[] = $done ? "client hung up after {$this->conns[$i]['off']} of ".strlen($conn['out']).' bytes' : 'sent all '.strlen($conn['out']).' bytes';
                fclose($conn['s']);
                unset($this->conns[$i]);
            }
        }
    }

    public function install(): void
    {
        Http::preventStrayRequests(false);
        $multi = new CurlMultiHandler(['select_timeout' => 0.002]);

        Http::fake(function (Request $request, array $options) use ($multi) {
            $promise = $multi($request->toPsrRequest(), $options);

            for ($until = microtime(true) + 60; Is::pending($promise) && microtime(true) < $until;) {
                $this->pump();
                $multi->tick();
            }

            return $promise;
        });
    }
}
