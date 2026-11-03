<?php

namespace ItsJustVita\LaravelBfsg\Http;

use Closure;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use ReflectionFunction;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Requests pages of the running application through its HTTP kernel instead of a web server: no `php -S`,
 * no TLS, no network. The request carries the SKIP_ATTRIBUTE so the package's own middleware ignores it, and
 * the kernel is not terminated (terminable middleware does not run for a check). When `app.url` has a path
 * (`http://localhost/app`), URLs below it are dispatched with that path as the base path, as a web server would.
 *
 * Every fetch is isolated: it runs with no resolved guards, a fresh session store, no scoped instances (the Context
 * repository among them) and its output captured (OutputCapture: nothing reaches stdout, stray bytes become a
 * warning). Afterwards the caller's state is put back exactly: its resolved guards (with their users), its session
 * store, the session manager's drivers, its default guard, its scoped instances and Context, its request instance,
 * its locale and its output buffers and dump handler. No user, session data, request, per-request state or locale
 * leaks from one fetch into the next, and the calling process (a long-lived MCP server, a multi-page run, or a web
 * request) keeps its own. Global changes the application makes (config(), static properties) are not isolated.
 */
class InProcessFetcher
{
    public const SKIP_ATTRIBUTE = 'bfsg.in_process';

    public function __construct(private Application $app) {}

    /** @return array{status: int, location: ?string, contentType: string, body: string, warnings: list<string>} */
    public function get(string $url, ?Authenticatable $user = null, ?string $guard = null): array
    {
        $request = $this->request($url);
        $request->attributes->set(self::SKIP_ATTRIBUTE, true);

        $auth = $this->app->make('auth');
        $previousRequest = $this->app->bound('request') ? $this->app->make('request') : null;
        $previousGuard = $auth->getDefaultDriver();
        $previousLocale = $this->app->getLocale();
        $previous = $this->snapshot();
        $rebindings = $this->requestRebindings();
        $this->reset();
        $scoped = $this->forgetScopedInstances();
        $capture = new OutputCapture;

        try {
            if ($user !== null) {
                $auth->guard($guard)->setUser($user);

                if ($guard !== null) {
                    $auth->shouldUse($guard);
                }
            }

            $response = $this->app->make(Kernel::class)->handle($request);

            $result = [
                'status' => $response->getStatusCode(),
                'location' => $response->headers->get('Location'),
                'contentType' => (string) $response->headers->get('Content-Type', ''),
                'body' => $response instanceof StreamedResponse ? $capture->body($response->sendContent(...)) : $this->body($response),
            ];
        } finally {
            try {
                $stray = $capture->stop();
            } finally {
                $this->reset();
                $auth->shouldUse($previousGuard);
                $this->restore($previous);
                $this->restoreScopedInstances($scoped);
                $this->trimRequestRebindings($rebindings);

                if ($this->app->getLocale() !== $previousLocale) {
                    $this->app->setLocale($previousLocale);
                }

                if ($previousRequest !== null) {
                    $this->app->instance('request', $previousRequest);
                    Facade::clearResolvedInstance('request');
                }
            }
        }

        // In the caller's locale (restored above); only a byte count, no application output
        $result['warnings'] = $stray === 0 ? [] : [(string) __('bfsg::report.fetch_warnings.stray_output', ['bytes' => $stray])];

        return $result;
    }

    /**
     * The GET request for $url. Below the path of `app.url` that path is the base path (SCRIPT_NAME), so the
     * application sees `/contact` for `http://localhost/app/contact` and generates URLs below `/app`.
     */
    private function request(string $url): Request
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        $path = (string) parse_url($url, PHP_URL_PATH);
        $base = $this->basePath();
        $server = [
            'HTTP_HOST' => $host.($port === null ? '' : ':'.$port),
            'HTTPS' => $scheme === 'https' ? 'on' : 'off',
            'HTTP_ACCEPT' => 'text/html,application/xhtml+xml',
        ];

