<?php

namespace ItsJustVita\LaravelBfsg\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelBfsg\Facades\Bfsg;
use ItsJustVita\LaravelBfsg\Reports\ReportGenerator;
use ItsJustVita\LaravelBfsg\Services\AuthenticatedHttpClient;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

class BfsgCheckCommand extends Command
{
    protected $signature = 'bfsg:check {url?}
                            {--auth : Enable authentication}
                            {--email= : Email for authentication}
                            {--password= : Password for authentication}
                            {--username-field= : Custom username field name (default: email)}
                            {--password-field= : Custom password field name (default: password)}
                            {--login-url= : Custom login URL (default: /login)}
                            {--json-auth : Use JSON authentication instead of form-based}
                            {--bearer= : Bearer token for API authentication}
                            {--jwt= : JWT token for authentication}
                            {--api-key= : API key for authentication}
                            {--api-key-header= : API key header name (default: X-API-Key)}
                            {--session= : Session cookie value (format: name=value)}
                            {--guard= : Laravel guard name to use}
                            {--sanctum : Use Laravel Sanctum authentication}
                            {--detailed : Show detailed violation information}
                            {--save : Save results to database}
                            {--format=cli : Output format (cli, json, html)}
                            {--verify-ssl=false : Verify SSL certificates (set to true for production)}';

    protected $description = 'Check a URL for accessibility compliance';

    protected AuthenticatedHttpClient $httpClient;

    public function __construct()
    {
        parent::__construct();
        $this->httpClient = new AuthenticatedHttpClient;
    }

