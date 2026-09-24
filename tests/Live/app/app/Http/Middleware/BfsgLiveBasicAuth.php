<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Fixture for tests/Live/smoke.sh: HTTP basic auth (deploy / s3cret) in front of /live/basic/*, like a staging site. */
class BfsgLiveBasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->getUser() !== 'deploy' || $request->getPassword() !== 's3cret') {
            return response('Unauthorized', 401, ['WWW-Authenticate' => 'Basic realm="bfsg-live"']);
        }

        return $next($request);
    }
}
