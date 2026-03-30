<?php

namespace ItsJustVita\LaravelBfsg\Services;

use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class AuthenticatedHttpClient
{
    protected array $cookies = [];
    protected ?string $bearerToken = null;
    protected array $headers = [];
    protected ?string $sessionCookie = null;

    /**
     * Authenticate using email and password (or custom fields)
     */
    public function authenticateWithCredentials(
        string $loginUrl,
        string $email,
        string $password,
        array $additionalFields = [],
        array $customFieldNames = []
    ): bool {
        // Support custom field names for different auth providers
        $emailField = $customFieldNames['email_field'] ?? 'email';
        $passwordField = $customFieldNames['password_field'] ?? 'password';

        $postData = array_merge([
            $emailField => $email,
            $passwordField => $password,
        ], $additionalFields);

        // Check if it's JSON or form-based authentication
        $isJsonAuth = $customFieldNames['json_auth'] ?? false;

        if ($isJsonAuth) {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])->timeout(30)->post($loginUrl, $postData);
        } else {
            $response = Http::asForm()->withHeaders([
                'Accept' => 'text/html,application/xhtml+xml',
            ])->timeout(30)->post($loginUrl, $postData);
        }

        if ($response->failed() && $response->status() >= 500) {
            throw new Exception('Failed to authenticate: Could not connect to login URL');
        }

        // Extract cookies from response headers
        $this->extractCookiesFromResponse($response);

        // For JSON responses, check for token
        if ($isJsonAuth) {
            $responseData = $response->json();

            // Check for JWT/Bearer token in response
            if (isset($responseData['token'])) {
                $this->bearerToken = $responseData['token'];
                $this->headers['Authorization'] = "Bearer {$responseData['token']}";
                return true;
            }

            // Check for access_token (OAuth style)
            if (isset($responseData['access_token'])) {
                $this->bearerToken = $responseData['access_token'];
                $this->headers['Authorization'] = "Bearer {$responseData['access_token']}";
                return true;
            }
        }

        // Check if we got a session cookie
        return $this->hasSessionCookie();
    }

    /**
     * Authenticate using a bearer token
     */
    public function authenticateWithBearerToken(string $token, string $type = 'Bearer'): void
    {
        $this->bearerToken = $token;
        $this->headers['Authorization'] = "{$type} {$token}";
    }

    /**
     * Authenticate using JWT (JSON Web Token)
     */
    public function authenticateWithJWT(string $token): void
    {
        $this->authenticateWithBearerToken($token, 'JWT');
    }

    /**
     * Authenticate using API Key
     */
    public function authenticateWithApiKey(string $apiKey, string $headerName = 'X-API-Key'): void
    {
        $this->headers[$headerName] = $apiKey;
    }

    /**
     * Authenticate using OAuth2
     */
    public function authenticateWithOAuth2(string $accessToken): void
    {
        $this->bearerToken = $accessToken;
        $this->headers['Authorization'] = "Bearer {$accessToken}";
    }

    /**
     * Authenticate using custom headers
     */
    public function authenticateWithCustomHeaders(array $headers): void
    {
        foreach ($headers as $header => $value) {
            $this->headers[$header] = $value;
        }
    }

    /**
     * Authenticate using an existing session cookie
     */
    public function authenticateWithSessionCookie(string $cookieName, string $cookieValue): void
    {
        $this->sessionCookie = "{$cookieName}={$cookieValue}";
        $this->cookies[$cookieName] = $cookieValue;
    }

    /**
     * Authenticate using Laravel Sanctum
     */
    public function authenticateWithSanctum(string $apiUrl, string $email, string $password): ?string
    {
        // First get CSRF token
        $csrfUrl = rtrim($apiUrl, '/') . '/sanctum/csrf-cookie';

        $csrfResponse = Http::withHeaders([
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->timeout(30)->get($csrfUrl);

        $this->extractCookiesFromResponse($csrfResponse);

        // Get XSRF token from cookies
        $xsrfToken = $this->cookies['XSRF-TOKEN'] ?? null;

        if (!$xsrfToken) {
            throw new Exception('Failed to get CSRF token from Sanctum');
        }

        // Now authenticate
        $loginData = [
            'email' => $email,
            'password' => $password,
        ];

        $cookieHeader = $this->getCookieString();

        $loginUrl = rtrim($apiUrl, '/') . '/login';

        $loginResponse = Http::withHeaders([
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-XSRF-TOKEN' => urldecode($xsrfToken),
            'Cookie' => $cookieHeader,
        ])->timeout(30)->post($loginUrl, $loginData);

        if ($loginResponse->failed()) {
            throw new Exception('Failed to authenticate with Sanctum');
        }

        $this->extractCookiesFromResponse($loginResponse);

        // For API token based Sanctum
        $responseData = $loginResponse->json();
        if (isset($responseData['token'])) {
            $this->bearerToken = $responseData['token'];
            return $responseData['token'];
        }

        return null;
    }

    /**
     * Fetch a URL with authentication
     */
    public function fetchAuthenticatedUrl(string $url, bool $verifySsl = true): string
    {
        $request = Http::withHeaders($this->headers)->timeout(30);

        if (!$verifySsl) {
            $request = $request->withoutVerifying();
        }

        if ($this->hasCookies()) {
            $request = $request->withHeaders([
                'Cookie' => $this->getCookieString(),
            ]);
        }

        $response = $request->get($url);

        if ($response->failed()) {
            throw new Exception("Failed to fetch authenticated URL: {$url}");
        }

        return $response->body();
    }

    /**
     * Extract cookies from HTTP response
     */
    protected function extractCookiesFromResponse(Response $response): void
    {
        $setCookieHeaders = $response->header('Set-Cookie');

        if (empty($setCookieHeaders)) {
            // Try getting all headers - some responses have multiple Set-Cookie headers
            $headers = $response->headers();
            $setCookieHeaders = $headers['Set-Cookie'] ?? [];
        }

        if (!is_array($setCookieHeaders)) {
            $setCookieHeaders = [$setCookieHeaders];
        }

        foreach ($setCookieHeaders as $cookieString) {
            if (empty($cookieString)) {
                continue;
            }

            $parts = explode(';', $cookieString);
            $cookie = trim($parts[0]);

            if (strpos($cookie, '=') !== false) {
                [$name, $value] = explode('=', $cookie, 2);
                $name = trim($name);
                $value = trim($value);
                $this->cookies[$name] = $value;

                // Check for Laravel session cookie
                if (stripos($name, 'laravel_session') !== false || $name === 'PHPSESSID') {
                    $this->sessionCookie = $cookie;
                }
            }
        }
    }

    /**
     * Check if we have a session cookie
     */
    protected function hasSessionCookie(): bool
    {
        return $this->sessionCookie !== null ||
               isset($this->cookies['laravel_session']) ||
               isset($this->cookies['PHPSESSID']);
    }

    /**
     * Check if we have any cookies
     */
    protected function hasCookies(): bool
    {
        return !empty($this->cookies);
    }

    /**
     * Get cookie string for headers
     */
    protected function getCookieString(): string
    {
        $cookieParts = [];
        foreach ($this->cookies as $name => $value) {
            $cookieParts[] = "{$name}={$value}";
        }
        return implode('; ', $cookieParts);
    }

    /**
     * Clear all authentication
     */
    public function clearAuthentication(): void
    {
        $this->cookies = [];
        $this->bearerToken = null;
        $this->headers = [];
        $this->sessionCookie = null;
    }
}
