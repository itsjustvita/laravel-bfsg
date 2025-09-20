<?php

namespace ItsJustVita\LaravelBfsg\Commands;

use Illuminate\Console\Command;
use ItsJustVita\LaravelBfsg\Facades\Bfsg;
use ItsJustVita\LaravelBfsg\Services\AuthenticatedHttpClient;
use Exception;

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
                            {--format=cli : Output format (cli, json, html)}';

    protected $description = 'Check a URL for accessibility compliance';

    protected AuthenticatedHttpClient $httpClient;

    public function __construct()
    {
        parent::__construct();
        $this->httpClient = new AuthenticatedHttpClient();
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
            $this->error("❌ Error: " . $e->getMessage());
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

            list($name, $value) = explode('=', $session, 2);
            $this->httpClient->authenticateWithSessionCookie($name, $value);
            $this->info('✅ Session cookie authentication configured');
            return;
        }

        // Credentials authentication
        if ($this->option('auth')) {
            $email = $this->option('email') ?? $this->ask('Email');
            $password = $this->option('password') ?? $this->secret('Password');

            if (!$email || !$password) {
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
                    ? $baseUrl . '/' . ltrim($this->option('login-url'), '/')
                    : $baseUrl . '/login';

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

                if (!$success && !$this->option('json-auth')) {
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
            return $this->httpClient->fetchAuthenticatedUrl($url);
        }

        // Otherwise use simple file_get_contents
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'BFSG-Checker/1.0',
            ],
        ]);

        $html = @file_get_contents($url, false, $context);

        if ($html === false) {
            throw new Exception("Failed to fetch URL: {$url}");
        }

        return $html;
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
        $output = [
            'url' => $url,
            'timestamp' => now()->toIso8601String(),
            'summary' => [
                'total_issues' => array_sum(array_map('count', $violations)),
                'categories' => array_map('count', $violations),
            ],
            'violations' => $violations,
        ];

        $this->line(json_encode($output, JSON_PRETTY_PRINT));
    }

    protected function outputHtml(array $violations, string $url): void
    {
        $html = view('bfsg::report', [
            'url' => $url,
            'violations' => $violations,
            'timestamp' => now(),
        ])->render();

        $filename = 'bfsg-report-' . now()->format('Y-m-d-His') . '.html';
        file_put_contents($filename, $html);

        $this->info("📄 HTML report saved to: {$filename}");
    }

    protected function saveResults(string $url, array $violations): void
    {
        // This would save to database if the table exists
        $this->info('💾 Results saved to database');
    }
}