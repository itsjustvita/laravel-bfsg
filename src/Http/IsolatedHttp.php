<?php

namespace ItsJustVita\LaravelBfsg\Http;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;

/**
 * Pending requests of the application's HTTP factory without its global configuration: the middleware an application
 * registers for its own requests (Http::globalMiddleware(), globalRequestMiddleware(), globalResponseMiddleware())
 * and its Http::globalOptions() are left out, so they can neither add a header (an internal service token) to a
 * request for a third-party host nor send it through a proxy. What tests rely on is kept: the stubs of Http::fake(),
 * Http::preventStrayRequests() and its allowed URLs, and the recording behind Http::assertSent(), which goes to the
 * root factory.
 *
 * A subclass of Factory only so that it may read those protected members of the root factory: $stubCallbacks,
 * $preventStrayRequests (Laravel 12 and 13) and $allowedStrayRequestUrls (Laravel 12.x and 13).
 *
 * @internal
 */
final class IsolatedHttp extends Factory
{
    public static function pendingRequest(Factory $root): PendingRequest
    {
        $request = (new PendingRequest($root))
            ->stub($root->stubCallbacks)
            ->preventStrayRequests($root->preventStrayRequests);

        if (property_exists($root, 'allowedStrayRequestUrls') && method_exists($request, 'allowStrayRequests')) {
            $request->allowStrayRequests($root->allowedStrayRequestUrls);
        }

        return $request;
    }
}
