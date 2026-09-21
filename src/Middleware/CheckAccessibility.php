<?php

namespace ItsJustVita\LaravelBfsg\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use ItsJustVita\LaravelBfsg\AnalysisResult;
use ItsJustVita\LaravelBfsg\Facades\Bfsg;
use ItsJustVita\LaravelBfsg\Persistence\ReportRepository;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CheckAccessibility
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Only check HTML responses
        if (! $this->shouldCheck($request, $response)) {
            return $response;
        }

        // Get HTML content
        $html = $response->getContent();

        if (! is_string($html) || trim($html) === '') {
            return $response;
        }

        // The middleware must never break the page it inspects.
        try {
            $result = Bfsg::analyze($html, ['url' => $request->fullUrl()]);

            if ($result->count() > 0) {
                $this->handleViolations($request, $result);

                // Add violations to response headers for debugging
                if (config('app.debug')) {
                    $response->headers->set('X-BFSG-Violations', (string) $result->count());
                }
            }
        } catch (Throwable $e) {
            Log::error('BFSG: accessibility analysis failed for '.$request->fullUrl(), [
                'exception' => $e,
            ]);
        }

        return $response;
    }

    /**
     * Determine if the response should be checked
     */
    protected function shouldCheck(Request $request, $response): bool
    {
        // Only check GET requests
        if (! $request->isMethod('GET')) {
            return false;
        }

        // Only full HTML page responses carry a body worth analyzing
        if (! $response instanceof SymfonyResponse
            || $response instanceof RedirectResponse
            || $response instanceof JsonResponse
            || $response instanceof StreamedResponse
            || $response instanceof BinaryFileResponse) {
            return false;
        }

        // Skip redirects, error pages and anything else that is not a 2xx page
        if (! $response->isSuccessful()) {
            return false;
        }

        // Only check HTML responses
        $contentType = $response->headers->get('Content-Type', '');
        if (stripos($contentType, 'text/html') === false) {
            return false;
        }

        // Skip if disabled (config default is false)
        if (! config('bfsg.middleware.enabled', false)) {
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
    protected function handleViolations(Request $request, AnalysisResult $result): void
    {
        $url = $request->fullUrl();

        // Log violations
        if (config('bfsg.middleware.log_violations', true)) {
            $counts = $result->countBySeverity();

            Log::warning("BFSG: {$result->count()} accessibility violations found on {$url}", [
                'url' => $url,
                'errors' => $counts['error'],
                'warnings' => $counts['warning'],
                'notices' => $counts['notice'],
                'violations' => $result->toArray()['violations'],
                'user_id' => $request->user()?->id,
                'ip' => $request->ip(),
            ]);
        }

        // Store in database if configured
        if (config('bfsg.reporting.save_to_database')) {
            $this->storeViolations($result);
        }
    }

    /**
     * Store the analysis in the database
     */
    protected function storeViolations(AnalysisResult $result): void
    {
        app(ReportRepository::class)->store($result);
    }
}
