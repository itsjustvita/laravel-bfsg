<?php

namespace ItsJustVita\LaravelBfsg;

use DOMDocument;
use Exception;
use Illuminate\Support\Facades\Process;

class BrowserAnalyzer
{
    protected array $analyzers;
    protected array $options;

    public function __construct(array $options = [])
    {
        $this->options = array_merge([
            'timeout' => 30000,
            'waitForSelector' => 'body',
            'browser' => 'chromium',
            'headless' => true,
        ], $options);

        $this->analyzers = [
            new Analyzers\HeadingAnalyzer(),
            new Analyzers\ImageAnalyzer(),
            new Analyzers\FormAnalyzer(),
            new Analyzers\AriaAnalyzer(),
            new Analyzers\LinkAnalyzer(),
            new Analyzers\ContrastAnalyzer(),
            new Analyzers\KeyboardNavigationAnalyzer(),
        ];
    }

    /**
     * Analyze a URL using a headless browser to capture fully rendered content
     */
    public function analyzeUrl(string $url): array
    {
        try {
            // Check if Playwright is installed
            $this->ensurePlaywrightInstalled();

            // Get the fully rendered HTML from the browser
            $html = $this->getRenderedHtml($url);

            // Convert to DOMDocument for analysis
            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML($html);
            libxml_clear_errors();

            // Run all analyzers
            $results = [];
            foreach ($this->analyzers as $analyzer) {
                $analyzerName = class_basename($analyzer);
                $results[$analyzerName] = $analyzer->analyze($dom);
            }

            return [
                'success' => true,
                'url' => $url,
                'rendered' => true,
                'results' => $results,
                'summary' => $this->generateSummary($results),
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'fallback_message' => 'Browser analysis failed. Consider using server-side analysis for static content.',
            ];
        }
    }

    /**
     * Get fully rendered HTML from a URL using Playwright
     */
    protected function getRenderedHtml(string $url): string
    {
        // Get the project root directory (where node_modules is)
        $projectRoot = base_path();

        // Create a temporary JavaScript file for Playwright
        $scriptPath = sys_get_temp_dir().'/bfsg_browser_'.uniqid().'.js';
        $playwrightPath = $projectRoot.'/node_modules/playwright';

        $script = <<<JS
const { chromium, firefox, webkit } = require('{$playwrightPath}');

(async () => {
    const browser = await {$this->options['browser']}.launch({
        headless: {$this->getHeadlessOption()}
    });

    const page = await browser.newPage();

    try {
        // Navigate to the URL
        await page.goto('{$url}', {
            waitUntil: 'networkidle',
            timeout: {$this->options['timeout']}
        });

        // Wait for the specified selector
        await page.waitForSelector('{$this->options['waitForSelector']}', {
            timeout: {$this->options['timeout']}
        });

        // Wait a bit more for any dynamic content
        await page.waitForTimeout(1000);

        // Get the full page HTML
        const html = await page.content();
        console.log(html);

    } catch (error) {
        console.error('Error:', error.message);
        process.exit(1);
    } finally {
        await browser.close();
    }
})();
JS;

        file_put_contents($scriptPath, $script);

        try {
            // Execute Playwright script from the project root directory
            $result = Process::path($projectRoot)->timeout(60)->run("node {$scriptPath}");

            if (! $result->successful()) {
                throw new Exception('Failed to render page: '.$result->errorOutput());
            }

            return $result->output();
        } finally {
            // Clean up temp file
            if (file_exists($scriptPath)) {
                unlink($scriptPath);
            }
        }
    }

    /**
     * Check if Playwright is installed
     */
    protected function ensurePlaywrightInstalled(): void
    {
        $projectRoot = base_path();
        $checkPlaywright = Process::path($projectRoot)->run('npm list playwright');

        if (! $checkPlaywright->successful()) {
            throw new Exception(
                'Playwright is not installed. Please run: npm install playwright && npx playwright install'
            );
        }
    }

    /**
     * Get headless option as string for JavaScript
     */
    protected function getHeadlessOption(): string
    {
        return $this->options['headless'] ? 'true' : 'false';
    }

    /**
     * Generate a summary of all issues found
     */
    protected function generateSummary(array $results): array
    {
        $totalIssues = 0;
        $issuesByType = [
            'error' => 0,
            'warning' => 0,
            'notice' => 0,
        ];

        foreach ($results as $analyzerResults) {
            if (isset($analyzerResults['issues'])) {
                foreach ($analyzerResults['issues'] as $issue) {
                    $totalIssues++;
                    $type = $issue['type'] ?? 'notice';
                    $issuesByType[$type]++;
                }
            }
        }

        return [
            'total_issues' => $totalIssues,
            'errors' => $issuesByType['error'],
            'warnings' => $issuesByType['warning'],
            'notices' => $issuesByType['notice'],
        ];
    }

    /**
     * Set custom analyzers
     */
    public function setAnalyzers(array $analyzers): self
    {
        $this->analyzers = $analyzers;

        return $this;
    }

    /**
     * Add an analyzer
     */
    public function addAnalyzer($analyzer): self
    {
        $this->analyzers[] = $analyzer;

        return $this;
    }
}