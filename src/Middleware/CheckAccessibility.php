<?php

namespace ItsJustVita\LaravelBfsg\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use ItsJustVita\LaravelBfsg\AnalysisResult;
use ItsJustVita\LaravelBfsg\Facades\Bfsg;
use ItsJustVita\LaravelBfsg\Http\InProcessFetcher;
use ItsJustVita\LaravelBfsg\Persistence\ReportRepository;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Analyzes full HTML pages after the response has been sent (terminate), logs the counts per severity on the
 * configured channel and stores the report when `bfsg.reporting.save_to_database` is on. With `app.debug` the
 * analysis runs in handle() instead so the X-BFSG-Violations header can be set. Never breaks the page.
 */
class CheckAccessibility
{
    /** Request attribute: handle() accepted the response for analysis. */
    public const PENDING = 'bfsg.pending';

    /** Request attribute: the result of a synchronous (debug) analysis, reused by terminate(). */
    public const RESULT = 'bfsg.result';

    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        try {
            if ($this->shouldCheck($request, $response)) {
                if (config('app.debug')) {
                    $result = $this->analyze($request, $response);
                    $request->attributes->set(self::RESULT, $result);

                    if ($result->count() > 0) {
                        $response->headers->set('X-BFSG-Violations', (string) $result->count());
                    }
                }

                // Only now: a debug analysis that failed was logged above and must not run again in terminate()
                $request->attributes->set(self::PENDING, true);
            }
        } catch (Throwable $e) {
            $this->failed($request, $e);
        }

        return $response;
    }

    public function terminate(Request $request, SymfonyResponse $response): void
    {
        if (! $request->attributes->get(self::PENDING)) {
            return;
        }

        $request->attributes->remove(self::PENDING);

        try {
            $result = $request->attributes->get(self::RESULT) ?? $this->analyze($request, $response);

            $this->log($result);

            if (config('bfsg.reporting.save_to_database')) {
                app(ReportRepository::class)->store($result, ['source' => 'middleware']);
            }
        } catch (Throwable $e) {
            $this->failed($request, $e);
        }
    }

    /** GET, a successful Illuminate HTML response with a body, not XHR/Livewire/Inertia, not an ignored path. */
    protected function shouldCheck(Request $request, mixed $response): bool
    {
        if (! config('bfsg.middleware.enabled', false) || ! $request->isMethod('GET') || $request->attributes->get(InProcessFetcher::SKIP_ATTRIBUTE)) {
            return false;
        }

        if ($request->ajax() || $request->headers->has('X-Livewire') || $request->headers->has('X-Inertia')) {
            return false;
        }

        if (! $response instanceof Response || ! $response->isSuccessful() || stripos((string) $response->headers->get('Content-Type', ''), 'text/html') === false) {
            return false;
        }

        $content = $response->getContent();

        if (! is_string($content) || trim($content) === '') {
            return false;
        }

        foreach ((array) config('bfsg.middleware.ignored_paths', []) as $path) {
            if ($request->is($path)) {
                return false;
            }
        }

        return true;
    }

    protected function analyze(Request $request, SymfonyResponse $response): AnalysisResult
    {
        return Bfsg::analyze((string) $response->getContent(), ['url' => $request->fullUrl()]);
    }

    /** One line per page with findings; counts only, at warning level when the page is not accessible. */
    protected function log(AnalysisResult $result): void
    {
        if ($result->count() === 0 || ! config('bfsg.middleware.log_violations', true)) {
            return;
        }

        $counts = $result->countBySeverity();

        Log::channel(config('bfsg.middleware.log_channel'))->log(
            $result->isAccessible() ? 'info' : 'warning',
            "BFSG: {$result->count()} violations on {$result->url()}",
            ['errors' => $counts['error'], 'warnings' => $counts['warning'], 'notices' => $counts['notice']],
        );
    }

    protected function failed(Request $request, Throwable $e): void
    {
        try {
            Log::error('BFSG: accessibility analysis failed for '.$request->fullUrl(), ['exception' => $e]);
        } catch (Throwable) {
            // logging must not break the page either
        }
    }
}
