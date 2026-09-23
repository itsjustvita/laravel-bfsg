<?php

namespace ItsJustVita\LaravelBfsg\Http;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Requests pages of the running application through its HTTP kernel instead of a web server: no `php -S`,
 * no TLS, no network. The request carries the SKIP_ATTRIBUTE so the package's own middleware ignores it, and
 * the kernel is not terminated (terminable middleware does not run for a check).
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

        if ($user !== null) {
            $auth = $this->app->make('auth');
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
    }

    /** Contents of a file below public_path() for a URL path, or null (never outside the public directory). */
    public function publicFile(string $path): ?string
    {
        $root = realpath($this->app->publicPath());
        $file = realpath($this->app->publicPath(ltrim(rawurldecode($path), '/')));

        if ($root === false || $file === false || ! is_file($file) || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR)) {
            return null;
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