    public function handle()
    {
        $url = $this->argument('url') ?? config('app.url');

        $this->info("🔍 Checking accessibility for: {$url}");
        $this->newLine();

        try {
            // Handle authentication if needed
            if ($this->option('auth') || $this->option('bearer') || $this->option('session')) {
                $this->handleAuthentication($url);
            }

            // Fetch HTML content
            $html = $this->fetchHtml($url);

            // Analyze
            $violations = Bfsg::analyze($html);

            // Handle output based on format
            $format = $this->option('format');
            switch ($format) {
                case 'json':
                    $this->outputJson($violations, $url);
                    break;
                case 'html':
                    $this->outputHtml($violations, $url);
                    break;
                default:
                    $this->outputCli($violations);
                    break;
            }

            // Save to database if requested
            if ($this->option('save')) {
                $this->saveResults($url, $violations);
            }

            return empty($violations) ? Command::SUCCESS : Command::FAILURE;

        } catch (Exception $e) {
            $this->error('❌ Error: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    protected function handleAuthentication(string $baseUrl): void
    {
        // JWT authentication
        if ($jwt = $this->option('jwt')) {
            $this->info('🔐 Authenticating with JWT token...');
            $this->httpClient->authenticateWithJWT($jwt);
            $this->info('✅ JWT authentication configured');

            return;
        }

        // Bearer token authentication
        if ($bearer = $this->option('bearer')) {
            $this->info('🔐 Authenticating with bearer token...');
            $this->httpClient->authenticateWithBearerToken($bearer);
            $this->info('✅ Bearer token authentication configured');

            return;
        }

        // API Key authentication
        if ($apiKey = $this->option('api-key')) {
            $this->info('🔐 Authenticating with API key...');
            $headerName = $this->option('api-key-header') ?? 'X-API-Key';
            $this->httpClient->authenticateWithApiKey($apiKey, $headerName);
            $this->info('✅ API key authentication configured');

            return;
        }

        // Session cookie authentication
        if ($session = $this->option('session')) {
            $this->info('🔐 Authenticating with session cookie...');

            if (strpos($session, '=') === false) {
                throw new Exception('Session format must be: name=value');
            }

            [$name, $value] = explode('=', $session, 2);
            $this->httpClient->authenticateWithSessionCookie($name, $value);
            $this->info('✅ Session cookie authentication configured');

            return;
        }

        // Credentials authentication
        if ($this->option('auth')) {
            $email = $this->option('email') ?? $this->ask('Email');
            $password = $this->option('password') ?? $this->secret('Password');

            if (! $email || ! $password) {
                throw new Exception('Email and password are required for authentication');
            }

            $this->info('🔐 Authenticating with credentials...');

            if ($this->option('sanctum')) {
                // Sanctum authentication
                $token = $this->httpClient->authenticateWithSanctum($baseUrl, $email, $password);
                if ($token) {
                    $this->info('✅ Sanctum API token obtained');
                } else {
                    $this->info('✅ Sanctum session authentication successful');
                }
            } else {
                // Regular form authentication with custom field support
                $loginUrl = $this->option('login-url')
                    ? $baseUrl.'/'.ltrim($this->option('login-url'), '/')
                    : $baseUrl.'/login';

                $customFields = [];
                if ($this->option('username-field')) {
                    $customFields['email_field'] = $this->option('username-field');
                }
                if ($this->option('password-field')) {
                    $customFields['password_field'] = $this->option('password-field');
                }
                if ($this->option('json-auth')) {
                    $customFields['json_auth'] = true;
                }

                $additionalFields = [];
                if ($guard = $this->option('guard')) {
                    $additionalFields['guard'] = $guard;
                }

                $success = $this->httpClient->authenticateWithCredentials(
                    $loginUrl,
                    $email,
                    $password,
                    $additionalFields,
                    $customFields
                );

                if (! $success && ! $this->option('json-auth')) {
                    throw new Exception('Authentication failed - no session cookie received');
                }

                $this->info('✅ Authentication successful');
            }
        }
    }

    protected function fetchHtml(string $url): string
    {
        // Use authenticated client if we have authentication
        if ($this->option('auth') || $this->option('bearer') || $this->option('session')) {
            $verifySsl = filter_var($this->option('verify-ssl'), FILTER_VALIDATE_BOOLEAN);

            return $this->httpClient->fetchAuthenticatedUrl($url, $verifySsl);
        }

        // Check if this is a Herd domain and handle accordingly
        $parsedUrl = parse_url($url);
        $host = $parsedUrl['host'] ?? '';

        // If it's a .test domain (Herd domain), start a temporary PHP server
        if (str_ends_with($host, '.test')) {
            return $this->fetchHtmlFromHerdDomain($url);
        }

        // Otherwise use Http facade with SSL handling
        $verifySSL = filter_var($this->option('verify-ssl'), FILTER_VALIDATE_BOOLEAN);

        $response = Http::withOptions(['verify' => $verifySSL])
            ->timeout(30)
            ->withUserAgent('BFSG-Checker/2.0')
            ->get($url);

        if ($response->failed()) {
            $status = $response->status();
            throw new Exception("Failed to fetch URL: {$url}. HTTP status: {$status}");
        }

        return $response->body();
    }

    protected function outputCli(array $violations): void
    {
        if (empty($violations)) {
            $this->info('✅ No accessibility issues found!');

            return;
        }

        // Display violations
        foreach ($violations as $category => $issues) {
            $count = count($issues);
            $this->error("❌ {$category} - {$count} issues found:");

            foreach ($issues as $issue) {
                $rule = $issue['rule'] ?? 'BFSG';
                $message = "  - [{$rule}] {$issue['message']}";

                if ($this->option('detailed')) {
                    // Show additional details
                    if (isset($issue['element'])) {
                        $message .= " (Element: {$issue['element']})";
                    }
                    if (isset($issue['src'])) {
                        $message .= " (Source: {$issue['src']})";
                    }
                    if (isset($issue['href'])) {
                        $message .= " (Link: {$issue['href']})";
                    }
                    if (isset($issue['content'])) {
                        $message .= " (Content: {$issue['content']})";
                    }
                }

                $this->line($message);

                if (isset($issue['suggestion'])) {
                    $this->line("    💡 {$issue['suggestion']}");
                }
            }
            $this->newLine();
        }

        // Summary
        $totalIssues = array_sum(array_map('count', $violations));
        $this->warn("Total issues found: {$totalIssues}");
    }

    protected function outputJson(array $violations, string $url): void
    {
        $report = new ReportGenerator($url, $violations);
        $this->line($report->setFormat('json')->generate());
    }

    protected function outputHtml(array $violations, string $url): void
    {
        $report = new ReportGenerator($url, $violations);
        $filename = $report->setFormat('html')->saveToFile();

        $this->info("📄 HTML report saved to: {$filename}");

        // Show quick stats
        $stats = $report->getStats();
        $this->newLine();
        $this->info("Compliance Score: {$stats['compliance_score']}% (Grade: {$stats['grade']})");
        $this->info("Total Issues: {$stats['total_issues']} (Critical: {$stats['critical']}, Errors: {$stats['errors']}, Warnings: {$stats['warnings']})");
    }

    protected function saveResults(string $url, array $violations): void
    {
        $report = new ReportGenerator($url, $violations);
        $stats = $report->getStats();

        $dbReport = \ItsJustVita\LaravelBfsg\Models\BfsgReport::create([
            'url' => $url,
            'total_violations' => $stats['total_issues'],
            'score' => $stats['compliance_score'],
            'grade' => $stats['grade'],
        ]);

        foreach ($violations as $analyzer => $issues) {
            foreach ($issues as $issue) {
                $dbReport->violations()->create([
                    'analyzer' => $analyzer,
                    'severity' => $issue['severity'] ?? 'notice',
                    'message' => $issue['message'],
                    'element' => $issue['element'] ?? null,
                    'wcag_rule' => $issue['rule'] ?? null,
                    'suggestion' => $issue['suggestion'] ?? null,
                ]);
            }
        }

        $this->info("Results saved to database (Report #{$dbReport->id})");
    }

    protected function fetchHtmlFromHerdDomain(string $url): string
    {
        // Find an available port
        $port = $this->findAvailablePort();

        // Get the PHP executable
        $phpFinder = new PhpExecutableFinder;
        $phpBinary = $phpFinder->find();

        if (! $phpBinary) {
            throw new Exception('Could not find PHP binary');
        }

        // Get the project public directory
        $publicPath = base_path('public');

        // Start the PHP server in background
        $serverCommand = [
            $phpBinary,
            '-S',
            "127.0.0.1:{$port}",
            '-t',
            $publicPath,
            base_path('server.php'),
        ];

        $serverProcess = new Process($serverCommand);
        $serverProcess->setTimeout(null);
        $serverProcess->start();

        // Wait a moment for the server to start
        usleep(500000); // 500ms

        try {
            // Parse the original URL to get the path
            $parsedUrl = parse_url($url);
            $path = $parsedUrl['path'] ?? '/';
            $query = isset($parsedUrl['query']) ? '?'.$parsedUrl['query'] : '';
            $fragment = isset($parsedUrl['fragment']) ? '#'.$parsedUrl['fragment'] : '';

            // Build the local server URL
            $localUrl = "http://127.0.0.1:{$port}{$path}{$query}{$fragment}";

            // Fetch the HTML from the local server
            $response = Http::timeout(30)->withUserAgent('BFSG-Checker/2.0')->get($localUrl);

            if ($response->failed()) {
                throw new Exception("Failed to fetch from temporary server: HTTP {$response->status()}");
            }

            return $response->body();

        } finally {
            // Always stop the server - use signal 9 for immediate termination
            $serverProcess->stop(0, 9);
        }
    }

    protected function findAvailablePort(): int
    {
        // Try to find an available port between 8100-8199
        for ($port = 8100; $port <= 8199; $port++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($socket === false) {
                // Port is available
                return $port;
            }
            fclose($socket);
        }

        throw new Exception('Could not find an available port for temporary server');
    }
}
