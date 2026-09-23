<?php

namespace ItsJustVita\LaravelBfsg\Http;

use Closure;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for bfsg:check, MCP and UrlFetcher: one cookie jar, one request factory (timeout, TLS verification,
 * user agent, no automatic redirects) and the login flows. Cookies are stored and sent by the client itself, so
 * they behave the same with a real transport and with Http::fake().
 */
class AuthenticatedHttpClient
{
    private CookieJar $jar;

    /** @var array<string, string> headers sent with every request (Authorization, API keys, custom headers) */
    private array $headers = [];

    private int $timeout;

    private bool $verifySsl;

    private string $userAgent;

    public function __construct(?int $timeout = null, ?bool $verifySsl = null, ?string $userAgent = null)
    {
        $this->timeout = $timeout ?? (int) config('bfsg.fetch.timeout', 30);
        $this->verifySsl = $verifySsl ?? filter_var(config('bfsg.fetch.verify_ssl', true), FILTER_VALIDATE_BOOL);
        $this->userAgent = $userAgent ?? (string) config('bfsg.fetch.user_agent', 'laravel-bfsg');
        $this->jar = new CookieJar;
    }

    public function withVerifySsl(bool $verifySsl): static
    {
        $this->verifySsl = $verifySsl;

        return $this;
    }

    public function verifiesSsl(): bool
    {
        return $this->verifySsl;
    }

    public function cookies(): CookieJar
    {
        return $this->jar;
    }

