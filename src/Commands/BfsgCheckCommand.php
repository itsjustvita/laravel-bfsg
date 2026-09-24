<?php

namespace ItsJustVita\LaravelBfsg\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use ItsJustVita\LaravelBfsg\AnalysisResult;
use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Browser\BrowserAnalyzer;
use ItsJustVita\LaravelBfsg\Http\AuthenticatedHttpClient;
use ItsJustVita\LaravelBfsg\Http\FetchOptions;
use ItsJustVita\LaravelBfsg\Http\UrlFetcher;
use ItsJustVita\LaravelBfsg\Persistence\ReportRepository;
use ItsJustVita\LaravelBfsg\Reports\ReportGenerator;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Support\Locale;
use ItsJustVita\LaravelBfsg\Violation;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Checks one page. Exit codes: 0 threshold met, 1 threshold exceeded (--fail-on / --min-score), 2 operational
 * error (unknown or invalid option, option combination that would be ignored, fetch, authentication, browser,
 * database). With --format=json|markdown only the report is written to stdout (also with -q); every status line
 * goes to stderr.
 *
 * Programmatic calls: stderr exists only on a ConsoleOutputInterface. Artisan::call() with the default buffered
 * output (and Artisan::output()) therefore receives the status lines in the same buffer as the report; pass
 * --output=<file> to get the report alone, or an output that implements ConsoleOutputInterface.
 */
class BfsgCheckCommand extends Command
{
    public const OPERATIONAL_ERROR = 2;

    public const FORMATS = ['cli', 'json', 'markdown', 'html', 'pdf'];

    private const FAIL_ON = ['error', 'warning', 'notice', 'none'];

    private const AUTH_OPTIONS = ['auth', 'bearer', 'jwt', 'api-key', 'session', 'sanctum'];

    /** Options only --browser reads (they have defaults, so "given" means present on the command line). */
    private const BROWSER_OPTIONS = ['headless', 'timeout', 'wait-for', 'engine'];

    /** Options only the plain fetch (without --browser) reads. */
    private const FETCH_OPTIONS = ['insecure', 'allow-login-page', 'login-url'];

    /** Options only a login (--auth, --sanctum) reads. --login-url is exempt: it also names the login page to detect. */
    private const LOGIN_OPTIONS = ['email', 'password', 'username-field', 'password-field'];

    protected $signature = 'bfsg:check {url? : URL, or a path of this application (default: /)}
        {--browser : Render the page with Playwright before the analysis}
        {--headless=true : Run the browser headless (true|false)}
        {--timeout=30000 : Browser timeout in milliseconds}
        {--wait-for=body : CSS selector the browser waits for}
        {--engine=chromium : Browser engine: chromium|firefox|webkit}
        {--format=cli : Output format: cli|json|markdown|html|pdf}
        {--output= : Write the report to this file (json, markdown, html, pdf)}
        {--fail-on=error : Exit 1 on findings of this severity or higher: error|warning|notice|none}
        {--min-score= : Exit 1 when the score is below this value (0-100)}
        {--only= : Comma-separated analyzer keys to run}
        {--except= : Comma-separated analyzer keys to skip}
        {--locale= : Locale of messages and reports (default: bfsg.locale, then app.locale)}
        {--detailed : Show element, selector and snippet of every finding}
        {--save : Store the report in the database}
        {--insecure : Do not verify TLS certificates}
        {--no-inline-css : Do not inline same-origin stylesheets (plain fetch and --browser)}
        {--allow-login-page : Analyze the page even when the URL redirected to the login page}
        {--auth : Log in first (--email/--password, or BFSG_AUTH_EMAIL/BFSG_AUTH_PASSWORD, or BFSG_AUTH_TOKEN as bearer token)}
        {--email= : User for --auth}
        {--password= : Password for --auth}
        {--username-field= : Login form field for the user (default: email)}
        {--password-field= : Login form field for the password (default: password)}
        {--login-url= : Login URL, absolute or relative to the checked site (default: bfsg.authentication.default_login_url)}
        {--json-auth : Log in with a JSON request (token or session cookie)}
        {--bearer= : Bearer token}
        {--jwt= : JSON Web Token (sent as bearer token)}
        {--api-key= : API key}
        {--api-key-header=X-API-Key : Header for --api-key}
        {--session= : Existing session cookie as name=value}
        {--sanctum : Log in through Laravel Sanctum (csrf-cookie, then login)}
        {--guard= : Guard used by --as}
        {--as= : For pages of this application: act as the user with this id or email}';

