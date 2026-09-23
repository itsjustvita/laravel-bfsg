<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit\Browser;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use ItsJustVita\LaravelBfsg\Browser\BrowserAnalyzer;
use ItsJustVita\LaravelBfsg\Browser\BrowserRenderFailed;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeoutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process as SymfonyProcess;

class BrowserAnalyzerTest extends TestCase
{
    /** @var list<array{command: array<int, string>, path: ?string, timeout: ?int, script: ?string, mode: ?int}> */
    private array $runs = [];

    private function fakeNode(string $html = '<html><body>rendered</body></html>', bool $playwright = true, int $exitCode = 0, string $stderr = '', bool $timesOut = false): void
    {
        Process::fake(function (PendingProcess $process) use ($html, $playwright, $exitCode, $stderr, $timesOut) {
            $command = (array) $process->command;
            $isScript = ($command[1] ?? '') !== '-e' && is_file($command[1] ?? '');
            $script = $isScript ? (string) file_get_contents($command[1]) : null;
            $mode = $isScript ? fileperms($command[1]) & 0777 : null;
            $this->runs[] = ['command' => $command, 'path' => $process->path, 'timeout' => $process->timeout, 'script' => $script, 'mode' => $mode];

            if (($command[1] ?? '') === '-e') {
                return Process::result('', $playwright ? '' : "Error: Cannot find module 'playwright'", $playwright ? 0 : 1);
            }

            if ($timesOut) {
                $symfony = new SymfonyProcess($command);
                $symfony->setTimeout($process->timeout);

                throw new ProcessTimedOutException(new SymfonyTimeoutException($symfony, SymfonyTimeoutException::TYPE_GENERAL), new ProcessResult($symfony));
            }

            return Process::result($exitCode === 0 ? $html : '', $stderr, $exitCode);
        });
    }

    private function failure(\Closure $render): BrowserRenderFailed
    {
        try {
            $render();
        } catch (BrowserRenderFailed $e) {
            return $e;
        }

        $this->fail('BrowserRenderFailed was not thrown');
    }

    public function test_render_returns_the_html_printed_by_playwright(): void
    {
        $this->fakeNode();

        $html = (new BrowserAnalyzer('/srv/app'))->render('https://example.com/', ['timeout' => 20000, 'engine' => 'firefox', 'headless' => false]);

        $this->assertSame('<html><body>rendered</body></html>', trim($html));
        $this->assertSame(['node', '-e', "require.resolve('playwright')"], $this->runs[0]['command']);
        $this->assertSame('/srv/app', $this->runs[0]['path']);
        $this->assertSame('node', $this->runs[1]['command'][0]);
        $this->assertStringEndsWith('.cjs', $this->runs[1]['command'][1]);
        $this->assertSame('/srv/app', $this->runs[1]['path']);
        $this->assertSame(35, $this->runs[1]['timeout'], 'ceil(20000 / 1000) + 15');
        $this->assertStringContainsString('"engine":"firefox"', $this->runs[1]['script']);
        $this->assertStringContainsString('"headless":false', $this->runs[1]['script']);
        $this->assertStringContainsString('"timeout":20000', $this->runs[1]['script']);
        $this->assertFileDoesNotExist($this->runs[1]['command'][1], 'the temporary script is removed');
        $this->assertSame(0600, $this->runs[1]['mode'], 'the script carries the URL and is readable by its owner only');
    }