        if ($base !== '' && ($path === $base || str_starts_with($path, $base.'/'))) {
            $server['SCRIPT_NAME'] = $base.'/index.php';
            $server['SCRIPT_FILENAME'] = $this->app->publicPath('index.php');
            // `/app` is the application's home page, as `/app/` (Symfony derives no base path from `/app` alone)
            $url = $path === $base ? (string) (new Uri($url))->withPath($base.'/') : $url;
        }

        return Request::create($url, 'GET', server: $server);
    }

    /** The path of `app.url` without a trailing slash: '' for an application at the root of its host. */
    private function basePath(): string
    {
        return rtrim((string) parse_url((string) $this->app->make('config')->get('app.url'), PHP_URL_PATH), '/');
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

    /**
     * Number of `request` rebinding callbacks the container holds. Every guard the fetch creates registers one
     * (AuthManager: `$app->refresh('request', $guard, 'setRequest')`), so a long-running process such as the MCP
     * server would otherwise collect one per fetch, each keeping a discarded guard (and its user) alive.
     */
    private function requestRebindings(): int
    {
        return (fn () => count($this->reboundCallbacks['request'] ?? []))->call($this->app);
    }

    /**
     * Drop the `request` rebinding callbacks the fetch registered for its own guards (discarded by restore()), keeping
     * the caller's first $count and any other callback registered meanwhile (e.g. by a singleton resolved for the
     * first time during the fetch, which must keep following the current request).
     */
    private function trimRequestRebindings(int $count): void
    {
        (function (int $count) {
            $callbacks = $this->reboundCallbacks['request'] ?? [];
            $kept = array_slice($callbacks, 0, $count);

            foreach (array_slice($callbacks, $count) as $callback) {
                $target = $callback instanceof Closure ? ((new ReflectionFunction($callback))->getClosureUsedVariables()['target'] ?? null) : null;

                if (! $target instanceof Guard) {
                    $kept[] = $callback;
                }
            }

            if ($callbacks !== []) {
                $this->reboundCallbacks['request'] = $kept;
            }
        })->call($this->app, $count);
    }

    /**
     * Forget the container's scoped instances (the Context repository among them) and the facade roots resolved from
     * them, as a web server's next request starts without them. Returns them (abstract => instance) for restoring.
     *
     * @return array<string, mixed>
     */
    private function forgetScopedInstances(): array
    {
        $instances = fn () => $this->instances;
        $before = $instances->call($this->app);
        $this->app->forgetScopedInstances();
        $forgotten = array_diff_key($before, $instances->call($this->app));

        foreach (array_keys($forgotten) as $abstract) {
            Facade::clearResolvedInstance($abstract);
        }

        return $forgotten;
    }

    /**
     * Forget the scoped instances the fetch resolved and put the caller's back as they were (no rebinding callbacks).
     *
     * @param  array<string, mixed>  $instances
     */
    private function restoreScopedInstances(array $instances): void
    {
        $this->forgetScopedInstances();

        (function (array $instances) {
            $this->instances = array_replace($this->instances, $instances);
        })->call($this->app, $instances);

        foreach (array_keys($instances) as $abstract) {
            Facade::clearResolvedInstance($abstract);
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
     * Contents of a `.css` file below public_path() for a URL path (below the base path of `app.url`, if it has one),
     * or null (other file types and anything outside the public directory are never read). The size is checked before
     * reading.
     *
     * @throws ResponseTooLarge when the file is larger than $maxBytes
     */
    public function publicFile(string $path, ?int $maxBytes = null): ?string
    {
        $base = $this->basePath();

        // Below the base path of a sub-path application, as a web server maps /app/css/app.css to public/css/app.css
        if ($base !== '' && str_starts_with($path, $base.'/')) {
            $path = substr($path, strlen($base));
        }

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

    /** The body of a response that is not streamed (OutputCapture reads streamed ones). */
    private function body(Response $response): string
    {
        if ($response instanceof BinaryFileResponse) {
            return (string) file_get_contents($response->getFile()->getPathname());
        }

        return (string) $response->getContent();
    }
}
