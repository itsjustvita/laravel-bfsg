<?php

namespace ItsJustVita\LaravelBfsg\Middleware;

use Closure;
use Illuminate\Http\Request;
use ItsJustVita\LaravelBfsg\Facades\Bfsg;
use Illuminate\Support\Facades\Log;

class CheckAccessibility
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Only check HTML responses
        if (!$this->shouldCheck($request, $response)) {
            return $response;
        }

        // Get HTML content
        $html = $response->getContent();

        // Analyze for accessibility
        $violations = Bfsg::analyze($html);

        if (!empty($violations)) {
            $this->handleViolations($request, $violations);

            // Add violations to response headers for debugging
            if (config('app.debug')) {
                $response->headers->set(
                    'X-BFSG-Violations',
                    array_sum(array_map('count', $violations))
                );
            }
        }

        return $response;
    }

    /**
     * Determine if the response should be checked
     */
    protected function shouldCheck(Request $request, $response): bool
    {
        // Only check GET requests
        if (!$request->isMethod('GET')) {
            return false;
        }

        // Only check HTML responses
        $contentType = $response->headers->get('Content-Type', '');
        if (stripos($contentType, 'text/html') === false) {
            return false;
        }

        // Skip if disabled
        if (!config('bfsg.middleware.enabled', true)) {
            return false;
        }

        // Check if URL is in ignored paths
        $ignoredPaths = config('bfsg.middleware.ignored_paths', []);
        foreach ($ignoredPaths as $path) {
            if ($request->is($path)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Handle found violations
     */
    protected function handleViolations(Request $request, array $violations): void
    {
        $totalViolations = array_sum(array_map('count', $violations));
        $url = $request->fullUrl();

        // Log violations
        if (config('bfsg.middleware.log_violations', true)) {
            Log::warning("BFSG: {$totalViolations} accessibility violations found on {$url}", [
                'url' => $url,
                'violations' => $violations,
                'user_id' => $request->user()?->id,
                'ip' => $request->ip(),
            ]);
        }

        // Send notification if configured
        if (config('bfsg.reporting.enabled') && config('bfsg.reporting.email')) {
            // This would send an email notification
            // You can implement this based on your notification preferences
        }

        // Store in database if configured
        if (config('bfsg.reporting.save_to_database')) {
            $this->storeViolations($url, $violations);
        }
    }

    /**
     * Store violations in database
     */
    protected function storeViolations(string $url, array $violations): void
    {
        // This would store violations in a database table
        // You can create a migration for this if needed
    }
}