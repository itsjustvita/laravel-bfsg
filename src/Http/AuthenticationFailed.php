<?php

namespace ItsJustVita\LaravelBfsg\Http;

use RuntimeException;

/** A login attempt failed; `reason` names the cause for callers that branch on it. */
final class AuthenticationFailed extends RuntimeException
{
    /** @param  'csrf'|'credentials'|'validation'|'no_session'|'two_factor'|'login_page'|'connection'  $reason */
    public function __construct(string $message, public readonly string $reason, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }

    public static function csrf(string $url, ?int $status = 419): self
    {
        return new self("Login at {$url} was rejected because of the CSRF token (HTTP ".($status ?? 419).'). Check that the login page sets the session cookie and a _token field or an XSRF-TOKEN cookie.', 'csrf', $status);
    }

    public static function credentials(string $url, ?int $status): self
    {
        return new self("Login at {$url} failed: invalid credentials".($status === null ? ' (redirected back to the login page)' : " (HTTP {$status})").'.', 'credentials', $status);
    }

    public static function validation(string $url, ?string $detail): self
    {
        return new self("Login at {$url} failed validation (HTTP 422)".($detail === null ? '' : ": {$detail}").'.', 'validation', 422);
    }

    public static function noSession(string $url, int $status): self
    {
        return new self("Login at {$url} returned HTTP {$status} without a session cookie or token.", 'no_session', $status);
    }

    public static function twoFactor(string $url): self
    {
        return new self("Login at {$url} asks for a second factor (two-factor authentication); use --session or a token instead.", 'two_factor');
    }

    public static function loginPage(string $url, int $status): self
    {
        return new self("The login page {$url} returned HTTP {$status}.", 'login_page', $status);
    }

    public static function connection(string $url, string $detail): self
    {
        return new self("Could not reach the login URL {$url}: {$detail}", 'connection');
    }
}
