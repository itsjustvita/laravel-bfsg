<?php

namespace ItsJustVita\LaravelBfsg;

use ItsJustVita\LaravelBfsg\Analyzers\ImageAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\FormAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\HeadingAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\ContrastAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\AriaAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\LinkAnalyzer;

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
            $this->analyzers['images'] = new ImageAnalyzer();
        }

        if ($checks['forms'] ?? true) {
            $this->analyzers['forms'] = new FormAnalyzer();
        }

        if ($checks['headings'] ?? true) {
            $this->analyzers['headings'] = new HeadingAnalyzer();
        }

        if ($checks['contrast'] ?? true) {
            $this->analyzers['contrast'] = new ContrastAnalyzer();
        }

        if ($checks['aria'] ?? true) {
            $this->analyzers['aria'] = new AriaAnalyzer();
        }

        if ($checks['links'] ?? true) {
            $this->analyzers['links'] = new LinkAnalyzer();
        }
    }
    
    /**
     * Analyze HTML for accessibility
     */
    public function analyze(string $html): array
    {
        $this->violations = [];
        
        $dom = new \DOMDocument();
        @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        
        foreach ($this->analyzers as $name => $analyzer) {
            $results = $analyzer->analyze($dom);
            if (!empty($results)) {
                $this->violations[$name] = $results;
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