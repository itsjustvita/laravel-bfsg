<?php

namespace ItsJustVita\LaravelBfsg\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Models\BfsgReport;
use ItsJustVita\LaravelBfsg\Reports\ReportGenerator;
use ItsJustVita\LaravelBfsg\Tests\Support\CapturedOutput;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class CommandsTest extends TestCase
{
    use RefreshDatabase;

    /** Zero findings of any severity (CleanPageBaselineTest 'commands'). */
    private const ACCESSIBLE = '<!DOCTYPE html><html lang="en"><head><title>Test Page - Company</title></head><body>'
        .'<header><nav><a href="#main">Skip to content</a></nav></header>'
        .'<main id="main"><h1>Welcome</h1>'
        .'<img src="photo.jpg" alt="A descriptive alt text">'
        .'<form aria-label="Contact"><label for="email">Email</label>'
        .'<input type="email" id="email" name="email" autocomplete="email"></form>'
        .'<a href="/about">Learn more about our company</a>'
        .'<div aria-live="polite"></div>'
        .'</main><footer><p>Footer content</p></footer>'
        .'</body></html>';

    private const ERRORS = '<!DOCTYPE html><html><body><img src="test.jpg"></body></html>';

    private static function page(string $extra): string
    {
        return str_replace('<div aria-live="polite"></div>', '<div aria-live="polite"></div>'.$extra, self::ACCESSIBLE);
    }

    /** @return array{0: int, 1: CapturedOutput} */
    private function check(array $parameters): array
    {
        $output = new CapturedOutput;

        return [Artisan::call('bfsg:check', $parameters, $output), $output];
    }

    private function fakeSite(string $html = self::ACCESSIBLE): void
    {
        Http::fake(['http://example.com/*' => Http::response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8'])]);
    }

    public function test_commands_are_registered_and_bfsg_analyze_is_gone(): void
    {
        $commands = array_keys(Artisan::all());

        $this->assertContains('bfsg:check', $commands);
        $this->assertContains('bfsg:history', $commands);
        $this->assertNotContains('bfsg:analyze', $commands);
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: int}> */
    public static function exitCodes(): array
    {
        $notice = self::page('<a href="https://partner.example/" target="_blank" rel="noopener">Partner site of our company</a>');
        $warning = self::page('<div tabindex="2">Panel</div>');

        return [
            'clean page' => [self::ACCESSIBLE, [], 0],
            'errors fail by default' => [self::ERRORS, [], 1],
            'notices pass by default' => [$notice, [], 0],
            'notices fail with --fail-on=notice' => [$notice, ['--fail-on' => 'notice'], 1],
            'warnings pass with --fail-on=error' => [$warning, [], 0],
            'warnings fail with --fail-on=warning' => [$warning, ['--fail-on' => 'warning'], 1],
            'errors pass with --fail-on=none' => [self::ERRORS, ['--fail-on' => 'none'], 0],
            'score below --min-score' => [$warning, ['--min-score' => '99'], 1],
            'score at --min-score' => [$warning, ['--min-score' => '98'], 0],
            'unknown format' => [self::ACCESSIBLE, ['--format' => 'xml'], 2],
            'unknown fail-on' => [self::ACCESSIBLE, ['--fail-on' => 'critical'], 2],
            'min-score not a number' => [self::ACCESSIBLE, ['--min-score' => 'abc'], 2],
            'min-score above 100' => [self::ACCESSIBLE, ['--min-score' => '101'], 2],
            'unknown analyzer in --only' => [self::ACCESSIBLE, ['--only' => 'images,nope'], 2],
            'unknown analyzer in --except' => [self::ACCESSIBLE, ['--except' => 'nope'], 2],
            '--output with cli' => [self::ACCESSIBLE, ['--output' => '/tmp/x.txt'], 2],
            'score alone fails with --fail-on=none' => [self::ERRORS, ['--fail-on' => 'none', '--min-score' => '100'], 1],
            'unknown option' => [self::ACCESSIBLE, ['--failon' => 'warning'], 2],
            'empty analyzer selection' => [self::ACCESSIBLE, ['--only' => 'images', '--except' => 'images'], 2],
            'malformed locale' => [self::ACCESSIBLE, ['--locale' => '../../tmp/zz'], 2],
            'locale without translations' => [self::ACCESSIBLE, ['--locale' => 'xx'], 2],
            '--as with --bearer' => [self::ACCESSIBLE, ['--as' => '7', '--bearer' => 'token'], 2],
            '--as with --auth' => [self::ACCESSIBLE, ['--as' => '7', '--auth' => true, '--email' => 'a@example.com', '--password' => 'p'], 2],
            '--as with --session' => [self::ACCESSIBLE, ['--as' => '7', '--session' => 'a=b'], 2],
            '--engine without --browser' => [self::ACCESSIBLE, ['--engine' => 'firefox'], 2],
            '--timeout without --browser' => [self::ACCESSIBLE, ['--timeout' => '5000'], 2],
            '--wait-for without --browser' => [self::ACCESSIBLE, ['--wait-for' => 'main'], 2],
            '--headless without --browser' => [self::ACCESSIBLE, ['--headless' => 'false'], 2],
            '--email without --auth' => [self::ACCESSIBLE, ['--email' => 'a@example.com'], 2],
            '--password without --auth' => [self::ACCESSIBLE, ['--password' => 'p'], 2],
            '--username-field without --auth' => [self::ACCESSIBLE, ['--username-field' => 'login'], 2],
            '--json-auth without --auth' => [self::ACCESSIBLE, ['--json-auth' => true], 2],
            '--api-key-header without --api-key' => [self::ACCESSIBLE, ['--api-key-header' => 'X-Key'], 2],
            '--guard without --as' => [self::ACCESSIBLE, ['--guard' => 'web'], 2],
        ];
    }

    #[DataProvider('exitCodes')]
    public function test_exit_codes(string $html, array $options, int $expected): void
    {
        $this->fakeSite($html);

        [$exitCode, $output] = $this->check(['url' => 'http://example.com/page', ...$options]);

        $this->assertSame($expected, $exitCode, $output->stdout().$output->stderr());
    }

    public function test_operational_errors_exit_2_with_the_reason_on_stderr(): void
    {
        Http::fake(function (Request $request) {
            return match (parse_url($request->url(), PHP_URL_PATH)) {
                '/500' => Http::response('Server Error', 500),
                '/api' => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
                default => throw new ConnectionException('Connection refused'),
            };
        });

        foreach (['http://example.com/500' => 'HTTP 500', 'http://example.com/api' => 'not an HTML page', 'http://down.example.com/' => 'Connection refused', 'ftp://example.com/' => 'Invalid URL'] as $url => $reason) {
            [$exitCode, $output] = $this->check(['url' => $url]);

            $this->assertSame(2, $exitCode, $url);
            $this->assertStringContainsString($reason, $output->stderr(), $url);
            $this->assertSame('', $output->stdout(), $url);
        }
    }

    public function test_json_stdout_is_only_the_report(): void
    {
        $this->fakeSite(self::ERRORS);

        [$exitCode, $output] = $this->check(['url' => 'http://example.com/page', '--format' => 'json']);

        $report = json_decode($output->stdout(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $exitCode);
        $this->assertSame('http://example.com/page', $report['url']);
        $this->assertSame('images.missing_alt', $report['violations']['images'][0]['key']);
        $this->assertStringContainsString('Checking http://example.com/page', $output->stderr());
        $this->assertStringContainsString('score', $output->stderr());
    }

    public function test_markdown_stdout_is_only_the_report(): void
    {
        $this->fakeSite(self::ERRORS);

        [, $output] = $this->check(['url' => 'http://example.com/page', '--format' => 'markdown']);

        $result = app(Bfsg::class)->analyze(self::ERRORS, ['url' => 'http://example.com/page', 'locale' => 'en', 'fragment' => false]);
        $expected = (new ReportGenerator($result, 'en'))->format('markdown')->render();
        $withoutDates = fn (string $text) => preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', 'DATE', $text);

        $this->assertStringStartsWith("# Accessibility Report\n", $output->stdout());
        $this->assertSame($withoutDates($expected), $withoutDates($output->stdout()), 'stdout is exactly the report');
        $this->assertStringContainsString('Checking', $output->stderr());
    }

    public function test_unknown_options_exit_2_with_the_reason_on_stderr(): void
    {
        $this->fakeSite(self::ERRORS);

        [$exitCode, $output] = $this->check(['url' => 'http://example.com/page', '--format' => 'json', '--min_score' => '90']);

        $this->assertSame(2, $exitCode, 'a typo is an operational error, never the threshold exit code 1');
        $this->assertSame('', $output->stdout());
        $this->assertStringContainsString('The "--min_score" option does not exist.', $output->stderr());
        Http::assertNothingSent();
    }

    public function test_ignored_option_combinations_are_rejected_with_a_reason(): void
    {
        $this->fakeSite();

        $cases = [
            'the auth options fetch over HTTP' => ['--as' => '7', '--bearer' => 'token'],
            '--engine needs --browser.' => ['--engine' => 'firefox'],
            '--email needs --auth or --sanctum.' => ['--email' => 'a@example.com'],
            '--api-key-header needs --api-key.' => ['--api-key-header' => 'X-Key'],
            '--guard needs --as.' => ['--guard' => 'web'],
            'Unsupported locale [xx]' => ['--locale' => 'xx'],
            '--only and --except leave no analyzer to run.' => ['--only' => 'images', '--except' => 'images'],
        ];

        foreach ($cases as $reason => $options) {
            [$exitCode, $output] = $this->check(['url' => 'http://example.com/page', ...$options]);

            $this->assertSame(2, $exitCode, $reason);
            $this->assertStringContainsString($reason, $output->stderr());
            $this->assertSame('', $output->stdout(), $reason);
        }

        Http::assertNothingSent();
    }

    public function test_quiet_silences_status_lines_but_not_the_report(): void
    {
        $this->fakeSite(self::ERRORS);

        [$exitCode, $output] = $this->check(['url' => 'http://example.com/page', '--format' => 'json', '--quiet' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('http://example.com/page', json_decode($output->stdout(), true, flags: JSON_THROW_ON_ERROR)['url']);
    }

    public function test_html_and_pdf_are_written_to_files_and_the_path_goes_to_stderr(): void
    {
        $this->fakeSite(self::ERRORS);
        $directory = sys_get_temp_dir().'/bfsg-cli-'.uniqid();
        config()->set('bfsg.reporting.output_path', $directory);

        try {
            [, $html] = $this->check(['url' => 'http://example.com/page', '--format' => 'html']);
            [, $pdf] = $this->check(['url' => 'http://example.com/page', '--format' => 'pdf', '--output' => $directory.'/custom.pdf']);
            [, $json] = $this->check(['url' => 'http://example.com/page', '--format' => 'json', '--output' => $directory.'/report.json']);

            $this->assertSame('', $html->stdout());
            $this->assertMatchesRegularExpression('#Report written to '.preg_quote($directory, '#').'/report_[0-9_-]+_[0-9a-f]{6}\.html#', $html->stderr());
            $this->assertCount(1, glob($directory.'/report_*.html'));
            $this->assertStringStartsWith('%PDF', (string) file_get_contents($directory.'/custom.pdf'));
            $this->assertStringContainsString('Report written to '.$directory.'/custom.pdf', $pdf->stderr());
            $this->assertSame('', $json->stdout());
            $this->assertSame('http://example.com/page', json_decode((string) file_get_contents($directory.'/report.json'), true)['url']);
        } finally {
            array_map('unlink', glob($directory.'/*'));
            rmdir($directory);
        }
    }

    public function test_cli_output_is_localized_and_detailed(): void
    {
        $this->fakeSite(self::ERRORS);

        [$exitCode, $output] = $this->check(['url' => 'http://example.com/page', '--locale' => 'de', '--detailed' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('[Fehler] WCAG 1.1.1 Bild ohne Textalternative (test.jpg)', $output->stdout());
        $this->assertStringContainsString('Element: img  /html[1]/body[1]/img[1]', $output->stdout());
        $this->assertStringContainsString('<img src="test.jpg">', $output->stdout());
        $this->assertStringContainsString('Befunde (', $output->stdout());
        $this->assertStringContainsString('Prüfe http://example.com/page', $output->stderr());
    }

    public function test_clean_pages_say_so(): void
    {
        $this->fakeSite();

        [, $output] = $this->check(['url' => 'http://example.com/page']);

        $this->assertStringContainsString('No accessibility issues found.', $output->stdout());
    }

    public function test_only_and_except_select_analyzers(): void
    {
        $this->fakeSite(self::ERRORS);

        [, $only] = $this->check(['url' => 'http://example.com/page', '--format' => 'json', '--only' => 'images, language']);
        [, $except] = $this->check(['url' => 'http://example.com/page', '--format' => 'json', '--except' => 'images']);

        $this->assertSame(['images', 'language'], json_decode($only->stdout(), true)['analyzers']);
        $this->assertNotContains('images', json_decode($except->stdout(), true)['analyzers']);
        $this->assertArrayNotHasKey('images', json_decode($except->stdout(), true)['violations']);
    }

    public function test_locale_option_sets_messages_and_report_locale(): void
    {
        $this->fakeSite(self::ERRORS);

        [, $output] = $this->check(['url' => 'http://example.com/page', '--format' => 'json', '--locale' => 'de']);
        $report = json_decode($output->stdout(), true);

        $this->assertSame('de', $report['locale']);
        $this->assertSame('Bild ohne Textalternative (test.jpg)', $report['violations']['images'][0]['message']);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> app.locale, app.fallback_locale, expected report locale */
    public static function appLocales(): array
    {
        return [
            'region and script' => ['zh_Hans_CN', 'en', 'en'],
            'encoding suffix' => ['en_US.UTF-8', 'en', 'en'],
            'fallback locale used' => ['zh_Hans_CN', 'de', 'de'],
        ];
    }

    #[DataProvider('appLocales')]
    public function test_app_locales_without_translations_fall_back_instead_of_failing(string $appLocale, string $fallback, string $expected): void
    {
        Http::fake([
            'http://example.com/errors' => Http::response(self::ERRORS, 200, ['Content-Type' => 'text/html']),
            'http://example.com/clean' => Http::response(self::ACCESSIBLE, 200, ['Content-Type' => 'text/html']),
        ]);
        config()->set('app.locale', $appLocale);
        config()->set('app.fallback_locale', $fallback);
        $this->app->setLocale($appLocale);

        [$errors, $output] = $this->check(['url' => 'http://example.com/errors', '--format' => 'json']);
        [$clean, $cleanOutput] = $this->check(['url' => 'http://example.com/clean']);

        $this->assertSame(1, $errors, $output->stderr());
        $this->assertSame($expected, json_decode($output->stdout(), true)['locale']);
        $this->assertSame(0, $clean, $cleanOutput->stdout().$cleanOutput->stderr());
    }

    public function test_insecure_and_no_inline_css(): void
    {
        $options = [];
        Http::fake(function (Request $request, array $requestOptions) use (&$options) {
            $options[$request->url()] = $requestOptions['verify'] ?? null;

            return Http::response('<html lang="en"><head><title>Styled page</title><link rel="stylesheet" href="/app.css"></head><body><main><h1>Hi</h1></main></body></html>', 200);
        });

        $this->check(['url' => 'https://self-signed.example.com/', '--insecure' => true, '--no-inline-css' => true]);

        $this->assertSame(['https://self-signed.example.com/' => false], $options, 'TLS not verified, stylesheet not fetched');
    }

    public function test_paths_of_this_application_are_checked_in_process(): void
    {
        $this->app['router']->get('/bfsg-inprocess', fn () => response(self::ERRORS));

        [$exitCode, $output] = $this->check(['url' => '/bfsg-inprocess', '--format' => 'json']);

        $this->assertSame(1, $exitCode);
        $this->assertSame('http://localhost/bfsg-inprocess', json_decode($output->stdout(), true)['url']);
        Http::assertNothingSent();
    }

    public function test_as_acts_as_a_user_of_this_application(): void
    {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('email');
            $table->string('password');
        });
        DB::table('users')->insert(['id' => 7, 'email' => 'jane@example.com', 'password' => Hash::make('secret')]);
        config()->set('auth.providers.users', ['driver' => 'database', 'table' => 'users']);
        $this->app['router']->get('/account', fn () => auth()->check() ? response(self::ACCESSIBLE) : redirect('/login'));
        $this->app['router']->get('/login', fn () => response(self::ERRORS));

        [$guest, $guestOutput] = $this->check(['url' => '/account']);
        [$byLogin] = $this->check(['url' => '/account', '--allow-login-page' => true]);
        [$byId] = $this->check(['url' => '/account', '--as' => '7']);
        $this->app['auth']->forgetGuards();
        [$byEmail] = $this->check(['url' => '/account', '--as' => 'jane@example.com']);
        [$unknown, $unknownOutput] = $this->check(['url' => '/account', '--as' => 'nobody@example.com']);
        [$remote, $remoteOutput] = $this->check(['url' => 'https://elsewhere.example.com/', '--as' => '7']);

        $this->assertSame(2, $guest);
        $this->assertStringContainsString('redirected to the login page', $guestOutput->stderr());
        $this->assertSame(1, $byLogin, 'the login page itself is analyzed');
        $this->assertSame(0, $byId);
        $this->assertSame(0, $byEmail);
        $this->assertSame(2, $unknown);
        $this->assertStringContainsString('No user found', $unknownOutput->stderr());
        $this->assertSame(2, $remote);
        $this->assertStringContainsString('only works for pages of this application', $remoteOutput->stderr());
    }

    public function test_form_login_resolves_the_login_url_against_the_origin(): void
    {
        $logins = ['https://example.com/login', 'https://example.com/admin/login', 'https://auth.example.com/login'];

        Http::fake(fn (Request $request) => match (true) {
            in_array($request->url(), $logins, true) => Http::response('', 302, ['Location' => 'https://example.com/dashboard', 'Set-Cookie' => 'laravel_session=abc; Path=/']),
            $request->url() === 'https://example.com/dashboard' => Http::response(self::ACCESSIBLE, 200),
            default => Http::response('Not Found', 404),
        });

        foreach ([[[], $logins[0]], [['--login-url' => '/admin/login'], $logins[1]], [['--login-url' => 'https://auth.example.com/login'], $logins[2]]] as [$options, $loginUrl]) {
            [$exitCode, $output] = $this->check(['url' => 'https://example.com/dashboard', '--auth' => true, '--email' => 'user@example.com', '--password' => 'secret', ...$options]);

            $this->assertSame(0, $exitCode, $loginUrl.': '.$output->stderr());
            Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === $loginUrl);
        }
    }

    public function test_failed_logins_exit_2_with_the_cause(): void
    {
        Http::fake([
            'https://example.com/login' => fn (Request $request) => $request->method() === 'GET'
                ? Http::response('<form><input type="hidden" name="_token" value="t"></form>', 200)
                : Http::response('', 419),
        ]);

        [$exitCode, $output] = $this->check(['url' => 'https://example.com/dashboard', '--auth' => true, '--email' => 'u@example.com', '--password' => 'p']);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('CSRF', $output->stderr());
    }

    public function test_auth_without_credentials_is_an_error_in_non_interactive_runs(): void
    {
        [$exitCode, $output] = $this->check(['url' => 'https://example.com/', '--auth' => true, '--no-interaction' => true]);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('BFSG_AUTH_EMAIL', $output->stderr());
    }

    public function test_credentials_and_token_from_the_environment(): void
    {
        Http::fake([
            'https://example.com/login' => Http::response('', 302, ['Location' => '/home', 'Set-Cookie' => 'laravel_session=abc; Path=/']),
            '*' => Http::response(self::ACCESSIBLE, 200),
        ]);

        putenv('BFSG_AUTH_EMAIL=ci@example.com');
        putenv('BFSG_AUTH_PASSWORD=from-env');

        try {
            $this->assertSame(0, $this->check(['url' => 'https://example.com/dashboard', '--auth' => true])[0]);
            Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request['email'] === 'ci@example.com' && $request['password'] === 'from-env');
        } finally {
            putenv('BFSG_AUTH_EMAIL');
            putenv('BFSG_AUTH_PASSWORD');
        }

        putenv('BFSG_AUTH_TOKEN=env-token');

        try {
            $this->assertSame(0, $this->check(['url' => 'https://example.com/api-page', '--auth' => true])[0]);
            Http::assertSent(fn (Request $request) => $request->url() === 'https://example.com/api-page' && $request->hasHeader('Authorization', 'Bearer env-token'));
        } finally {
            putenv('BFSG_AUTH_TOKEN');
        }
    }

    public function test_sanctum_login_uses_the_origin(): void
    {
        Http::fake([
            'https://app.example.com/sanctum/csrf-cookie' => Http::response('', 204, ['Set-Cookie' => ['XSRF-TOKEN=csrf; Path=/', 'laravel_session=abc; Path=/']]),
            'https://app.example.com/login' => Http::response(['message' => 'ok'], 200, ['Set-Cookie' => 'laravel_session=regenerated; Path=/']),
            'https://app.example.com/dashboard' => Http::response(self::ACCESSIBLE, 200),
            '*' => Http::response('Not Found', 404),
        ]);

        [$exitCode] = $this->check(['url' => 'https://app.example.com/dashboard', '--sanctum' => true, '--email' => 'user@example.com', '--password' => 'secret']);

        $this->assertSame(0, $exitCode);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === 'https://app.example.com/login' && $request->hasHeader('X-XSRF-TOKEN', 'csrf'));
    }

    public function test_token_api_key_and_session_options(): void
    {
        $this->fakeSite();

        $this->check(['url' => 'http://example.com/a', '--bearer' => 'b-1']);
        $this->check(['url' => 'http://example.com/b', '--jwt' => 'j-2']);
        $this->check(['url' => 'http://example.com/c', '--api-key' => 'k-3', '--api-key-header' => 'X-Custom-Auth']);
        $this->check(['url' => 'http://example.com/d', '--session' => 'laravel_session=s-4']);
        [$invalid, $output] = $this->check(['url' => 'http://example.com/e', '--session' => 'no-equals-sign']);

        Http::assertSent(fn (Request $request) => $request->url() === 'http://example.com/a' && $request->hasHeader('Authorization', 'Bearer b-1'));
        Http::assertSent(fn (Request $request) => $request->url() === 'http://example.com/b' && $request->hasHeader('Authorization', 'Bearer j-2'));
        Http::assertSent(fn (Request $request) => $request->url() === 'http://example.com/c' && $request->hasHeader('X-Custom-Auth', 'k-3'));
        Http::assertSent(fn (Request $request) => $request->url() === 'http://example.com/d' && ($request->header('Cookie')[0] ?? '') === 'laravel_session=s-4');
        $this->assertSame(2, $invalid);
        $this->assertStringContainsString('name=value', $output->stderr());
    }

    public function test_auth_options_fetch_pages_of_this_application_over_http(): void
    {
        Http::fake(['http://localhost/*' => Http::response(self::ACCESSIBLE, 200)]);

        [$exitCode] = $this->check(['url' => '/dashboard', '--bearer' => 'token']);

        $this->assertSame(0, $exitCode);
        Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost/dashboard' && $request->hasHeader('Authorization', 'Bearer token'));
    }

    public function test_save_stores_the_report(): void
    {
        $this->fakeSite(self::ERRORS);

        [$exitCode, $output] = $this->check(['url' => 'http://example.com/page', '--save' => true]);

        $report = BfsgReport::query()->sole();
        $this->assertSame(1, $exitCode);
        $this->assertSame('http://example.com/page', $report->url);
        $this->assertSame('bfsg:check', $report->metadata['source']);
        $this->assertStringContainsString("Stored as report #{$report->id}", $output->stderr());
    }

    public function test_save_stores_the_url_without_query_string_and_at_most_2048_characters(): void
    {
        $this->fakeSite(self::ERRORS);
        $path = '/'.str_repeat('a', 3000);

        [$long] = $this->check(['url' => 'http://example.com'.$path.'?utm_source=x', '--save' => true]);
        [$query, $output] = $this->check(['url' => 'http://example.com/page?signature=s3cret&email=a%40example.com#top', '--save' => true, '--format' => 'json']);

        $this->assertSame(1, $long);
        $this->assertSame(1, $query);
        $this->assertSame('http://example.com/page?signature=s3cret&email=a%40example.com#top', json_decode($output->stdout(), true)['url'], 'the report itself keeps the URL');
        $this->assertSame([mb_substr('http://example.com'.$path, 0, 2048), 'http://example.com/page'], BfsgReport::query()->orderBy('id')->pluck('url')->all());
        $this->assertSame(1, BfsgReport::forUrl('http://example.com'.$path.'?utm_source=x')->count(), 'the pasted long URL finds its report');
    }

    public function test_save_without_tables_exits_2_with_the_migrate_hint(): void
    {
        $this->fakeSite();
        Schema::drop('bfsg_violations');

        [$exitCode, $output] = $this->check(['url' => 'http://example.com/page', '--save' => true]);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('php artisan migrate', $output->stderr());
        Http::assertNothingSent();
    }

    public function test_browser_mode_renders_with_playwright_and_analyzes_the_result(): void
    {
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $commands[] = (array) $process->command;

            return Process::result(($process->command[1] ?? '') === '-e' ? '' : self::ERRORS);
        });

        [$exitCode, $output] = $this->check(['url' => 'https://spa.example.com/', '--browser' => true, '--engine' => 'webkit', '--timeout' => '5000', '--format' => 'json']);

        $this->assertSame(1, $exitCode, $output->stderr());
        $this->assertSame('https://spa.example.com/', json_decode($output->stdout(), true)['url']);
        $this->assertCount(2, $commands);
        $this->assertStringContainsString('Rendering https://spa.example.com/ with webkit', $output->stderr());
        Http::assertNothingSent();
    }

    public function test_browser_mode_inlines_stylesheets_unless_no_inline_css(): void
    {
        $scripts = [];
        Process::fake(function (PendingProcess $process) use (&$scripts) {
            if (($process->command[1] ?? '') === '-e') {
                return Process::result('');
            }

            $scripts[] = (string) file_get_contents($process->command[1]);

            return Process::result(self::ACCESSIBLE, "bfsg-warning: Stylesheet /late.css was not inlined: more than 5 stylesheets.\n");
        });

        [$inlined, $inlinedOutput] = $this->check(['url' => 'https://spa.example.com/', '--browser' => true]);
        [$plain, $plainOutput] = $this->check(['url' => 'https://spa.example.com/', '--browser' => true, '--no-inline-css' => true]);

        $this->assertSame(0, $inlined, $inlinedOutput->stderr());
        $this->assertSame(0, $plain, $plainOutput->stderr());
        $this->assertStringContainsString('"inlineStylesheets":true', $scripts[0]);
        $this->assertStringContainsString('"inlineStylesheets":false', $scripts[1]);
        $this->assertStringContainsString('Stylesheet /late.css was not inlined', $inlinedOutput->stderr());
    }

    public function test_browser_mode_errors_exit_2(): void
    {
        Process::fake(fn () => Process::result('', "Cannot find module 'playwright'", 1));

        [$missing, $missingOutput] = $this->check(['url' => 'https://spa.example.com/', '--browser' => true]);
        [$engine, $engineOutput] = $this->check(['url' => 'https://spa.example.com/', '--browser' => true, '--engine' => 'opera']);
        [$auth, $authOutput] = $this->check(['url' => 'https://spa.example.com/', '--browser' => true, '--bearer' => 'x']);
        [$headless] = $this->check(['url' => 'https://spa.example.com/', '--browser' => true, '--headless' => 'maybe']);

        $this->assertSame(2, $missing);
        $this->assertStringContainsString('npm install playwright', $missingOutput->stderr());
        $this->assertSame(2, $engine);
        $this->assertStringContainsString('Unknown browser engine [opera]', $engineOutput->stderr());
        $this->assertSame(2, $auth);
        $this->assertStringContainsString('cannot be combined with --bearer', $authOutput->stderr());
        $this->assertSame(2, $headless);
    }

    public function test_fetch_options_the_browser_would_ignore_are_rejected_with_browser(): void
    {
        Process::fake();

        foreach (['--insecure' => true, '--allow-login-page' => true, '--login-url' => '/signin'] as $option => $value) {
            [$exitCode, $output] = $this->check(['url' => 'https://spa.example.com/', '--browser' => true, $option => $value]);

            $this->assertSame(2, $exitCode, $option);
            $this->assertStringContainsString("--browser cannot be combined with {$option}", $output->stderr());
            $this->assertSame('', $output->stdout(), $option);
        }

        Process::assertNothingRan();
        Http::assertNothingSent();
    }

    public function test_pending_command_output_still_contains_the_status_lines(): void
    {
        $this->fakeSite(self::ERRORS);

        $this->artisan('bfsg:check', ['url' => 'http://example.com/page'])
            ->expectsOutputToContain('Checking http://example.com/page')
            ->assertExitCode(1);
    }

    public function test_tokens_are_not_sent_to_another_host_after_a_redirect(): void
    {
        Http::fake([
            'https://example.com/moved' => Http::response('', 302, ['Location' => 'https://other.example.net/page']),
            '*' => Http::response(self::ACCESSIBLE, 200),
        ]);

        [$exitCode] = $this->check(['url' => 'https://example.com/moved', '--bearer' => 'b-1', '--api-key' => 'k-2']);

        $this->assertSame(0, $exitCode);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://example.com/moved' && $request->hasHeader('Authorization', 'Bearer b-1'));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://other.example.net/page' && ! $request->hasHeader('Authorization') && ! $request->hasHeader('X-API-Key'));
    }

    public function test_url_credentials_are_sent_with_the_login_to_a_basic_auth_protected_site(): void
    {
        $basic = 'Basic '.base64_encode('deploy:s3cret');
        Http::fake(function (Request $request) use ($basic) {
            if (! $request->hasHeader('Authorization', $basic)) {
                return Http::response('Unauthorized', 401, ['WWW-Authenticate' => 'Basic realm="staging"']);
            }

            return match ($request->method().' '.$request->url()) {
                'GET https://staging.example.com/login' => Http::response('<form method="post"><input type="hidden" name="_token" value="t"><input type="password" name="password"></form>', 200, ['Set-Cookie' => 'laravel_session=guest; Path=/']),
                'POST https://staging.example.com/login' => Http::response('', 302, ['Location' => 'https://staging.example.com/dashboard', 'Set-Cookie' => 'laravel_session=user; Path=/']),
                'GET https://staging.example.com/dashboard' => Http::response(self::ACCESSIBLE, 200),
                default => Http::response('Not Found', 404),
            };
        });

        [$form, $formOutput] = $this->check(['url' => 'https://deploy:s3cret@staging.example.com/dashboard', '--auth' => true, '--email' => 'user@example.com', '--password' => 'secret']);
        [$apiKey, $apiKeyOutput] = $this->check(['url' => 'https://deploy:s3cret@staging.example.com/dashboard', '--api-key' => 'k-1']);

        $this->assertSame(0, $form, $formOutput->stderr());
        $this->assertSame(0, $apiKey, $apiKeyOutput->stderr());
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === 'https://staging.example.com/login' && $request->hasHeader('Authorization', $basic) && $request['_token'] === 't');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://staging.example.com/dashboard' && $request->hasHeader('Authorization', $basic) && $request->hasHeader('X-API-Key', 'k-1'));
        Http::assertNotSent(fn (Request $request) => ! $request->hasHeader('Authorization', $basic));
        $this->assertStringNotContainsString('s3cret', $formOutput->stdout().$formOutput->stderr().$apiKeyOutput->stdout().$apiKeyOutput->stderr());
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function authorizationOptions(): array
    {
        return [
            '--bearer' => [['--bearer' => 'b-1']],
            '--jwt' => [['--jwt' => 'j-1']],
            '--api-key-header=Authorization' => [['--api-key' => 'k-1', '--api-key-header' => 'authorization']],
        ];
    }

    #[DataProvider('authorizationOptions')]
    public function test_url_credentials_with_another_authorization_header_exit_2(array $options): void
    {
        $this->fakeSite();

        [$exitCode, $output] = $this->check(['url' => 'http://deploy:s3cret@example.com/page', ...$options]);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('both use the Authorization header', $output->stderr());
        $this->assertStringNotContainsString('s3cret', $output->stderr());
        $this->assertSame('', $output->stdout());
        Http::assertNothingSent();
    }

    public function test_a_bearer_token_from_the_authentication_cannot_replace_url_credentials(): void
    {
        Http::fake([
            'https://staging.example.com/login' => Http::response(['token' => 'api-token'], 200),
            '*' => Http::response(self::ACCESSIBLE, 200),
        ]);

        [$json, $jsonOutput] = $this->check(['url' => 'https://deploy:s3cret@staging.example.com/dashboard', '--auth' => true, '--json-auth' => true, '--email' => 'user@example.com', '--password' => 'secret']);

        putenv('BFSG_AUTH_TOKEN=env-token');

        try {
            [$env, $envOutput] = $this->check(['url' => 'https://deploy:s3cret@staging.example.com/dashboard', '--auth' => true]);
        } finally {
            putenv('BFSG_AUTH_TOKEN');
        }

        $this->assertSame(2, $json);
        $this->assertSame(2, $env);
        $this->assertStringContainsString('bearer token', $jsonOutput->stderr());
        $this->assertStringContainsString('bearer token', $envOutput->stderr());
        Http::assertNotSent(fn (Request $request) => $request->url() === 'https://staging.example.com/dashboard');
    }

    public function test_url_credentials_become_a_basic_header_for_that_origin_and_appear_nowhere_else(): void
    {
        Http::fake([
            'https://staging.example.com/moved' => Http::response('', 302, ['Location' => 'https://staging.example.com/page']),
            'https://staging.example.com/page' => Http::response(self::ERRORS, 200, ['Content-Type' => 'text/html']),
            'https://staging.example.com/gone' => Http::response('', 302, ['Location' => 'https://other.example.net/missing']),
            'https://other.example.net/missing' => Http::response('', 404),
        ]);
        $path = sys_get_temp_dir().'/bfsg-userinfo-'.uniqid().'.md';

        try {
            [$json, $jsonOutput] = $this->check(['url' => 'https://deploy:s3cr%40t@staging.example.com/moved', '--format' => 'json', '--save' => true]);
            [$markdown, $markdownOutput] = $this->check(['url' => 'https://deploy:s3cr%40t@staging.example.com/page', '--format' => 'markdown', '--output' => $path]);
            [$failed, $failedOutput] = $this->check(['url' => 'https://deploy:s3cr%40t@staging.example.com/gone']);
            [$invalid, $invalidOutput] = $this->check(['url' => 'ftp://deploy:s3cr%40t@staging.example.com/']);

            $this->assertSame(1, $json, $jsonOutput->stderr());
            $this->assertSame(1, $markdown, $markdownOutput->stderr());
            $this->assertSame(2, $failed);
            $this->assertSame(2, $invalid);
            $this->assertSame('https://staging.example.com/page', json_decode($jsonOutput->stdout(), true)['url']);
            $this->assertStringContainsString('Checking https://staging.example.com/moved', $jsonOutput->stderr());
            $this->assertStringContainsString('HTTP 404', $failedOutput->stderr());

            $everything = implode("\n", [
                $jsonOutput->stdout(), $jsonOutput->stderr(), $markdownOutput->stdout(), $markdownOutput->stderr(),
                $failedOutput->stdout(), $failedOutput->stderr(), $invalidOutput->stdout(), $invalidOutput->stderr(),
                (string) file_get_contents($path), json_encode(DB::table('bfsg_reports')->get()), json_encode(DB::table('bfsg_violations')->get()),
            ]);

            foreach (['s3cr', 'deploy:', 'deploy@'] as $secret) {
                $this->assertStringNotContainsString($secret, $everything);
            }

            $this->assertSame('https://staging.example.com/page', BfsgReport::query()->sole()->url);
            $basic = 'Basic '.base64_encode('deploy:s3cr@t');
            Http::assertSent(fn (Request $request) => $request->url() === 'https://staging.example.com/moved' && $request->hasHeader('Authorization', $basic));
            Http::assertSent(fn (Request $request) => $request->url() === 'https://staging.example.com/page' && $request->hasHeader('Authorization', $basic));
            Http::assertSent(fn (Request $request) => $request->url() === 'https://other.example.net/missing' && ! $request->hasHeader('Authorization'));
            Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 's3cr'));
        } finally {
            @unlink($path);
        }
    }
}
