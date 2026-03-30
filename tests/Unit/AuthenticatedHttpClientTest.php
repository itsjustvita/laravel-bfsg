<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use Exception;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelBfsg\Services\AuthenticatedHttpClient;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class AuthenticatedHttpClientTest extends TestCase
{
    protected AuthenticatedHttpClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new AuthenticatedHttpClient();
    }

    // ─── Form-based credential auth ─────────────────────────────────────

    public function test_form_based_credential_auth_with_session_cookie(): void
    {
        Http::fake([
            'https://example.com/login' => Http::response('OK', 200, [
                'Set-Cookie' => 'laravel_session=abc123; Path=/; HttpOnly',
            ]),
        ]);

        $result = $this->client->authenticateWithCredentials(
            'https://example.com/login',
            'user@example.com',
            'password123'
        );

        $this->assertTrue($result);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://example.com/login'
                && $request->method() === 'POST'
                && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
                && str_contains($request->body(), 'email=user%40example.com')
                && str_contains($request->body(), 'password=password123');
        });
    }

    // ─── JSON credential auth ───────────────────────────────────────────

    public function test_json_credential_auth_with_token_response(): void
    {
        Http::fake([
            'https://api.example.com/login' => Http::response([
                'token' => 'jwt-token-abc123',
            ], 200),
        ]);

        $result = $this->client->authenticateWithCredentials(
            'https://api.example.com/login',
            'user@example.com',
            'secret',
            [],
            ['json_auth' => true]
        );

        $this->assertTrue($result);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.example.com/login'
                && $request->method() === 'POST'
                && $request->hasHeader('Accept', 'application/json')
                && $request->hasHeader('X-Requested-With', 'XMLHttpRequest');
        });

        // Verify the token is used for subsequent requests
        Http::fake([
            'https://api.example.com/data' => Http::response('protected data', 200),
        ]);

        $content = $this->client->fetchAuthenticatedUrl('https://api.example.com/data');
        $this->assertEquals('protected data', $content);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.example.com/data'
                && $request->hasHeader('Authorization', 'Bearer jwt-token-abc123');
        });
    }

    // ─── OAuth token in response ────────────────────────────────────────

    public function test_json_credential_auth_with_access_token_response(): void
    {
        Http::fake([
            'https://api.example.com/oauth/login' => Http::response([
                'access_token' => 'oauth-access-token-xyz',
                'token_type' => 'Bearer',
            ], 200),
        ]);

        $result = $this->client->authenticateWithCredentials(
            'https://api.example.com/oauth/login',
            'user@example.com',
            'secret',
            [],
            ['json_auth' => true]
        );

        $this->assertTrue($result);

        // Verify OAuth token is used in subsequent requests
        Http::fake([
            'https://api.example.com/resource' => Http::response('resource data', 200),
        ]);

        $this->client->fetchAuthenticatedUrl('https://api.example.com/resource');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.example.com/resource'
                && $request->hasHeader('Authorization', 'Bearer oauth-access-token-xyz');
        });
    }

    // ─── Custom field names ─────────────────────────────────────────────

    public function test_credential_auth_with_custom_field_names(): void
    {
        Http::fake([
            'https://example.com/auth' => Http::response([
                'token' => 'custom-token',
            ], 200),
        ]);

        $result = $this->client->authenticateWithCredentials(
            'https://example.com/auth',
            'admin@example.com',
            'admin-pass',
            ['remember' => true],
            [
                'email_field' => 'username',
                'password_field' => 'pass',
                'json_auth' => true,
            ]
        );

        $this->assertTrue($result);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            return $request->url() === 'https://example.com/auth'
                && isset($body['username'])
                && $body['username'] === 'admin@example.com'
                && isset($body['pass'])
                && $body['pass'] === 'admin-pass'
                && isset($body['remember'])
                && $body['remember'] === true;
        });
    }

    // ─── Bearer token auth ──────────────────────────────────────────────

    public function test_bearer_token_auth(): void
    {
        $this->client->authenticateWithBearerToken('my-bearer-token');

        Http::fake([
            'https://api.example.com/protected' => Http::response('bearer data', 200),
        ]);

        $content = $this->client->fetchAuthenticatedUrl('https://api.example.com/protected');

        $this->assertEquals('bearer data', $content);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer my-bearer-token');
        });
    }

    // ─── JWT auth ───────────────────────────────────────────────────────

    public function test_jwt_auth(): void
    {
        $this->client->authenticateWithJWT('my-jwt-token');

        Http::fake([
            'https://api.example.com/jwt-resource' => Http::response('jwt data', 200),
        ]);

        $content = $this->client->fetchAuthenticatedUrl('https://api.example.com/jwt-resource');

        $this->assertEquals('jwt data', $content);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'JWT my-jwt-token');
        });
    }

    // ─── API key auth ───────────────────────────────────────────────────

    public function test_api_key_auth_with_default_header(): void
    {
        $this->client->authenticateWithApiKey('my-api-key-123');

        Http::fake([
            'https://api.example.com/data' => Http::response('api key data', 200),
        ]);

        $content = $this->client->fetchAuthenticatedUrl('https://api.example.com/data');

        $this->assertEquals('api key data', $content);

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-API-Key', 'my-api-key-123');
        });
    }

    public function test_api_key_auth_with_custom_header(): void
    {
        $this->client->authenticateWithApiKey('key-456', 'X-Custom-Auth');

        Http::fake([
            'https://api.example.com/data' => Http::response('custom api data', 200),
        ]);

        $content = $this->client->fetchAuthenticatedUrl('https://api.example.com/data');

        $this->assertEquals('custom api data', $content);

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-Custom-Auth', 'key-456');
        });
    }

    // ─── Session cookie auth ────────────────────────────────────────────

    public function test_session_cookie_auth(): void
    {
        $this->client->authenticateWithSessionCookie('laravel_session', 'session-value-abc');

        Http::fake([
            'https://example.com/dashboard' => Http::response('dashboard content', 200),
        ]);

        $content = $this->client->fetchAuthenticatedUrl('https://example.com/dashboard');

        $this->assertEquals('dashboard content', $content);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Cookie')
                && str_contains($request->header('Cookie')[0], 'laravel_session=session-value-abc');
        });
    }

    // ─── OAuth2 auth ────────────────────────────────────────────────────

    public function test_oauth2_auth(): void
    {
        $this->client->authenticateWithOAuth2('oauth2-access-token');

        Http::fake([
            'https://api.example.com/oauth-resource' => Http::response('oauth2 data', 200),
        ]);

        $content = $this->client->fetchAuthenticatedUrl('https://api.example.com/oauth-resource');

        $this->assertEquals('oauth2 data', $content);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer oauth2-access-token');
        });
    }

    // ─── Custom headers auth ────────────────────────────────────────────

    public function test_custom_headers_auth(): void
    {
        $this->client->authenticateWithCustomHeaders([
            'X-Custom-Token' => 'custom-value',
            'X-Tenant-Id' => 'tenant-123',
        ]);

        Http::fake([
            'https://api.example.com/custom' => Http::response('custom header data', 200),
        ]);

        $content = $this->client->fetchAuthenticatedUrl('https://api.example.com/custom');

        $this->assertEquals('custom header data', $content);

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-Custom-Token', 'custom-value')
                && $request->hasHeader('X-Tenant-Id', 'tenant-123');
        });
    }

    // ─── Sanctum auth ───────────────────────────────────────────────────

    public function test_sanctum_auth_with_token_response(): void
    {
        Http::fake([
            'https://app.example.com/sanctum/csrf-cookie' => Http::response('', 204, [
                'Set-Cookie' => 'XSRF-TOKEN=csrf-token-value; Path=/',
            ]),
            'https://app.example.com/login' => Http::response([
                'token' => 'sanctum-api-token',
            ], 200, [
                'Set-Cookie' => 'laravel_session=sanctum-session; Path=/; HttpOnly',
            ]),
        ]);

        $token = $this->client->authenticateWithSanctum(
            'https://app.example.com',
            'user@example.com',
            'password'
        );

        $this->assertEquals('sanctum-api-token', $token);

        // Verify CSRF cookie request
        Http::assertSent(function ($request) {
            return $request->url() === 'https://app.example.com/sanctum/csrf-cookie'
                && $request->method() === 'GET';
        });

        // Verify login request includes XSRF token and cookies
        Http::assertSent(function ($request) {
            return $request->url() === 'https://app.example.com/login'
                && $request->method() === 'POST'
                && $request->hasHeader('X-XSRF-TOKEN', 'csrf-token-value')
                && $request->hasHeader('X-Requested-With', 'XMLHttpRequest');
        });
    }

    public function test_sanctum_auth_without_token_response(): void
    {
        Http::fake([
            'https://app.example.com/sanctum/csrf-cookie' => Http::response('', 204, [
                'Set-Cookie' => 'XSRF-TOKEN=csrf-token; Path=/',
            ]),
            'https://app.example.com/login' => Http::response([
                'message' => 'Authenticated',
            ], 200, [
                'Set-Cookie' => 'laravel_session=session-val; Path=/; HttpOnly',
            ]),
        ]);

        $token = $this->client->authenticateWithSanctum(
            'https://app.example.com',
            'user@example.com',
            'password'
        );

        $this->assertNull($token);
    }

    public function test_sanctum_auth_throws_when_csrf_cookie_missing(): void
    {
        Http::fake([
            'https://app.example.com/sanctum/csrf-cookie' => Http::response('', 204),
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to get CSRF token from Sanctum');

        $this->client->authenticateWithSanctum(
            'https://app.example.com',
            'user@example.com',
            'password'
        );
    }

    // ─── fetchAuthenticatedUrl ──────────────────────────────────────────

    public function test_fetch_authenticated_url_throws_on_failure(): void
    {
        Http::fake([
            'https://example.com/missing' => Http::response('Not Found', 404),
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to fetch authenticated URL: https://example.com/missing');

        $this->client->fetchAuthenticatedUrl('https://example.com/missing');
    }

    public function test_fetch_authenticated_url_with_ssl_verification_disabled(): void
    {
        $this->client->authenticateWithBearerToken('my-token');

        Http::fake([
            'https://self-signed.example.com/data' => Http::response('insecure data', 200),
        ]);

        $content = $this->client->fetchAuthenticatedUrl('https://self-signed.example.com/data', false);

        $this->assertEquals('insecure data', $content);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://self-signed.example.com/data'
                && $request->hasHeader('Authorization', 'Bearer my-token');
        });
    }

    // ─── clearAuthentication ────────────────────────────────────────────

    public function test_clear_authentication(): void
    {
        // Set up various auth
        $this->client->authenticateWithBearerToken('token');
        $this->client->authenticateWithSessionCookie('session', 'value');
        $this->client->authenticateWithApiKey('key');

        // Clear it all
        $this->client->clearAuthentication();

        // Verify headers are clean - fetch should have no auth headers
        Http::fake([
            'https://example.com/public' => Http::response('public data', 200),
        ]);

        $content = $this->client->fetchAuthenticatedUrl('https://example.com/public');
        $this->assertEquals('public data', $content);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://example.com/public'
                && !$request->hasHeader('Authorization')
                && !$request->hasHeader('X-API-Key')
                && !$request->hasHeader('Cookie');
        });
    }

    // ─── Form auth without session cookie ───────────────────────────────

    public function test_form_credential_auth_returns_false_without_session_cookie(): void
    {
        Http::fake([
            'https://example.com/login' => Http::response('OK', 200),
        ]);

        $result = $this->client->authenticateWithCredentials(
            'https://example.com/login',
            'user@example.com',
            'password'
        );

        $this->assertFalse($result);
    }
}