    /** The factory every request is built from. */
    public function request(): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->withUserAgent($this->userAgent)
            ->withOptions(['verify' => $this->verifySsl, 'allow_redirects' => false])
            ->withHeaders($this->headers);
    }

    /** @param  array<string, string>  $headers */
    public function get(string $url, array $headers = []): Response
    {
        return $this->dispatch('GET', $url, $headers, fn (PendingRequest $request) => $request->get($url));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function postForm(string $url, array $data, array $headers = []): Response
    {
        return $this->dispatch('POST', $url, $headers, fn (PendingRequest $request) => $request->asForm()->post($url, $data));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function postJson(string $url, array $data, array $headers = []): Response
    {
        return $this->dispatch('POST', $url, $headers, fn (PendingRequest $request) => $request->asJson()->acceptJson()->post($url, $data));
    }

    /**
     * Form login: GET the login page (session cookie, `_token` field, XSRF-TOKEN cookie), then POST the credentials
     * without following redirects. Success is a redirect away from the login page, or a 2xx with a session cookie.
     *
     * @param  array<string, mixed>  $fields  extra form fields
     * @param  array{username?: string, password?: string}  $fieldNames
     *
     * @throws AuthenticationFailed
     */
    public function loginWithForm(string $loginUrl, string $user, string $password, array $fields = [], array $fieldNames = []): void
    {
        $page = $this->attempt($loginUrl, fn () => $this->get($loginUrl, ['Accept' => 'text/html,application/xhtml+xml']));

        if ($page->clientError() || $page->serverError()) {
            throw AuthenticationFailed::loginPage($loginUrl, $page->status());
        }

        $data = array_merge($fields, [($fieldNames['username'] ?? 'email') => $user, ($fieldNames['password'] ?? 'password') => $password]);
        $token = $this->csrfField($page->body());

        if ($token !== null) {
            $data['_token'] = $token;
        }

        $response = $this->attempt($loginUrl, fn () => $this->postForm($loginUrl, $data, $this->csrfHeaders($loginUrl, ['Accept' => 'text/html,application/xhtml+xml', 'Referer' => $loginUrl])));

        $this->assertLoggedIn($response, $loginUrl);
    }

    /**
     * JSON login (API guards, JWT): a token in the response (`token`, `access_token`, `data.token`) becomes the
     * bearer token; otherwise a session cookie must have been set.
     *
     * @param  array<string, mixed>  $fields
     * @param  array{username?: string, password?: string}  $fieldNames
     *
     * @throws AuthenticationFailed
     */
    public function loginWithJson(string $loginUrl, string $user, string $password, array $fields = [], array $fieldNames = []): void
    {
        $data = array_merge($fields, [($fieldNames['username'] ?? 'email') => $user, ($fieldNames['password'] ?? 'password') => $password]);
        $response = $this->attempt($loginUrl, fn () => $this->postJson($loginUrl, $data, $this->csrfHeaders($loginUrl, ['X-Requested-With' => 'XMLHttpRequest'])));

        $this->acceptTokenOrSession($response, $loginUrl);
    }

    /**
     * Sanctum SPA login: GET {origin}/sanctum/csrf-cookie, then POST {origin}{loginPath} with the XSRF token,
     * Origin and Referer (Sanctum's stateful check), sharing one cookie jar.
     *
     * @param  array{username?: string, password?: string}  $fieldNames
     *
     * @throws AuthenticationFailed
     */
    public function loginWithSanctum(string $origin, string $user, string $password, string $loginPath = '/login', array $fieldNames = []): void
    {
        $origin = rtrim($origin, '/');
        $csrfUrl = $origin.'/sanctum/csrf-cookie';
        $loginUrl = $origin.'/'.ltrim($loginPath, '/');

        $this->attempt($csrfUrl, fn () => $this->get($csrfUrl, ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']));

        if ($this->cookieValue($csrfUrl, 'XSRF-TOKEN') === null) {
            throw AuthenticationFailed::csrf($csrfUrl, null);
        }

        $data = [($fieldNames['username'] ?? 'email') => $user, ($fieldNames['password'] ?? 'password') => $password];
        $headers = $this->csrfHeaders($loginUrl, ['X-Requested-With' => 'XMLHttpRequest', 'Origin' => $origin, 'Referer' => $origin.'/']);
        $response = $this->attempt($loginUrl, fn () => $this->postJson($loginUrl, $data, $headers));

        $this->acceptTokenOrSession($response, $loginUrl);
    }

    public function withBearer(string $token): static
    {
        $this->headers['Authorization'] = 'Bearer '.$token;

        return $this;
    }

    /** JWTs are bearer tokens (RFC 6750); tymon/jwt-auth and Passport read `Authorization: Bearer`. */
    public function withJwt(string $token): static
    {
        return $this->withBearer($token);
    }

    public function withApiKey(string $key, string $header = 'X-API-Key'): static
    {
        $this->headers[$header] = $key;

        return $this;
    }

    /** @param  array<string, string>  $headers */
    public function withHeaders(array $headers): static
    {
        $this->headers = array_merge($this->headers, $headers);

        return $this;
    }

    /** An existing session cookie, scoped to the host of $url. */
    public function withSessionCookie(string $name, string $value, string $url): static
    {
        $this->jar->setCookie(new SetCookie([
            'Name' => $name,
            'Value' => $value,
            'Domain' => (string) parse_url($url, PHP_URL_HOST),
            'Path' => '/',
        ]));

        return $this;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** Whether the jar holds a session cookie for the host of $url: `*_session`, `PHPSESSID`, or the app's session cookie. */
    public function hasSessionCookie(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $appCookie = $host !== '' && $host === parse_url((string) config('app.url'), PHP_URL_HOST) ? (string) config('session.cookie') : null;

        foreach ($this->jar as $cookie) {
            $name = (string) $cookie->getName();

            if ($cookie->matchesDomain($host) && (preg_match('/_session$/i', $name) === 1 || $name === 'PHPSESSID' || $name === $appCookie)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{email: ?string, password: ?string, token: ?string} BFSG_AUTH_EMAIL, BFSG_AUTH_PASSWORD, BFSG_AUTH_TOKEN */
    public static function credentialsFromEnv(): array
    {
        $read = fn (string $name): ?string => is_string($value = Env::get($name)) && $value !== '' ? $value : null;

        return ['email' => $read('BFSG_AUTH_EMAIL'), 'password' => $read('BFSG_AUTH_PASSWORD'), 'token' => $read('BFSG_AUTH_TOKEN')];
    }

    /** @param  array<string, string>  $headers */
    private function dispatch(string $method, string $url, array $headers, Closure $send): Response
    {
        $psr = $this->jar->withCookieHeader(new PsrRequest($method, $url));
        $request = $this->request()->withHeaders($headers);

        if ($psr->hasHeader('Cookie')) {
            $request->withHeaders(['Cookie' => $psr->getHeaderLine('Cookie')]);
        }

        $response = $send($request);
        $this->jar->extractCookies($psr, $response->toPsrResponse());

        return $response;
    }

    private function attempt(string $url, Closure $send): Response
    {
        try {
            return $send();
        } catch (ConnectionException $e) {
            throw AuthenticationFailed::connection($url, $e->getMessage());
        }
    }

    private function assertLoggedIn(Response $response, string $loginUrl): void
    {
        $this->rejectFailures($response, $loginUrl);

        if ($response->redirect()) {
            $location = (string) UriResolver::resolve(Utils::uriFor($loginUrl), Utils::uriFor($response->header('Location')));

            if ($response->header('Location') !== '' && $this->samePath($location, $loginUrl)) {
                throw AuthenticationFailed::credentials($loginUrl, null);
            }

            return;
        }

        if ($response->successful() && $this->hasSessionCookie($loginUrl)) {
            return;
        }

        throw AuthenticationFailed::noSession($loginUrl, $response->status());
    }

    private function acceptTokenOrSession(Response $response, string $loginUrl): void
    {
        $this->rejectFailures($response, $loginUrl);

        $json = $response->json();
        $token = is_array($json) ? ($json['token'] ?? $json['access_token'] ?? ($json['data']['token'] ?? null)) : null;

        if (is_string($token) && $token !== '') {
            $this->withBearer($token);

            return;
        }

        if (($response->successful() || $response->redirect()) && $this->hasSessionCookie($loginUrl)) {
            return;
        }

        throw AuthenticationFailed::noSession($loginUrl, $response->status());
    }

    private function rejectFailures(Response $response, string $loginUrl): void
    {
        match ($response->status()) {
            419 => throw AuthenticationFailed::csrf($loginUrl),
            422 => throw AuthenticationFailed::validation($loginUrl, $this->firstError($response)),
            401, 403 => throw AuthenticationFailed::credentials($loginUrl, $response->status()),
            default => null,
        };
    }

    private function firstError(Response $response): ?string
    {
        $errors = $response->json('errors');

        if (is_array($errors)) {
            $first = reset($errors);

            return is_array($first) ? (string) reset($first) : (is_string($first) ? $first : null);
        }

        $message = $response->json('message');

        return is_string($message) ? $message : null;
    }

    /** The `_token` of a Laravel form, or the csrf-token meta tag. */
    private function csrfField(string $html): ?string
    {
        foreach (['/<input\b[^>]*\bname=["\']_token["\'][^>]*>/i', '/<meta\b[^>]*\bname=["\']csrf-token["\'][^>]*>/i'] as $tag) {
            if (preg_match($tag, $html, $m) === 1 && preg_match('/\b(?:value|content)=["\']([^"\']*)["\']/i', $m[0], $value) === 1) {
                return html_entity_decode($value[1], ENT_QUOTES);
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string> $headers plus X-XSRF-TOKEN when the jar holds an XSRF-TOKEN cookie for $url
     */
    private function csrfHeaders(string $url, array $headers): array
    {
        $xsrf = $this->cookieValue($url, 'XSRF-TOKEN');

        return $xsrf === null ? $headers : $headers + ['X-XSRF-TOKEN' => urldecode($xsrf)];
    }

    private function cookieValue(string $url, string $name): ?string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        foreach ($this->jar as $cookie) {
            if ($cookie->getName() === $name && $cookie->matchesDomain($host)) {
                return $cookie->getValue();
            }
        }

        return null;
    }

    private function samePath(string $a, string $b): bool
    {
        $path = fn (string $url): string => rtrim((string) parse_url($url, PHP_URL_PATH), '/');

        return parse_url($a, PHP_URL_HOST) === parse_url($b, PHP_URL_HOST) && $path($a) === $path($b);
    }
}