    protected $description = 'Check a page for accessibility (BFSG / WCAG 2.1) and report the findings';

    private string $locale;

    /** Unknown options and missing arguments are operational errors (exit 2), never the threshold exit code 1. */
    public function run(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::run($input, $output);
        } catch (ConsoleException $e) {
            $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $stderr->writeln('<error>'.OutputFormatter::escape($e->getMessage()).'</error>');

            return self::OPERATIONAL_ERROR;
        }
    }

    public function handle(Bfsg $bfsg, UrlFetcher $fetcher, ReportRepository $repository, BrowserAnalyzer $browser): int
    {
        try {
            $format = $this->format();
            $failOn = $this->failOn();
            $minScore = $this->minScore();
            $this->assertOptionCombinations();
            $registry = $this->registry($bfsg);
            // --locale comes from outside and must be valid; config/app locales fall back (Locale::default()).
            $this->locale = $this->option('locale') !== null
                ? Locale::validate((string) $this->option('locale'))
                : Locale::default();

            if ($this->option('save') && ! $repository->isMigrated()) {
                throw new InvalidArgumentException('--save needs the bfsg tables with the v3 columns: run php artisan migrate');
            }

            $url = $fetcher->absolute($this->argument('url') ?? '/');
            // Credentials in the URL are for the request only (UrlFetcher turns them into a Basic header)
            $userInfo = (string) parse_url($url, PHP_URL_USER).(parse_url($url, PHP_URL_PASS) === null ? '' : ':'.parse_url($url, PHP_URL_PASS));
            $this->assertSanctumLoginOrigin($url);
            $this->status($this->trans('cli.checking', ['url' => UrlFetcher::redact($url)]));
            [$html, $url] = $this->option('browser') ? $this->render($browser, $url) : $this->fetch($fetcher, $url);

            $result = $registry->analyze($html, ['url' => $url, 'locale' => $this->locale, 'fragment' => false]);
            $report = new ReportGenerator($result, $this->locale);

            $this->emit($report, $format);

            if ($this->option('save')) {
                $this->status($this->trans('cli.stored', ['id' => $repository->store($result, ['source' => 'bfsg:check'])->id]));
            }

            if ($format !== 'cli') {
                $this->status($this->summaryLine($report));
            }

            return $this->thresholdExceeded($result, $report, $failOn, $minScore) ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $e) {
            $message = ($userInfo ?? '') === '' ? $e->getMessage() : str_replace($userInfo.'@', '', $e->getMessage());
            $this->stderr()->writeln('<error>'.OutputFormatter::escape($message).'</error>');

            return self::OPERATIONAL_ERROR;
        }
    }

    private function format(): string
    {
        $format = strtolower((string) $this->option('format'));

        if (! in_array($format, self::FORMATS, true)) {
            throw new InvalidArgumentException("Unknown --format={$format}. Use one of: ".implode(', ', self::FORMATS).'.');
        }

        if ($format === 'cli' && $this->option('output') !== null) {
            throw new InvalidArgumentException('--output needs --format=json, markdown, html or pdf.');
        }

        return $format;
    }

    private function failOn(): ?Severity
    {
        $failOn = strtolower((string) $this->option('fail-on'));

        if (! in_array($failOn, self::FAIL_ON, true)) {
            throw new InvalidArgumentException("Unknown --fail-on={$failOn}. Use one of: ".implode(', ', self::FAIL_ON).'.');
        }

        return $failOn === 'none' ? null : Severity::from($failOn);
    }

    private function minScore(): ?int
    {
        $minScore = $this->option('min-score');

        if ($minScore === null) {
            return null;
        }

        if (! ctype_digit((string) $minScore) || (int) $minScore > 100) {
            throw new InvalidArgumentException("--min-score must be a whole number from 0 to 100, got [{$minScore}].");
        }

        return (int) $minScore;
    }

    /** Options that would be silently ignored in this combination are an error. */
    private function assertOptionCombinations(): void
    {
        $given = fn (string $option): bool => $this->input->hasParameterOption('--'.$option, true);
        $auth = array_values(array_filter(self::AUTH_OPTIONS, fn (string $option) => (bool) $this->option($option)));

        if ($this->option('browser')) {
            $login = [...$auth, ...($this->option('as') !== null ? ['as'] : [])];

            if ($login !== []) {
                throw new InvalidArgumentException("--browser cannot be combined with --{$login[0]}: the browser does not share the login.");
            }

            foreach (self::FETCH_OPTIONS as $option) {
                if ($given($option)) {
                    throw new InvalidArgumentException("--browser cannot be combined with --{$option}: the browser does not use the options of the plain fetch.");
                }
            }
        } else {
            foreach (self::BROWSER_OPTIONS as $option) {
                if ($given($option)) {
                    throw new InvalidArgumentException("--{$option} needs --browser.");
                }
            }
        }

        if ($this->option('as') !== null && $auth !== []) {
            throw new InvalidArgumentException("--as cannot be combined with --{$auth[0]}: --as acts as the user in-process, the auth options fetch over HTTP.");
        }

        if ($this->option('guard') !== null && $this->option('as') === null) {
            throw new InvalidArgumentException('--guard needs --as.');
        }

        if (! $this->option('auth') && ! $this->option('sanctum')) {
            foreach (self::LOGIN_OPTIONS as $option) {
                if ($this->option($option) !== null) {
                    throw new InvalidArgumentException("--{$option} needs --auth or --sanctum.");
                }
            }
        }

        if ($this->option('json-auth') && ! $this->option('auth')) {
            throw new InvalidArgumentException('--json-auth needs --auth.');
        }

        if ($given('api-key-header') && ! $this->option('api-key')) {
            throw new InvalidArgumentException('--api-key-header needs --api-key.');
        }
    }

    /** The registry narrowed by --only / --except; unknown keys are an error. */
    private function registry(Bfsg $bfsg): Bfsg
    {
        $registry = $bfsg->except([]);

        foreach (['only', 'except'] as $option) {
            $keys = array_values(array_filter(array_map('trim', explode(',', (string) $this->option($option)))));

            if ($keys === []) {
                continue;
            }

            $unknown = array_diff($keys, $bfsg->keys());

            if ($unknown !== []) {
                throw new InvalidArgumentException("Unknown analyzer in --{$option}: ".implode(', ', $unknown).'. Available: '.implode(', ', $bfsg->keys()).'.');
            }

            $registry = $option === 'only' ? $registry->only($keys) : $registry->except($keys);

            if ($registry->keys() === []) {
                throw new InvalidArgumentException('--only and --except leave no analyzer to run.');
            }
        }

        return $registry;
    }

    /** @return array{0: string, 1: string} HTML and final URL */
    private function fetch(UrlFetcher $fetcher, string $url): array
    {
        $remoteAuth = array_filter(self::AUTH_OPTIONS, fn (string $option) => (bool) $this->option($option)) !== [];
        $user = $this->actingAs($fetcher, $url);

        $page = $fetcher->fetch($url, new FetchOptions(
            client: $remoteAuth ? $this->authenticatedClient($url) : new AuthenticatedHttpClient(verifySsl: $this->option('insecure') ? false : null),
            actingAs: $user,
            guard: $this->option('guard'),
            inlineStylesheets: $this->option('no-inline-css') ? false : null,
            loginUrl: $this->option('login-url'),
            inProcess: ! $remoteAuth,
        ));

        foreach ($page->warnings as $warning) {
            $this->status($this->trans('cli.warning', ['message' => $warning]));
        }

        if ($page->redirected) {
            $this->status($this->trans('cli.redirected', ['url' => $page->finalUrl]));
        }

        if ($page->landedOnLogin && ! $this->option('allow-login-page')) {
            throw new InvalidArgumentException(UrlFetcher::redact($url)." redirected to the login page {$page->finalUrl}. Authenticate (--as, --auth, --bearer, --session) or pass --allow-login-page to check the login page.");
        }

        return [$page->html, $page->finalUrl];
    }

    /** @return array{0: string, 1: string} */
    private function render(BrowserAnalyzer $browser, string $url): array
    {
        $headless = filter_var($this->option('headless'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        $timeout = (string) $this->option('timeout');

        if ($headless === null || ! ctype_digit($timeout) || (int) $timeout === 0) {
            throw new InvalidArgumentException('--headless must be true or false and --timeout a positive number of milliseconds.');
        }

        $this->status($this->trans('cli.rendering', ['url' => UrlFetcher::redact($url), 'engine' => $this->option('engine')]));

        $html = $browser->render($url, [
            'headless' => $headless,
            'timeout' => (int) $timeout,
            'waitFor' => (string) $this->option('wait-for'),
            'engine' => (string) $this->option('engine'),
            'inlineStylesheets' => $this->option('no-inline-css') ? false : null,
        ]);

        foreach ($browser->warnings() as $warning) {
            $this->status($this->trans('cli.warning', ['message' => $warning]));
        }

        return [$html, UrlFetcher::redact($url)];
    }

    private function actingAs(UrlFetcher $fetcher, string $url): ?Authenticatable
    {
        $value = $this->option('as');

        if ($value === null) {
            return null;
        }

        if (! $fetcher->isSameApp($url)) {
            throw new InvalidArgumentException('--as only works for pages of this application (a path or the host of app.url).');
        }

        $guard = Auth::guard($this->option('guard'));

        if (! method_exists($guard, 'getProvider')) {
            throw new InvalidArgumentException('The guard ['.($this->option('guard') ?? 'default').'] has no user provider for --as.');
        }

        $provider = $guard->getProvider();
        $user = ctype_digit((string) $value) ? $provider->retrieveById($value) : null;
        $user ??= $provider->retrieveByCredentials(['email' => $value]);

        if (! $user instanceof Authenticatable) {
            throw new InvalidArgumentException("No user found for --as={$value}.");
        }

        return $user;
    }

    /**
     * A client carrying the requested authentication (tokens, cookies, or a login performed now). Credentials in the URL
     * become the Basic header of the page's origin before the login, so a login behind HTTP basic auth (a protected
     * staging site) sends them too. There is only one Authorization header: an option that needs it as well is an error.
     */
    private function authenticatedClient(string $url): AuthenticatedHttpClient
    {
        $client = new AuthenticatedHttpClient(verifySsl: $this->option('insecure') ? false : null);
        $origin = $this->origin($url);
        $basic = UrlFetcher::basicAuthorization($url);

        if ($basic !== null) {
            $this->assertNoAuthorizationOption();
            $client->withHeaders(['Authorization' => $basic], $origin);
        }

        if ($token = $this->option('jwt')) {
            $client->withJwt($token, $origin);
        }

        if ($token = $this->option('bearer')) {
            $client->withBearer($token, $origin);
        }

        if ($key = $this->option('api-key')) {
            $client->withApiKey($key, (string) $this->option('api-key-header'), $origin);
        }

        if ($session = $this->option('session')) {
            if (! str_contains($session, '=')) {
                throw new InvalidArgumentException('--session must look like name=value.');
            }

            [$name, $value] = explode('=', $session, 2);
            $client->withSessionCookie(trim($name), trim($value), $url);
        }

        if ($this->option('auth') || $this->option('sanctum')) {
            $this->login($client, $origin);
        }

        if ($basic !== null && ($client->headers()['Authorization'] ?? null) !== $basic) {
            throw new InvalidArgumentException('The authentication produced a bearer token (the answer of a JSON or Sanctum login, or BFSG_AUTH_TOKEN), which would replace the credentials in the URL: both use the Authorization header. Use a form login (--auth) or --session with credentials in the URL.');
        }

        return $client;
    }

    /** Credentials in the URL are the Authorization header; --bearer, --jwt and --api-key-header=Authorization would be too. */
    private function assertNoAuthorizationOption(): void
    {
        foreach (['bearer', 'jwt'] as $option) {
            if ($this->option($option)) {
                throw new InvalidArgumentException("Credentials in the URL cannot be combined with --{$option}: both use the Authorization header.");
            }
        }

        if ($this->option('api-key') && strcasecmp(trim((string) $this->option('api-key-header')), 'Authorization') === 0) {
            throw new InvalidArgumentException('Credentials in the URL cannot be combined with --api-key-header=Authorization: both use the Authorization header.');
        }
    }

    private function login(AuthenticatedHttpClient $client, string $origin): void
    {
        $env = AuthenticatedHttpClient::credentialsFromEnv();
        $email = $this->option('email') ?? $env['email'];
        $password = $this->option('password') ?? $env['password'];

        if (($email === null || $password === null) && $env['token'] !== null && ! $this->option('sanctum')) {
            $client->withBearer($env['token'], $origin);

            return;
        }

        if ($this->input->isInteractive()) {
            $email ??= $this->ask('Email');
            $password ??= $this->secret('Password');
        }

        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            throw new InvalidArgumentException('--auth needs --email and --password (or BFSG_AUTH_EMAIL and BFSG_AUTH_PASSWORD, or BFSG_AUTH_TOKEN).');
        }

        $fieldNames = array_filter(['username' => $this->option('username-field'), 'password' => $this->option('password-field')]);
        $loginUrl = $this->loginUrl($origin);

        match (true) {
            (bool) $this->option('sanctum') => $client->loginWithSanctum($origin, $email, $password, (string) parse_url($loginUrl, PHP_URL_PATH), $fieldNames),
            (bool) $this->option('json-auth') => $client->loginWithJson($loginUrl, $email, $password, [], $fieldNames),
            default => $client->loginWithForm($loginUrl, $email, $password, [], $fieldNames),
        };
    }

    /** --sanctum only uses the path of the login URL; one on another origin would be silently ignored, so it is an error. */
    private function assertSanctumLoginOrigin(string $url): void
    {
        if (! $this->option('sanctum')) {
            return;
        }

        $origin = $this->origin($url);
        $loginUrl = $this->loginUrl($origin);

        if (AuthenticatedHttpClient::origin($loginUrl) !== AuthenticatedHttpClient::origin($origin)) {
            throw new InvalidArgumentException("--sanctum logs in on the origin of the checked page ({$origin}), but the login URL ".UrlFetcher::redact($loginUrl).' is on another origin. Pass a path or a URL on that origin as --login-url.');
        }
    }

    private function loginUrl(string $origin): string
    {
        $loginUrl = (string) ($this->option('login-url') ?: config('bfsg.authentication.default_login_url', '/login'));

        return preg_match('#^https?://#i', $loginUrl) === 1 ? $loginUrl : $origin.'/'.ltrim($loginUrl, '/');
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private function emit(ReportGenerator $report, string $format): void
    {
        if ($format === 'cli') {
            $this->printCli($report);

            return;
        }

        $report->format($format);
        $output = $this->option('output');

        if ($output === null && in_array($format, ['json', 'markdown'], true)) {
            // VERBOSITY_QUIET: -q silences the status lines, never the report itself.
            $this->output->write($report->render(), false, OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET);

            return;
        }

        $this->status($this->trans('cli.report_written', ['path' => $report->saveTo($output ?? $report->defaultPath())]));
    }

    private function printCli(ReportGenerator $report): void
    {
        $result = $report->result();

        if ($result->count() === 0) {
            $this->line('<info>'.OutputFormatter::escape($this->trans('no_issues')).'</info>');
        }

        foreach ($result->byAnalyzer() as $analyzer => $violations) {
            $this->line('<options=bold>'.OutputFormatter::escape($analyzer).'</> ('.count($violations).')');

            foreach ($violations as $violation) {
                $this->printViolation($violation);
            }

            $this->newLine();
        }

        $this->line(OutputFormatter::escape($this->summaryLine($report)));
    }

    private function printViolation(Violation $violation): void
    {
        $style = match ($violation->severity) {
            Severity::Error => 'fg=red',
            Severity::Warning => 'fg=yellow',
            Severity::Notice => 'fg=cyan',
        };
        $rule = $violation->rule === null ? $this->trans('no_rule') : $this->trans('rule').' '.$violation->rule;
        $escape = fn (?string $text): string => OutputFormatter::escape((string) $text);

        $this->line("  <{$style}>[".$escape($violation->severity->label($this->locale)).']</> '.$escape($rule).' '.$escape($violation->message($this->locale)));
        $this->line('      '.$escape($this->trans('suggestion').': '.$violation->suggestion($this->locale)));

        if ($this->option('detailed')) {
            if ($violation->element !== null) {
                $this->line('      '.$escape($this->trans('element').': '.$violation->element.'  '.$violation->selector));
            }

            if ($violation->snippet !== null) {
                $this->line('      '.$escape($violation->snippet));
            }
        }
    }

    private function summaryLine(ReportGenerator $report): string
    {
        return $this->trans('cli.summary', $report->summary());
    }

    private function thresholdExceeded(AnalysisResult $result, ReportGenerator $report, ?Severity $failOn, ?int $minScore): bool
    {
        if ($failOn !== null) {
            foreach ($result->all() as $violation) {
                if ($violation->severity->atLeast($failOn)) {
                    $this->status($this->trans('cli.fail_on', ['severity' => $failOn->label($this->locale)]));

                    return true;
                }
            }
        }

        if ($minScore !== null && $report->score() < $minScore) {
            $this->status($this->trans('cli.min_score', ['score' => $report->score(), 'min' => $minScore]));

            return true;
        }

        return false;
    }

    /** @param  array<string, mixed>  $replace */
    private function trans(string $key, array $replace = []): string
    {
        return (string) __('bfsg::report.'.$key, array_map(fn ($value) => is_bool($value) ? ($value ? 'true' : 'false') : $value, $replace), $this->locale ?? null);
    }

    private function status(string $message): void
    {
        $this->stderr()->writeln('<comment>'.OutputFormatter::escape($message).'</comment>');
    }

    /** stderr of a console output; the output itself otherwise (tests with a buffered output). */
    private function stderr(): OutputInterface
    {
        $output = $this->output->getOutput();

        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }
}
