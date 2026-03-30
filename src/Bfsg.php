<?php

namespace ItsJustVita\LaravelBfsg;

use ItsJustVita\LaravelBfsg\Analyzers\AriaAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\ContrastAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\ErrorHandlingAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\FocusAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\FormAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\HeadingAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\ImageAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\InputPurposeAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\KeyboardNavigationAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\LanguageAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\LinkAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\MediaAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\PageTitleAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\SemanticHTMLAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\StatusMessageAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\TableAnalyzer;

class Bfsg
{
    protected array $analyzers = [];

    protected array $violations = [];

    public function __construct()
    {
        $this->registerDefaultAnalyzers();
    }

    /**
     * Register the default analyzers
     */
    protected function registerDefaultAnalyzers(): void
    {
        // Get checks config if available, otherwise use defaults
        $checks = [];
        if (function_exists('config') && function_exists('app')) {
            try {
                $checks = config('bfsg.checks', []);
            } catch (\Exception $e) {
                // Config not available, use defaults
            }
        }

        if ($checks['images'] ?? true) {
            $this->analyzers['images'] = new ImageAnalyzer;
        }

        if ($checks['forms'] ?? true) {
            $this->analyzers['forms'] = new FormAnalyzer;
        }

        if ($checks['headings'] ?? true) {
            $this->analyzers['headings'] = new HeadingAnalyzer;
        }

        if ($checks['contrast'] ?? true) {
            $this->analyzers['contrast'] = new ContrastAnalyzer;
        }

        if ($checks['aria'] ?? true) {
            $this->analyzers['aria'] = new AriaAnalyzer;
        }

        if ($checks['links'] ?? true) {
            $this->analyzers['links'] = new LinkAnalyzer;
        }

        if ($checks['keyboard'] ?? true) {
            $this->analyzers['keyboard'] = new KeyboardNavigationAnalyzer;
        }

        if ($checks['language'] ?? true) {
            $this->analyzers['language'] = new LanguageAnalyzer;
        }

        if ($checks['tables'] ?? true) {
            $this->analyzers['tables'] = new TableAnalyzer;
        }

        if ($checks['media'] ?? true) {
            $this->analyzers['media'] = new MediaAnalyzer;
        }

        if ($checks['semantic'] ?? true) {
            $this->analyzers['semantic'] = new SemanticHTMLAnalyzer;
        }

        if ($checks['page_title'] ?? true) {
            $this->analyzers['page_title'] = new PageTitleAnalyzer;
        }

        if ($checks['input_purpose'] ?? true) {
            $this->analyzers['input_purpose'] = new InputPurposeAnalyzer;
        }

        if ($checks['focus'] ?? true) {
            $this->analyzers['focus'] = new FocusAnalyzer;
        }

        if ($checks['error_handling'] ?? true) {
            $this->analyzers['error_handling'] = new ErrorHandlingAnalyzer;
        }

        if ($checks['status_messages'] ?? true) {
            $this->analyzers['status_messages'] = new StatusMessageAnalyzer;
        }
    }

    /**
     * Analyze HTML for accessibility
     */
    public function analyze(string $html): array
    {
        $this->violations = [];

        $dom = new \DOMDocument;
        @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        foreach ($this->analyzers as $name => $analyzer) {
            $results = $analyzer->analyze($dom);
            // Extract issues from the result array
            if (! empty($results['issues'])) {
                $this->violations[$name] = $results['issues'];
            }
        }

        return $this->violations;
    }

    /**
     * Check if HTML is accessible
     */
    public function isAccessible(string $html): bool
    {
        $violations = $this->analyze($html);

        return empty($violations);
    }

    /**
     * Get all violations
     */
    public function getViolations(): array
    {
        return $this->violations;
    }
}
