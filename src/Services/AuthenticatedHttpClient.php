<?php

namespace ItsJustVita\LaravelBfsg\Services;

use Exception;

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
            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Requested-With: XMLHttpRequest',
            ];
            $content = json_encode($postData);
        } else {
            $headers = [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: text/html,application/xhtml+xml',
            ];
            $content = http_build_query($postData);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headers,
                'content' => $content,
                'follow_location' => true,
                'timeout' => 30,
                'ignore_errors' => true, // Get response even on HTTP errors
            ],
        ]);

        $response = @file_get_contents($loginUrl, false, $context);

        if ($response === false) {
            throw new Exception('Failed to authenticate: Could not connect to login URL');
        }

        // Extract cookies from response headers
        $this->extractCookiesFromHeaders($http_response_header);

        // For JSON responses, check for token
        if ($isJsonAuth) {
            $responseData = json_decode($response, true);

            // Check for JWT/Bearer token in response
            if (isset($responseData['token'])) {
                $this->bearerToken = $responseData['token'];
                $this->headers[] = "Authorization: Bearer {$responseData['token']}";
                return true;
            }

            // Check for access_token (OAuth style)
            if (isset($responseData['access_token'])) {
                $this->bearerToken = $responseData['access_token'];
                $this->headers[] = "Authorization: Bearer {$responseData['access_token']}";
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
        $this->headers[] = "Authorization: {$type} {$token}";
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
        $this->headers[] = "{$headerName}: {$apiKey}";
    }

    /**
     * Authenticate using OAuth2
     */
    public function authenticateWithOAuth2(string $accessToken): void
    {
        $this->bearerToken = $accessToken;
        $this->headers[] = "Authorization: Bearer {$accessToken}";
    }

    /**
     * Authenticate using custom headers
     */
    public function authenticateWithCustomHeaders(array $headers): void
    {
        foreach ($headers as $header => $value) {
            $this->headers[] = "{$header}: {$value}";
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
        $csrfContext = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'Accept: application/json',
                    'X-Requested-With: XMLHttpRequest',
                ],
                'timeout' => 30,
            ],
        ]);

        $csrfUrl = rtrim($apiUrl, '/') . '/sanctum/csrf-cookie';
        @file_get_contents($csrfUrl, false, $csrfContext);
        $this->extractCookiesFromHeaders($http_response_header);

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

        $loginContext = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'X-Requested-With: XMLHttpRequest',
                    'X-XSRF-TOKEN: ' . urldecode($xsrfToken),
                    'Cookie: ' . $this->getCookieString(),
                ],
                'content' => json_encode($loginData),
                'timeout' => 30,
            ],
        ]);

        $loginUrl = rtrim($apiUrl, '/') . '/login';
        $response = @file_get_contents($loginUrl, false, $loginContext);

        if ($response === false) {
            throw new Exception('Failed to authenticate with Sanctum');
        }

        $this->extractCookiesFromHeaders($http_response_header);

        // For API token based Sanctum
        $responseData = json_decode($response, true);
        if (isset($responseData['token'])) {
            $this->bearerToken = $responseData['token'];
            return $responseData['token'];
        }

        return null;
    }

    /**
     * Fetch a URL with authentication
     */
    public function fetchAuthenticatedUrl(string $url): string
    {
        $headers = $this->headers;

        if ($this->hasCookies()) {
            $headers[] = 'Cookie: ' . $this->getCookieString();
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => $headers,
                'timeout' => 30,
                'follow_location' => true,
            ],
        ]);

        $content = @file_get_contents($url, false, $context);

        if ($content === false) {
            throw new Exception("Failed to fetch authenticated URL: {$url}");
        }

        return $content;
    }

    /**
     * Extract cookies from HTTP response headers
     */
    protected function extractCookiesFromHeaders(?array $headers): void
    {
        if (!$headers) {
            return;
        }

        foreach ($headers as $header) {
            if (stripos($header, 'Set-Cookie:') === 0) {
                $cookieString = substr($header, 12);
                $parts = explode(';', $cookieString);
                $cookie = trim($parts[0]);

                if (strpos($cookie, '=') !== false) {
                    list($name, $value) = explode('=', $cookie, 2);
                    $this->cookies[trim($name)] = trim($value);

                    // Check for Laravel session cookie
                    if (stripos($name, 'laravel_session') !== false || $name === 'PHPSESSID') {
                        $this->sessionCookie = $cookie;
                    }
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