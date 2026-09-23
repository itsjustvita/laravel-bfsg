<?php

namespace ItsJustVita\LaravelBfsg\Browser;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

/**
 * Renders a page with Playwright (node) and returns the DOM as HTML; analysis stays with Bfsg. The URL, wait
 * selector, engine and ignored selectors reach the generated script only as JSON, the engine is validated,
 * and the temporary script (mode 0600: the URL may carry tokens) is removed in every case. One deadline of
 * `timeout` ms covers launch, navigation and the wait selector; the node process gets 15 s more for start-up
 * and shutdown.
 *
 * Known limits: the caller reports the requested URL, not the URL after browser redirects, and TLS errors are
 * not ignored (bfsg:check --insecure has no effect on the browser).
 */
class BrowserAnalyzer
{
    public const ENGINES = ['chromium', 'firefox', 'webkit'];

    public function __construct(private ?string $workingDirectory = null) {}

    /**
     * @param  array{headless?: bool, timeout?: int, waitFor?: string, engine?: string, ignoredSelectors?: list<string>}  $options  timeout in milliseconds
     *
     * @throws BrowserRenderFailed
     */
    public function render(string $url, array $options = []): string
    {
        $options = array_merge([
            'headless' => true,
            'timeout' => 30000,
            'waitFor' => 'body',
            'engine' => 'chromium',
            'ignoredSelectors' => (array) config('bfsg.ignored_selectors', []),
        ], $options);

        if (! in_array($options['engine'], self::ENGINES, true)) {
            throw BrowserRenderFailed::invalidEngine((string) $options['engine']);
        }

        $timeout = max(1000, (int) $options['timeout']);
        $directory = $this->workingDirectory ?? base_path();

        if (! Process::path($directory)->run(['node', '-e', "require.resolve('playwright')"])->successful()) {
            throw BrowserRenderFailed::playwrightMissing($directory);
        }

        $script = sys_get_temp_dir().'/bfsg-browser-'.bin2hex(random_bytes(8)).'.cjs';
        $seconds = (int) ceil($timeout / 1000) + 15;

        try {
            if (! touch($script) || ! chmod($script, 0600)) {
                throw BrowserRenderFailed::process("could not create the temporary script {$script}");
            }

            file_put_contents($script, $this->script([
                'url' => $url,
                'engine' => $options['engine'],
                'headless' => (bool) $options['headless'],
                'timeout' => $timeout,
                'waitFor' => (string) $options['waitFor'],
                'ignoredSelectors' => array_values(array_map('strval', $options['ignoredSelectors'])),
            ]));

            try {
                $result = Process::path($directory)->timeout($seconds)->run(['node', $script]);
            } catch (ProcessTimedOutException) {
                throw BrowserRenderFailed::timedOut($seconds);
            }

            if (! $result->successful()) {
                throw BrowserRenderFailed::process(trim($result->errorOutput()) ?: trim($result->output()));
            }

            if (trim($result->output()) === '') {
                throw BrowserRenderFailed::process('');
            }

            return $result->output();
        } finally {
            if (is_file($script)) {
                unlink($script);
            }
        }
    }

    /** @param  array{url: string, engine: string, headless: bool, timeout: int, waitFor: string, ignoredSelectors: list<string>}  $options */
    private function script(array $options): string
    {
        $json = json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return <<<JS
const path = require('path');
const { createRequire } = require('module');
const playwright = createRequire(path.join(process.cwd(), 'package.json'))('playwright');
const options = {$json};
const deadline = Date.now() + options.timeout;
const remaining = () => Math.max(1, deadline - Date.now());

(async () => {
    const browser = await playwright[options.engine].launch({ headless: options.headless, timeout: remaining() });

    try {
        const page = await browser.newPage();
        await page.goto(options.url, { waitUntil: 'networkidle', timeout: remaining() });
        await page.waitForSelector(options.waitFor, { timeout: remaining() });

        for (const selector of options.ignoredSelectors) {
            await page.evaluate((sel) => {
                try { document.querySelectorAll(sel).forEach((element) => element.remove()); } catch (error) {}
            }, selector);
        }

        process.stdout.write(await page.content());
    } finally {
        await browser.close();
    }
})().catch((error) => {
    process.stderr.write(String((error && error.message) || error));
    process.exit(1);
});
JS;
    }
}
