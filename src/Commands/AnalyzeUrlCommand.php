<?php

namespace ItsJustVita\LaravelBfsg\Commands;

use Illuminate\Console\Command;
use ItsJustVita\LaravelBfsg\BrowserAnalyzer;

class AnalyzeUrlCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bfsg:analyze {url}
                            {--browser : Use browser rendering for SPAs}
                            {--headless=true : Run browser in headless mode}
                            {--timeout=30000 : Timeout in milliseconds}
                            {--verify-ssl=false : Verify SSL certificates (set to true for production)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Analyze a URL for BFSG/WCAG accessibility compliance';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $url = $this->argument('url');
        $useBrowser = $this->option('browser');

        $this->info("🔍 Analyzing: {$url}");

        if ($useBrowser) {
            $this->info('📱 Using browser rendering (for SPAs)...');
            $this->analyzeWithBrowser($url);
        } else {
            $this->info('📄 Using server-side analysis...');
            $this->analyzeServerSide($url);
        }

        return Command::SUCCESS;
    }

    protected function analyzeWithBrowser(string $url): void
    {
        $analyzer = new BrowserAnalyzer([
            'headless' => filter_var($this->option('headless'), FILTER_VALIDATE_BOOLEAN),
            'timeout' => (int) $this->option('timeout'),
        ]);

        $this->info('⏳ Starting browser (this may take a moment)...');

        $results = $analyzer->analyzeUrl($url);

        if (! $results['success']) {
            $this->error('❌ Analysis failed: '.$results['error']);
            if (isset($results['fallback_message'])) {
                $this->warn($results['fallback_message']);
            }

            return;
        }

        $this->displayResults($results);
    }

    protected function analyzeServerSide(string $url): void
    {
        try {
            // Fetch HTML content with SSL handling
            $verifySSL = filter_var($this->option('verify-ssl'), FILTER_VALIDATE_BOOLEAN);

            $context = stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'user_agent' => 'BFSG-Analyzer/1.2',
                ],
                'ssl' => [
                    'verify_peer' => $verifySSL,
                    'verify_peer_name' => $verifySSL,
                    'allow_self_signed' => !$verifySSL,
                ],
            ]);

            $html = @file_get_contents($url, false, $context);

            if ($html === false) {
                $this->error('❌ Failed to fetch URL content');
                $this->warn('Tip: For local development with self-signed certificates, this is expected.');

                return;
            }

            // Convert to DOMDocument
            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML($html);
            libxml_clear_errors();

            // Run analyzers
            $analyzers = [
                'HeadingAnalyzer' => new \ItsJustVita\LaravelBfsg\Analyzers\HeadingAnalyzer(),
                'ImageAnalyzer' => new \ItsJustVita\LaravelBfsg\Analyzers\ImageAnalyzer(),
                'FormAnalyzer' => new \ItsJustVita\LaravelBfsg\Analyzers\FormAnalyzer(),
                'AriaAnalyzer' => new \ItsJustVita\LaravelBfsg\Analyzers\AriaAnalyzer(),
                'LinkAnalyzer' => new \ItsJustVita\LaravelBfsg\Analyzers\LinkAnalyzer(),
                'ContrastAnalyzer' => new \ItsJustVita\LaravelBfsg\Analyzers\ContrastAnalyzer(),
                'KeyboardNavigationAnalyzer' => new \ItsJustVita\LaravelBfsg\Analyzers\KeyboardNavigationAnalyzer(),
            ];

            $results = [
                'success' => true,
                'url' => $url,
                'rendered' => false,
                'results' => [],
            ];

            foreach ($analyzers as $name => $analyzer) {
                $results['results'][$name] = $analyzer->analyze($dom);
            }

            $results['summary'] = $this->calculateSummary($results['results']);

            $this->displayResults($results);
        } catch (\Exception $e) {
            $this->error('❌ Analysis failed: '.$e->getMessage());
        }
    }

    protected function displayResults(array $results): void
    {
        $this->newLine();
        $this->info('✅ Analysis Complete');
        $this->info('==================');

        // Display summary
        if (isset($results['summary'])) {
            $summary = $results['summary'];
            $this->line("Total Issues: {$summary['total_issues']}");
            $this->line("  • Errors: {$summary['errors']}");
            $this->line("  • Warnings: {$summary['warnings']}");
            $this->line("  • Notices: {$summary['notices']}");
        }

        $this->newLine();

        // Display issues by analyzer
        foreach ($results['results'] as $analyzerName => $result) {
            $issues = $result['issues'] ?? [];
            if (empty($issues)) {
                continue;
            }

            $this->info("[{$analyzerName}] - ".count($issues).' issues');

            foreach (array_slice($issues, 0, 3) as $issue) {
                $type = strtoupper($issue['type'] ?? 'NOTICE');
                $message = $issue['message'];

                switch ($issue['type'] ?? 'notice') {
                    case 'error':
                        $this->error("  • [{$type}] {$message}");
                        break;
                    case 'warning':
                        $this->warn("  • [{$type}] {$message}");
                        break;
                    default:
                        $this->line("  • [{$type}] {$message}");
                }

                if (isset($issue['suggestion'])) {
                    $this->line("    → {$issue['suggestion']}");
                }
            }

            if (count($issues) > 3) {
                $this->line('  ... and '.(count($issues) - 3).' more');
            }

            $this->newLine();
        }
    }

    protected function calculateSummary(array $results): array
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
}