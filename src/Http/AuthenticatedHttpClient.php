<?php

namespace ItsJustVita\LaravelBfsg\Http;

use Closure;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * HTTP client for bfsg:check, MCP and UrlFetcher: one cookie jar, one request factory (timeout, TLS verification,
 * user agent, no automatic redirects) and the login flows. Cookies are stored and sent by the client itself, so
 * they behave the same with a real transport and with Http::fake().
 *
 * Credentials (Authorization, API keys, custom headers, tokens from a login, a given session cookie) are bound to
 * one origin (scheme, host, port) and only sent to that origin: redirects are followed by UrlFetcher, not Guzzle,
 * so Guzzle's cross-origin and https-to-http stripping does not apply and this client does it instead.
 */
class AuthenticatedHttpClient
{
    private CookieJar $jar;

    /** @var array<string, string> credential headers (Authorization, API keys, custom headers) */
    private array $headers = [];

    /** @var array<string, ?string> header name => origin it is bound to (null = the origin of the next request) */
    private array $headerOrigins = [];

    /** @var array<string, array{0: string, 1: string}> cookie name => [value, origin] of given session cookies */
    private array $credentialCookies = [];

    private int $timeout;

    private bool $verifySsl;

    private string $userAgent;

    public function __construct(?int $timeout = null, ?bool $verifySsl = null, ?string $userAgent = null)
    {
        $this->timeout = $timeout ?? (int) config('bfsg.fetch.timeout', 30);
        $this->verifySsl = $verifySsl ?? self::verifySslSetting(config('bfsg.fetch.verify_ssl', true));
        $this->userAgent = $userAgent ?? (string) config('bfsg.fetch.user_agent', 'laravel-bfsg');
        $this->jar = new CookieJar;
    }

