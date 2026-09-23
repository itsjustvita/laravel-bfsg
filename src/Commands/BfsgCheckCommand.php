<?php

namespace ItsJustVita\LaravelBfsg\Commands;

use Exception;
use Illuminate\Console\Command;
use ItsJustVita\LaravelBfsg\AnalysisResult;
use ItsJustVita\LaravelBfsg\Facades\Bfsg;
use ItsJustVita\LaravelBfsg\Http\AuthenticatedHttpClient;
use ItsJustVita\LaravelBfsg\Http\FetchOptions;
use ItsJustVita\LaravelBfsg\Http\UrlFetcher;
use ItsJustVita\LaravelBfsg\Persistence\ReportRepository;
use ItsJustVita\LaravelBfsg\Reports\ReportGenerator;
use Symfony\Component\Console\Output\OutputInterface;

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
                            {--format=cli : Output format (cli, json, html, pdf)}
                            {--verify-ssl=false : Verify SSL certificates (set to true for production)}';

    protected $description = 'Check a URL for accessibility compliance';

    protected AuthenticatedHttpClient $httpClient;

    public function handle()
    {
        $url = $this->argument('url') ?? '/';

        $this->info("🔍 Checking accessibility for: {$url}");
        $this->newLine();

        try {
            $this->httpClient = (new AuthenticatedHttpClient)->withVerifySsl($this->verifySsl());

            // Handle authentication if needed
            if ($this->usesAuthentication()) {
                $this->handleAuthentication(app(UrlFetcher::class)->absolute($url));
            }

            // Fetch the page (in-process for URLs of this application) and analyze it as a full document
            $page = app(UrlFetcher::class)->fetch($url, new FetchOptions(client: $this->httpClient, loginUrl: $this->option('login-url')));

            if ($page->landedOnLogin) {
                throw new Exception("{$url} redirected to the login page {$page->finalUrl}; authenticate first.");
            }

            $url = $page->finalUrl;
            $result = Bfsg::analyze($page->html, ['url' => $url, 'fragment' => false]);
            $violations = $result->toArray()['violations'];

            // Handle output based on format
            $format = $this->option('format');
            if (in_array($format, ['json', 'markdown', 'html', 'pdf'], true)) {
                $this->outputReport(new ReportGenerator($result), $format);
            } else {
                $this->outputCli($violations);
            }

            // Save to database if requested
            if ($this->option('save')) {
                $this->saveResults($result);
            }

            return $result->count() === 0 ? Command::SUCCESS : Command::FAILURE;

        } catch (Exception $e) {
            $this->error('❌ Error: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Whether any authentication option was given
     */
    protected function usesAuthentication(): bool
    {
        foreach (['auth', 'bearer', 'jwt', 'api-key', 'session'] as $option) {
            if ($this->option($option)) {
                return true;
            }
        }

        return false;
    }

    protected function verifySsl(): bool
    {
        return filter_var($this->option('verify-ssl'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * scheme://host[:port] of the URL being checked
     */
    protected function originOf(string $url): string
    {
        $parts = parse_url($url);

        if (empty($parts['scheme']) || empty($parts['host'])) {
            throw new Exception("Invalid URL: {$url}");
        }

        $origin = $parts['scheme'].'://'.$parts['host'];

        if (! empty($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }

    /**
     * Resolve --login-url (absolute or relative to the origin), falling back to the configured default
     */
    protected function resolveLoginUrl(string $origin): string
    {
        $loginUrl = $this->option('login-url') ?: config('bfsg.authentication.default_login_url', '/login');

        if (preg_match('#^https?://#i', $loginUrl)) {
            return $loginUrl;
        }

        return $origin.'/'.ltrim($loginUrl, '/');
    }

    protected function handleAuthentication(string $url): void
    {
        $origin = $this->originOf($url);

        if ($jwt = $this->option('jwt')) {
            $this->httpClient->withJwt($jwt);

            return;
        }

        if ($bearer = $this->option('bearer')) {
            $this->httpClient->withBearer($bearer);

            return;
        }

        if ($apiKey = $this->option('api-key')) {
            $this->httpClient->withApiKey($apiKey, $this->option('api-key-header') ?? 'X-API-Key');

            return;
        }

        if ($session = $this->option('session')) {
            if (strpos($session, '=') === false) {
                throw new Exception('Session format must be: name=value');
            }

            [$name, $value] = explode('=', $session, 2);
            $this->httpClient->withSessionCookie($name, $value, $url);

            return;
        }

        if ($this->option('auth')) {
            $email = $this->option('email') ?? $this->ask('Email');
            $password = $this->option('password') ?? $this->secret('Password');

            if (! $email || ! $password) {
                throw new Exception('Email and password are required for authentication');
            }

            $fieldNames = array_filter(['username' => $this->option('username-field'), 'password' => $this->option('password-field')]);

            if ($this->option('sanctum')) {
                $this->httpClient->loginWithSanctum($origin, $email, $password, fieldNames: $fieldNames);
            } elseif ($this->option('json-auth')) {
                $this->httpClient->loginWithJson($this->resolveLoginUrl($origin), $email, $password, fieldNames: $fieldNames);
            } else {
                $this->httpClient->loginWithForm($this->resolveLoginUrl($origin), $email, $password, fieldNames: $fieldNames);
            }

            $this->info('✅ Authentication successful');
        }
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
                    if (! empty($issue['snippet'])) {
                        $message .= " ({$issue['snippet']})";
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

    protected function outputReport(ReportGenerator $report, string $format): void
    {
        $report->format($format);

        if (in_array($format, ['json', 'markdown'], true)) {
            $this->output->write($report->render(), false, OutputInterface::OUTPUT_RAW);

            return;
        }

        $path = $report->saveTo($report->defaultPath());
        $summary = $report->summary();

        $this->info("Report saved to: {$path}");
        $this->info("Compliance Score: {$summary['score']}% (Grade: {$summary['grade']})");
    }

    protected function saveResults(AnalysisResult $result): void
    {
        $dbReport = app(ReportRepository::class)->store($result);

        $this->info("Results saved to database (Report #{$dbReport->id})");
    }
}
