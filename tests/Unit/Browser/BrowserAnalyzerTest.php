<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit\Browser;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use ItsJustVita\LaravelBfsg\Browser\BrowserAnalyzer;
use ItsJustVita\LaravelBfsg\Browser\BrowserRenderFailed;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class BrowserAnalyzerTest extends TestCase
{
    /** @var list<array{command: array<int, string>, path: ?string, timeout: ?int, script: ?string}> */
    private array $runs = [];

    private function fakeNode(string $html = '<html><body>rendered</body></html>', bool $playwright = true, int $exitCode = 0, string $stderr = ''): void
    {
        Process::fake(function (PendingProcess $process) use ($html, $playwright, $exitCode, $stderr) {
            $command = (array) $process->command;
            $script = ($command[1] ?? '') !== '-e' && is_file($command[1] ?? '') ? (string) file_get_contents($command[1]) : null;
            $this->runs[] = ['command' => $command, 'path' => $process->path, 'timeout' => $process->timeout, 'script' => $script];

            if (($command[1] ?? '') === '-e') {
                return Process::result('', $playwright ? '' : "Error: Cannot find module 'playwright'", $playwright ? 0 : 1);
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