    /** `bfsg.fetch.verify_ssl`: only an explicit false value (false, "false", "0", "off", "no") turns verification off. */
    private static function verifySslSetting(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
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

    /** The factory every request is built from; credential headers and cookies are added per request for their origin. */
    public function request(): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->withUserAgent($this->userAgent)
            ->withOptions(['verify' => $this->verifySsl, 'allow_redirects' => false]);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  int|null  $maxBytes  abort (ResponseTooLarge) once the body exceeds this many bytes: by Content-Length and
     *                              download progress on a real transport, and by the received body in any case
     *
     * @throws ResponseTooLarge
     */
    public function get(string $url, array $headers = [], ?int $maxBytes = null): Response
    {
        return $this->dispatch('GET', $url, $headers, fn (PendingRequest $request) => ($maxBytes === null ? $request : $request->withOptions([
            'on_headers' => function (ResponseInterface $response) use ($url, $maxBytes) {
                if ($response->getHeaderLine('Content-Length') !== '' && (int) $response->getHeaderLine('Content-Length') > $maxBytes) {
                    throw new ResponseTooLarge($url, $maxBytes);
                }
            },
            'progress' => function ($downloadTotal, $downloaded) use ($url, $maxBytes) {
                if ($downloaded > $maxBytes) {
                    throw new ResponseTooLarge($url, $maxBytes);
                }
            },
        ]))->get($url), $maxBytes);
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

        $before = $this->sessionCookies($loginUrl);
        $response = $this->attempt($loginUrl, fn () => $this->postForm($loginUrl, $data, $this->csrfHeaders($loginUrl, ['Accept' => 'text/html,application/xhtml+xml', 'Referer' => $loginUrl])));

        $this->assertLoggedIn($response, $loginUrl, $before, $fieldNames);
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
        $before = $this->sessionCookies($loginUrl);
        $response = $this->attempt($loginUrl, fn () => $this->postJson($loginUrl, $data, $this->csrfHeaders($loginUrl, ['X-Requested-With' => 'XMLHttpRequest'])));

        $this->acceptTokenOrSession($response, $loginUrl, $before);
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
        $before = $this->sessionCookies($loginUrl);
        $response = $this->attempt($loginUrl, fn () => $this->postJson($loginUrl, $data, $headers));

        $this->acceptTokenOrSession($response, $loginUrl, $before);
    }

    /** @param  string|null  $origin  the only origin the token is sent to; null = the origin of the next request */
    public function withBearer(string $token, ?string $origin = null): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token], $origin);
    }

    /** JWTs are bearer tokens (RFC 6750); tymon/jwt-auth and Passport read `Authorization: Bearer`. */
    public function withJwt(string $token, ?string $origin = null): static
    {
        return $this->withBearer($token, $origin);
    }

    public function withApiKey(string $key, string $header = 'X-API-Key', ?string $origin = null): static
    {
        return $this->withHeaders([$header => $key], $origin);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  string|null  $origin  the only origin (any URL of it) the headers are sent to; null = the origin of the next request
     */
    public function withHeaders(array $headers, ?string $origin = null): static
    {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
            $this->headerOrigins[$name] = $origin === null ? null : self::origin($origin);
        }

        return $this;
    }

    /** Bind every credential header that has no origin yet to the origin of $url (UrlFetcher: the requested URL). */
    public function bindUnboundHeadersTo(string $url): static
    {
        $origin = self::origin($url);

        foreach ($this->headerOrigins as $name => $bound) {
            $this->headerOrigins[$name] = $bound ?? $origin;
        }

        return $this;
    }

    /** An existing session cookie, sent only to the origin of $url (host-only, same scheme and port). */
    public function withSessionCookie(string $name, string $value, string $url): static
    {
        $this->credentialCookies[$name] = [$value, self::origin($url)];

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
        return $this->sessionCookies($url) !== [];
    }

    /** @return array<string, string> session cookies (name => value) for the host of $url */
    private function sessionCookies(string $url): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $appCookie = $host !== '' && $host === parse_url((string) config('app.url'), PHP_URL_HOST) ? (string) config('session.cookie') : null;
        $isSession = fn (string $name): bool => preg_match('/_session$/i', $name) === 1 || $name === 'PHPSESSID' || $name === $appCookie;
        $found = [];

        foreach ($this->jar as $cookie) {
            $name = (string) $cookie->getName();

            if ($cookie->matchesDomain($host) && $isSession($name)) {
                $found[$name] = (string) $cookie->getValue();
            }
        }

        foreach ($this->credentialCookies as $name => [$value, $origin]) {
            if ($origin === self::origin($url) && $isSession($name)) {
                $found[$name] ??= $value;
            }
        }

        return $found;
    }

    /** scheme://host:port with the default port made explicit, lowercased */
    public static function origin(string $url): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $port = parse_url($url, PHP_URL_PORT) ?? ($scheme === 'https' ? 443 : 80);

        return $scheme.'://'.strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.')).':'.$port;
    }

    /** @return array{email: ?string, password: ?string, token: ?string} BFSG_AUTH_EMAIL, BFSG_AUTH_PASSWORD, BFSG_AUTH_TOKEN */
    public static function credentialsFromEnv(): array
    {
        $read = fn (string $name): ?string => is_string($value = Env::get($name)) && $value !== '' ? $value : null;

        return ['email' => $read('BFSG_AUTH_EMAIL'), 'password' => $read('BFSG_AUTH_PASSWORD'), 'token' => $read('BFSG_AUTH_TOKEN')];
    }

    /** @param  array<string, string>  $headers */
    private function dispatch(string $method, string $url, array $headers, Closure $send, ?int $maxBytes = null): Response
    {
        $psr = $this->jar->withCookieHeader(new PsrRequest($method, $url));
        $request = $this->request()->withHeaders($this->credentialHeaders($url))->withHeaders($headers);
        $cookie = $this->cookieHeader($psr->getHeaderLine('Cookie'), $url);

        if ($cookie !== '') {
            $request->withHeaders(['Cookie' => $cookie]);
        }

        try {
            $response = $send($request);
        } catch (Throwable $e) {
            for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof ResponseTooLarge) {
                    throw $cause;
                }
            }

            throw $e;
        }

        $this->jar->extractCookies($psr, $response->toPsrResponse());

        if ($maxBytes !== null && strlen($response->body()) > $maxBytes) {
            throw new ResponseTooLarge($url, $maxBytes);
        }

        return $response;
    }

    /** @return array<string, string> the credential headers bound to the origin of $url (unbound ones are bound to it now) */
    private function credentialHeaders(string $url): array
    {
        $origin = self::origin($url);
        $headers = [];

        foreach ($this->headers as $name => $value) {
            $this->headerOrigins[$name] ??= $origin;

            if ($this->headerOrigins[$name] === $origin) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /** The jar's Cookie header plus the given session cookies of this origin (a jar cookie of the same name wins). */
    private function cookieHeader(string $jarHeader, string $url): string
    {
        $pairs = $jarHeader === '' ? [] : explode('; ', $jarHeader);
        $names = array_map(fn (string $pair) => explode('=', $pair, 2)[0], $pairs);

        foreach ($this->credentialCookies as $name => [$value, $origin]) {
            if ($origin === self::origin($url) && ! in_array($name, $names, true)) {
                $pairs[] = $name.'='.$value;
            }
        }

        return implode('; ', $pairs);
    }

    private function attempt(string $url, Closure $send): Response
    {
        try {
            return $send();
        } catch (ConnectionException $e) {
            throw AuthenticationFailed::connection($url, $e->getMessage());
        }
    }

    /**
     * A redirect away from the login page (not to a two-factor challenge) is a login. A 2xx answer that still shows a
     * login form (a password field, the configured password field, or a form posting to the login URL) is a failed
     * login whatever the cookies say; any other 2xx is one when a session cookie is present. The "cookie changed"
     * test alone is not enough: Laravel's EncryptCookies re-encrypts the session cookie on every response, so its
     * value changes even when the session ID does not (the comparison only means something for unencrypted cookies).
     *
     * @param  array<string, string>  $before  session cookies before the POST
     * @param  array{username?: string, password?: string}  $fieldNames
     */
    private function assertLoggedIn(Response $response, string $loginUrl, array $before, array $fieldNames = []): void
    {
        $this->rejectFailures($response, $loginUrl);

        if ($response->redirect()) {
            $this->rejectRedirectBack($response, $loginUrl);

            return;
        }

        if ($response->successful()) {
            if ($this->showsLoginForm($response->body(), $loginUrl, $fieldNames['password'] ?? 'password')) {
                throw AuthenticationFailed::credentials($loginUrl, $response->status());
            }

            if ($this->sessionChanged($loginUrl, $before) || $this->hasSessionCookie($loginUrl)) {
                return;
            }
        }

        throw AuthenticationFailed::noSession($loginUrl, $response->status());
    }

    /** A password input, an input named like the password field, or a form whose action is the login URL. */
    private function showsLoginForm(string $html, string $loginUrl, string $passwordField): bool
    {
        if (preg_match('/<input\b[^>]*\btype\s*=\s*["\']?password\b/i', $html) === 1
            || preg_match('/<input\b[^>]*\bname\s*=\s*["\']?'.preg_quote($passwordField, '/').'(?=["\'\s>\/])/i', $html) === 1) {
            return true;
        }

        preg_match_all('/<form\b[^>]*\baction\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $html, $forms, PREG_SET_ORDER);

        foreach ($forms as $form) {
            $action = html_entity_decode(($form[1] ?? '').($form[2] ?? '').($form[3] ?? ''), ENT_QUOTES);

            try {
                $target = (string) UriResolver::resolve(Utils::uriFor($loginUrl), Utils::uriFor($action));
            } catch (Throwable) {
                continue;
            }

            if ($action !== '' && $this->samePath($target, $loginUrl)) {
                return true;
            }
        }

        return false;
    }

    /**
     * JSON and Sanctum logins: a token in the answer, or a 2xx/redirect with a regenerated session cookie.
     *
     * @param  array<string, string>  $before  session cookies before the POST
     */
    private function acceptTokenOrSession(Response $response, string $loginUrl, array $before): void
    {
        $this->rejectFailures($response, $loginUrl);

        $json = $response->json();

        if (is_array($json) && ($json['two_factor'] ?? false) === true) {
            throw AuthenticationFailed::twoFactor($loginUrl);
        }

        if (is_array($json) && (($json['ok'] ?? null) === false || ($json['success'] ?? null) === false || ! empty($json['errors']))) {
            throw AuthenticationFailed::credentials($loginUrl, $response->status());
        }

        if ($response->redirect()) {
            $this->rejectRedirectBack($response, $loginUrl);
        }

        $token = is_array($json) ? ($json['token'] ?? $json['access_token'] ?? ($json['data']['token'] ?? null)) : null;

        if (is_string($token) && $token !== '') {
            $this->withBearer($token, $loginUrl);

            return;
        }

        if (($response->successful() || $response->redirect()) && $this->sessionChanged($loginUrl, $before)) {
            return;
        }

        throw AuthenticationFailed::noSession($loginUrl, $response->status());
    }

    /** A redirect back to the login page means invalid credentials, one to a two-factor challenge an unfinished login. */
    private function rejectRedirectBack(Response $response, string $loginUrl): void
    {
        if ($response->header('Location') === '') {
            return;
        }

        $location = (string) UriResolver::resolve(Utils::uriFor($loginUrl), Utils::uriFor($response->header('Location')));

        if ($this->samePath($location, $loginUrl)) {
            throw AuthenticationFailed::credentials($loginUrl, null);
        }

        if (preg_match('~two[-_]?factor~i', (string) parse_url($location, PHP_URL_PATH)) === 1) {
            throw AuthenticationFailed::twoFactor($loginUrl);
        }
    }

    /** @param  array<string, string>  $before */
    private function sessionChanged(string $loginUrl, array $before): bool
    {
        foreach ($this->sessionCookies($loginUrl) as $name => $value) {
            if (($before[$name] ?? null) !== $value) {
                return true;
            }
        }

        return false;
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
