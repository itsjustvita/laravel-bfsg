<?php

namespace ItsJustVita\LaravelBfsg\Http;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Requests pages of the running application through its HTTP kernel instead of a web server: no `php -S`,
 * no TLS, no network. The request carries the SKIP_ATTRIBUTE so the package's own middleware ignores it, and
 * the kernel is not terminated (terminable middleware does not run for a check).
 *
 * Every fetch is isolated: it runs with no resolved guards and a fresh session store, and afterwards the caller's
 * state is put back exactly: its resolved guards (with their users), its session store, the session manager's
 * drivers, its default guard and its request instance. No user, session data or request leaks from one fetch into
 * the next, and the calling process (a long-lived MCP server, a multi-page run, or a web request) keeps its own.
 */
class InProcessFetcher
{
    public const SKIP_ATTRIBUTE = 'bfsg.in_process';

    public function __construct(private Application $app) {}

    /** @return array{status: int, location: ?string, contentType: string, body: string} */
    public function get(string $url, ?Authenticatable $user = null, ?string $guard = null): array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        $request = Request::create($url, 'GET', server: [
            'HTTP_HOST' => $host.($port === null ? '' : ':'.$port),
            'HTTPS' => $scheme === 'https' ? 'on' : 'off',
            'HTTP_ACCEPT' => 'text/html,application/xhtml+xml',
        ]);
        $request->attributes->set(self::SKIP_ATTRIBUTE, true);

        $auth = $this->app->make('auth');
        $previousRequest = $this->app->bound('request') ? $this->app->make('request') : null;
        $previousGuard = $auth->getDefaultDriver();
        $previous = $this->snapshot();
        $this->reset();

        try {
            if ($user !== null) {
                $auth->guard($guard)->setUser($user);

                if ($guard !== null) {
                    $auth->shouldUse($guard);
                }
            }

            $response = $this->app->make(Kernel::class)->handle($request);

            return [
                'status' => $response->getStatusCode(),
                'location' => $response->headers->get('Location'),
                'contentType' => (string) $response->headers->get('Content-Type', ''),
                'body' => $this->body($response),
            ];
        } finally {
            $this->reset();
            $auth->shouldUse($previousGuard);
            $this->restore($previous);

            if ($previousRequest !== null) {
                $this->app->instance('request', $previousRequest);
                Facade::clearResolvedInstance('request');
            }
        }
    }

    /**
     * The caller's resolved guards, session manager drivers and session store. AuthManager and Manager keep them in
     * protected properties without accessors, so they are read and written through a closure bound to the object.
     *
     * @return array{guards: array<string, mixed>, drivers: ?array<string, mixed>, store: ?object}
     */
    private function snapshot(): array
    {
        $session = $this->app->bound('session') ? $this->app->make('session') : null;

        return [
            'guards' => (fn () => $this->guards)->call($this->app->make('auth')),
            'drivers' => $session === null ? null : (fn () => $this->drivers)->call($session),
            'store' => $this->app->resolved('session.store') ? $this->app->make('session.store') : null,
        ];
    }

    /** @param  array{guards: array<string, mixed>, drivers: ?array<string, mixed>, store: ?object}  $previous */
    private function restore(array $previous): void
    {
        (function (array $guards) {
            $this->guards = $guards;
        })->call($this->app->make('auth'), $previous['guards']);

        if ($previous['drivers'] !== null) {
            (function (array $drivers) {
                $this->drivers = $drivers;
            })->call($this->app->make('session'), $previous['drivers']);
        }

        if ($previous['store'] !== null) {
            $this->app->instance('session.store', $previous['store']);
        }
    }

    /** Forget resolved guards (and their users) and start from a fresh session store. */
    private function reset(): void
    {
        $this->app->make('auth')->forgetGuards();

        if ($this->app->bound('session')) {
            $this->app->make('session')->forgetDrivers();
            $this->app->forgetInstance('session.store');
            Facade::clearResolvedInstance('session');
        }
    }

    /**
     * Contents of a `.css` file below public_path() for a URL path, or null (other file types and anything outside
     * the public directory are never read). The size is checked before reading.
     *
     * @throws ResponseTooLarge when the file is larger than $maxBytes
     */
    public function publicFile(string $path, ?int $maxBytes = null): ?string
    {
        $root = realpath($this->app->publicPath());
        $file = realpath($this->app->publicPath(ltrim(rawurldecode($path), '/')));

        if ($root === false || $file === false || ! is_file($file) || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR)
            || strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'css') {
            return null;
        }

        if ($maxBytes !== null && filesize($file) > $maxBytes) {
            throw new ResponseTooLarge($path, $maxBytes);
        }

        $contents = file_get_contents($file);

        return $contents === false ? null : $contents;
    }

    private function body(Response $response): string
    {
        if ($response instanceof StreamedResponse) {
            ob_start();

            try {
                $response->sendContent();
            } finally {
                $body = (string) ob_get_clean();
            }

            return $body;
        }

        if ($response instanceof BinaryFileResponse) {
            return (string) file_get_contents($response->getFile()->getPathname());
        }

        return (string) $response->getContent();
    }
}