    public function test_the_generated_script_is_valid_javascript(): void
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            $this->markTestSkipped('node is not installed');
        }

        $this->fakeNode();
        (new BrowserAnalyzer('/srv/app'))->render("https://example.com/?q=');x('</script>`\${1}", ['waitFor' => "main[data-x='1']", 'ignoredSelectors' => ['#chat', '"quoted"']]);

        $file = sys_get_temp_dir().'/bfsg-check-'.uniqid().'.cjs';
        file_put_contents($file, $this->runs[1]['script']);

        try {
            $check = new SymfonyProcess([$node, '--check', $file]);
            $check->run();

            $this->assertTrue($check->isSuccessful(), $check->getErrorOutput());
        } finally {
            unlink($file);
        }
    }

    public function test_one_deadline_covers_navigation_and_the_wait_selector(): void
    {
        $this->fakeNode();

        (new BrowserAnalyzer('/srv/app'))->render('https://example.com/');

        $script = $this->runs[1]['script'];
        $this->assertStringContainsString('const deadline = Date.now() + options.timeout;', $script);
        $this->assertStringContainsString("page.goto(options.url, { waitUntil: 'networkidle', timeout: remaining() })", $script);
        $this->assertStringContainsString('page.waitForSelector(options.waitFor, { timeout: remaining() })', $script);
    }

    public function test_a_process_timeout_is_a_render_failure_and_removes_the_script(): void
    {
        $this->fakeNode(timesOut: true);

        $failure = $this->failure(fn () => (new BrowserAnalyzer('/srv/app'))->render('https://example.com/', ['timeout' => 5000]));

        $this->assertStringContainsString('timed out after 20 s', $failure->getMessage());
        $this->assertFileDoesNotExist($this->runs[1]['command'][1]);
    }

    public function test_url_selector_and_ignored_selectors_reach_the_script_only_as_json(): void
    {
        $this->fakeNode();
        $url = "https://example.com/?q=');process.exit(0);('</script>";

        (new BrowserAnalyzer('/srv/app'))->render($url, ['waitFor' => "main[data-x='1']", 'ignoredSelectors' => ['#chat']]);

        $script = $this->runs[1]['script'];
        $this->assertStringNotContainsString("');process.exit(0);('", $script);
        $this->assertStringNotContainsString('</script>', $script);
        $json = fn (string $value) => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $this->assertStringContainsString('"url":'.$json($url), $script);
        $this->assertStringContainsString('"waitFor":'.$json("main[data-x='1']"), $script);
        $this->assertStringContainsString('"ignoredSelectors":["#chat"]', $script);
    }

    public function test_ignored_selectors_default_to_the_config(): void
    {
        config()->set('bfsg.ignored_selectors', ['.cookie-banner']);
        $this->fakeNode();

        (new BrowserAnalyzer('/srv/app'))->render('https://example.com/');

        $this->assertStringContainsString('"ignoredSelectors":[".cookie-banner"]', $this->runs[1]['script']);
        $this->assertSame(45, $this->runs[1]['timeout'], 'default 30 s + 15');
    }

    public function test_invalid_engines_are_rejected_before_anything_runs(): void
    {
        $this->fakeNode();

        $failure = $this->failure(fn () => (new BrowserAnalyzer('/srv/app'))->render('https://example.com/', ['engine' => 'chromium; rm -rf /']));

        $this->assertStringContainsString('Unknown browser engine', $failure->getMessage());
        $this->assertSame([], $this->runs);
    }

    public function test_missing_playwright_is_reported_with_the_install_hint(): void
    {
        $this->fakeNode(playwright: false);

        $failure = $this->failure(fn () => (new BrowserAnalyzer('/srv/app'))->render('https://example.com/'));

        $this->assertStringContainsString('npm install playwright', $failure->getMessage());
        $this->assertCount(1, $this->runs, 'no script is generated or run');
    }

    public function test_failed_renders_carry_the_error_output_and_still_remove_the_script(): void
    {
        $this->fakeNode(exitCode: 1, stderr: 'page.goto: net::ERR_NAME_NOT_RESOLVED');

        $failure = $this->failure(fn () => (new BrowserAnalyzer('/srv/app'))->render('https://nowhere.invalid/'));

        $this->assertStringContainsString('ERR_NAME_NOT_RESOLVED', $failure->getMessage());
        $this->assertFileDoesNotExist($this->runs[1]['command'][1]);
    }

    public function test_empty_output_is_a_failure(): void
    {
        $this->fakeNode(html: "  \n");

        $this->assertStringContainsString('no output', $this->failure(fn () => (new BrowserAnalyzer('/srv/app'))->render('https://example.com/'))->getMessage());
    }

    public function test_the_working_directory_defaults_to_the_application(): void
    {
        $this->fakeNode();

        app(BrowserAnalyzer::class)->render('https://example.com/');

        $this->assertSame(base_path(), $this->runs[0]['path']);
    }
}
