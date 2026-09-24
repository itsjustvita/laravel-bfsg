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

    public function test_stylesheet_inlining_follows_the_fetch_config_unless_the_option_is_given(): void
    {
        $this->fakeNode();

        (new BrowserAnalyzer('/srv/app'))->render('https://example.com/');
        config()->set('bfsg.fetch.inline_stylesheets', 'false');
        config()->set('bfsg.fetch.max_stylesheets', 2);
        config()->set('bfsg.fetch.max_stylesheet_bytes', 1000);
        (new BrowserAnalyzer('/srv/app'))->render('https://example.com/');
        (new BrowserAnalyzer('/srv/app'))->render('https://example.com/', ['inlineStylesheets' => true]);

        $this->assertStringContainsString('"inlineStylesheets":true,"maxStylesheets":5,"maxStylesheetBytes":524288', $this->runs[1]['script']);
        $this->assertStringContainsString('"inlineStylesheets":false,"maxStylesheets":2,"maxStylesheetBytes":1000', $this->runs[3]['script']);
        $this->assertStringContainsString('"inlineStylesheets":true,"maxStylesheets":2,"maxStylesheetBytes":1000', $this->runs[5]['script']);
        $this->assertStringContainsString('const inlineStylesheets = '.BrowserAnalyzer::INLINE_STYLESHEETS_JS.';', $this->runs[1]['script']);
        $this->assertStringContainsString('if (options.inlineStylesheets) {', $this->runs[1]['script']);
    }

    public function test_warnings_of_the_script_are_collected_per_render(): void
    {
        $this->fakeNode(stderr: "bfsg-warning: Stylesheet /a.css could not be read.\n(node:1) ExperimentalWarning: something\nbfsg-warning: Stylesheet /b.css was not inlined: more than 5 stylesheets.\n");
        $browser = new BrowserAnalyzer('/srv/app');

        $browser->render('https://example.com/');

        $this->assertSame(['Stylesheet /a.css could not be read.', 'Stylesheet /b.css was not inlined: more than 5 stylesheets.'], $browser->warnings());

        $this->runs = [];
        $this->fakeNode();
        $browser->render('https://example.com/');

        $this->assertSame([], $browser->warnings());
    }

    public function test_the_in_page_inliner_replaces_same_origin_sheets_within_the_limits(): void
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            $this->markTestSkipped('node is not installed');
        }

        $harness = 'const inline = '.BrowserAnalyzer::INLINE_STYLESHEETS_JS.";\n".<<<'JS'
class FakeStyle {
    constructor() { this.attrs = {}; this.textContent = ''; this.sheet = null; }
    setAttribute(name, value) { this.attrs[name] = String(value); }
    hasAttribute(name) { return name in this.attrs; }
}
const rules = (...texts) => ({ cssRules: texts.map((cssText) => ({ cssText })) });
const unreadable = { get cssRules() { throw new Error('SecurityError'); } };
const link = (href, sheet, media) => ({
    attrs: media ? { href, media } : { href },
    sheet,
    href: new URL(href, 'https://spa.example.com/app/').href,
    replacedWith: null,
    getAttribute(name) { return this.attrs[name] ?? null; },
    replaceWith(node) { this.replacedWith = node; },
});
const links = [
    link('/css/app.css', rules('.faint { color: rgb(187, 187, 187); }', 'a::after { content: "</style>"; }'), 'screen'),
    link('https://cdn.example.net/x.css', rules('.x { color: red; }')),
    link('/css/locked.css', unreadable),
    link('/css/alternate.css', Object.assign(rules('.o { color: red; }'), { disabled: true })),
    link('/css/big.css', rules('.b { color: red; }'.repeat(10))),
    link('/css/second.css', rules('.s { color: blue; }')),
    link('/css/third.css', rules('.t { color: green; }')),
    link('/favicon.ico', null),
];
const cssInJs = new FakeStyle();
cssInJs.sheet = rules('.emotion-1 { color: rgb(170, 170, 170); }');
const authored = new FakeStyle();
authored.textContent = 'body { color: #111; }';
authored.sheet = rules('body { color: rgb(17, 17, 17); }');
global.location = { origin: 'https://spa.example.com' };
global.document = {
    baseURI: 'https://spa.example.com/app/',
    querySelectorAll: (selector) => (selector === 'link' ? links : [cssInJs, authored]),
    createElement: () => new FakeStyle(),
};
const warnings = inline({ maxStylesheets: 4, maxBytes: 100 });
process.stdout.write(JSON.stringify({
    warnings,
    replaced: links.map((l) => (l.replacedWith ? { attrs: l.replacedWith.attrs, text: l.replacedWith.textContent } : null)),
    cssInJs: cssInJs.textContent,
    authored: authored.textContent,
}));
JS;
        $file = sys_get_temp_dir().'/bfsg-inline-'.uniqid().'.cjs';
        file_put_contents($file, $harness);

        try {
            $run = new SymfonyProcess([$node, $file]);
            $run->run();
            $this->assertTrue($run->isSuccessful(), $run->getErrorOutput());
            $result = json_decode($run->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            unlink($file);
        }

        $this->assertSame(['data-bfsg-inlined' => '/css/app.css', 'media' => 'screen'], $result['replaced'][0]['attrs']);
        $this->assertSame(".faint { color: rgb(187, 187, 187); }\na::after { content: \"<\\/style>\"; }", $result['replaced'][0]['text']);
        $this->assertSame(['attrs' => ['data-bfsg-inlined' => '/css/second.css'], 'text' => '.s { color: blue; }'], $result['replaced'][5]);
        $this->assertSame([null, null, null, null, null], [$result['replaced'][1], $result['replaced'][2], $result['replaced'][3], $result['replaced'][4], $result['replaced'][6]], 'cross-origin, unreadable, disabled, too large and over the limit stay links');
        $this->assertNull($result['replaced'][7], 'not a stylesheet');
        $this->assertSame([
            'Stylesheet /css/locked.css could not be read.',
            'Stylesheet /css/big.css was not inlined: larger than 100 bytes.',
            'Stylesheet /css/third.css was not inlined: more than 4 stylesheets.',
        ], $result['warnings']);
        $this->assertSame('.emotion-1 { color: rgb(170, 170, 170); }', $result['cssInJs'], 'CSS-in-JS rules become text');
        $this->assertSame('body { color: #111; }', $result['authored'], 'authored style text is kept');
    }

    public function test_the_working_directory_defaults_to_the_application(): void
    {
        $this->fakeNode();

        app(BrowserAnalyzer::class)->render('https://example.com/');

        $this->assertSame(base_path(), $this->runs[0]['path']);
    }
}
