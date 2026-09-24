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
 * Stylesheets: like a plain fetch, same-origin `<link rel="stylesheet">` files of the rendered page become
 * `<style data-bfsg-inlined="{href}">` in place (at most `bfsg.fetch.max_stylesheets`, each at most
 * `bfsg.fetch.max_stylesheet_bytes`, switched by `bfsg.fetch.inline_stylesheets`), taken from the CSSOM the browser
 * already loaded; `<style>` elements filled through the CSSOM only (CSS-in-JS `insertRule`) get their rules as text.
 * Sheets that are skipped are reported by warnings().
 *
 * Known limits: the caller reports the requested URL, not the URL after browser redirects, and TLS errors are
 * not ignored (bfsg:check --insecure has no effect on the browser).
 */
class BrowserAnalyzer
{
    public const ENGINES = ['chromium', 'firefox', 'webkit'];

    /** Prefix of the stderr lines the script uses for non-fatal problems (stylesheets it could not inline). */
    public const WARNING_PREFIX = 'bfsg-warning: ';

    /**
     * Runs inside the page (Playwright serializes it for page.evaluate()), so it must not reference anything outside
     * itself. Returns the warnings.
     */
    public const INLINE_STYLESHEETS_JS = <<<'JS'
(limits) => {
    const warnings = [];
    const bytes = (text) => new TextEncoder().encode(text).length;
    const rulesOf = (sheet) => {
        try {
            return Array.from(sheet.cssRules, (rule) => rule.cssText).join('\n');
        } catch (error) {
            return null;
        }
    };
    const styleWith = (css, href, media) => {
        const style = document.createElement('style');
        style.setAttribute('data-bfsg-inlined', href);
        if (media) {
            style.setAttribute('media', media);
        }
        style.textContent = css.replace(/<\/style/gi, '<\\/style');
        return style;
    };
    let inlined = 0;

    for (const link of Array.from(document.querySelectorAll('link'))) {
        const sheet = link.sheet;
        const href = link.getAttribute('href') || '';

        if (!sheet || sheet.disabled || href === '' || !link.href || new URL(link.href, document.baseURI).origin !== location.origin) {
            continue;
        }

        if (inlined >= limits.maxStylesheets) {
            warnings.push(`Stylesheet ${href} was not inlined: more than ${limits.maxStylesheets} stylesheets.`);
            continue;
        }

        inlined++;
        const css = rulesOf(sheet);

        if (css === null) {
            warnings.push(`Stylesheet ${href} could not be read.`);
            continue;
        }

        if (bytes(css) > limits.maxBytes) {
            warnings.push(`Stylesheet ${href} was not inlined: larger than ${limits.maxBytes} bytes.`);
            continue;
        }

        link.replaceWith(styleWith(css, href, link.getAttribute('media')));
    }

    for (const style of Array.from(document.querySelectorAll('style'))) {
        if (style.hasAttribute('data-bfsg-inlined') || style.textContent.trim() !== '' || !style.sheet) {
            continue;
        }

        const css = rulesOf(style.sheet);

        if (css !== null && css !== '' && bytes(css) <= limits.maxBytes) {
            style.textContent = css.replace(/<\/style/gi, '<\\/style');
        }
    }

    return warnings;
}
JS;

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(private ?string $workingDirectory = null) {}

    /**
     * @param  array{headless?: bool, timeout?: int, waitFor?: string, engine?: string, ignoredSelectors?: list<string>, inlineStylesheets?: ?bool, maxStylesheets?: int, maxStylesheetBytes?: int}  $options  timeout in milliseconds; inlineStylesheets null = `bfsg.fetch.inline_stylesheets`
     *
     * @throws BrowserRenderFailed
     */
    public function render(string $url, array $options = []): string
    {
        $this->warnings = [];
        $options = array_merge([
            'headless' => true,
            'timeout' => 30000,
            'waitFor' => 'body',
            'engine' => 'chromium',
            'ignoredSelectors' => (array) config('bfsg.ignored_selectors', []),
            'inlineStylesheets' => null,
            'maxStylesheets' => (int) config('bfsg.fetch.max_stylesheets', 5),
            'maxStylesheetBytes' => (int) config('bfsg.fetch.max_stylesheet_bytes', 524288),
        ], $options);
        $options['inlineStylesheets'] ??= filter_var(config('bfsg.fetch.inline_stylesheets', true), FILTER_VALIDATE_BOOL);

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
                'inlineStylesheets' => (bool) $options['inlineStylesheets'],
                'maxStylesheets' => max(0, (int) $options['maxStylesheets']),
                'maxStylesheetBytes' => max(0, (int) $options['maxStylesheetBytes']),
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

            foreach (preg_split('/\R/', $result->errorOutput()) ?: [] as $line) {
                if (str_starts_with($line, self::WARNING_PREFIX)) {
                    $this->warnings[] = substr($line, strlen(self::WARNING_PREFIX));
                }
            }

            return $result->output();
        } finally {
            if (is_file($script)) {
                unlink($script);
            }
        }
    }

    /** @return list<string> non-fatal problems of the last render() (stylesheets that were not inlined) */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @param  array{url: string, engine: string, headless: bool, timeout: int, waitFor: string, ignoredSelectors: list<string>, inlineStylesheets: bool, maxStylesheets: int, maxStylesheetBytes: int}  $options */
    private function script(array $options): string
    {
        $json = json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $inline = self::INLINE_STYLESHEETS_JS;
        $prefix = json_encode(self::WARNING_PREFIX, JSON_THROW_ON_ERROR);

        return <<<JS
const path = require('path');
const { createRequire } = require('module');
const playwright = createRequire(path.join(process.cwd(), 'package.json'))('playwright');
const options = {$json};
const deadline = Date.now() + options.timeout;
const remaining = () => Math.max(1, deadline - Date.now());
const inlineStylesheets = {$inline};

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

        if (options.inlineStylesheets) {
            const warnings = await page.evaluate(inlineStylesheets, { maxStylesheets: options.maxStylesheets, maxBytes: options.maxStylesheetBytes });
            // The page runs its own scripts and could replace the DOM API: accept only strings, one line each
            (Array.isArray(warnings) ? warnings : []).forEach((warning) => process.stderr.write({$prefix} + String(warning).replace(/\s+/g, ' ') + '\\n'));
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
