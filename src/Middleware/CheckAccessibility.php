<?php

namespace ItsJustVita\LaravelBfsg\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use ItsJustVita\LaravelBfsg\Facades\Bfsg;
use ItsJustVita\LaravelBfsg\Models\BfsgReport;
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
            $violations = Bfsg::analyze($html);

            if (! empty($violations)) {
                $this->handleViolations($request, $violations);

                // Add violations to response headers for debugging
                if (config('app.debug')) {
                    $response->headers->set(
                        'X-BFSG-Violations',
                        array_sum(array_map('count', $violations))
                    );
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
        $totalViolations = array_sum(array_map('count', $violations));

        $critical = 0;
        $errors = 0;
        $warnings = 0;
        $notices = 0;

        foreach ($violations as $issues) {
            foreach ($issues as $issue) {
                match ($issue['type'] ?? $issue['severity'] ?? 'notice') {
                    'critical' => $critical++,
                    'error' => $errors++,
                    'warning' => $warnings++,
                    default => $notices++,
                };
            }
        }

        $score = max(0, min(100, (int) (100 - ($critical * 10 + $errors * 5 + $warnings * 2 + $notices * 0.5))));

        $grade = match (true) {
            $score >= 95 => 'A+',
            $score >= 90 => 'A',
            $score >= 85 => 'B+',
            $score >= 80 => 'B',
            $score >= 75 => 'C+',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default => 'F',
        };

        $report = BfsgReport::create([
            'url' => $url,
            'total_violations' => $totalViolations,
            'score' => $score,
            'grade' => $grade,
            'metadata' => [
                'compliance_level' => config('bfsg.compliance_level'),
            ],
        ]);

        foreach ($violations as $analyzer => $issues) {
            foreach ($issues as $issue) {
                $report->violations()->create([
                    'analyzer' => $analyzer,
                    'severity' => $issue['type'] ?? $issue['severity'] ?? 'notice',
                    'message' => $issue['message'],
                    'element' => $issue['element'] ?? null,
                    'wcag_rule' => $issue['rule'] ?? null,
                    'suggestion' => $issue['suggestion'] ?? null,
                ]);
            }
        }
    }
}
