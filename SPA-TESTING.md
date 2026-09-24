# Testing single-page applications

Pages that build their content with JavaScript (React, Vue, Svelte, Inertia without server-side rendering) arrive as an almost empty shell such as `<div id="app"></div>`. A plain `bfsg:check` analyzes that shell and finds little. With `--browser`, the page is first rendered by a real browser through [Playwright](https://playwright.dev/), and the DOM after rendering is analyzed with the same 16 analyzers.

## Setup

Playwright is a Node.js package. Install it in your Laravel application, next to `package.json`: the package looks for it from `base_path()`.

```bash
npm install --save-dev playwright
npx playwright install chromium        # or: firefox, webkit
```

On a Linux CI runner, `npx playwright install --with-deps chromium` also installs the system libraries the browser needs.

## Usage

```bash
php artisan bfsg:check https://app.example.com/dashboard --browser
```

| Option | Default | Description |
|---|---|---|
| `--engine=` | `chromium` | `chromium`, `firefox` or `webkit` |
| `--headless=` | `true` | `false` opens a visible browser window (local debugging) |
| `--timeout=` | `30000` | One deadline in milliseconds for browser start, navigation and `--wait-for` together |
| `--wait-for=` | `body` | CSS selector that must exist before the DOM is taken, for example your app's root after mounting |
| `--no-inline-css` | | Keep `<link rel="stylesheet">` elements instead of inlining them |

The browser waits until the network is idle, then for `--wait-for`, removes the elements matched by `bfsg.ignored_selectors`, inlines stylesheets and hands the HTML to the analyzers. Every other `bfsg:check` option works as without `--browser`: `--format`, `--output`, `--fail-on`, `--min-score`, `--only`, `--except`, `--locale`, `--save`, `--detailed`.

```bash
# Wait for the mounted app, fail on warnings, write a JSON report
php artisan bfsg:check https://app.example.com/ --browser --wait-for="#app [data-ready]" --fail-on=warning --format=json --output=bfsg.json

# Slow page, Firefox, visible window
php artisan bfsg:check https://app.example.com/reports --browser --engine=firefox --headless=false --timeout=60000
```

## Stylesheets

The contrast analyzer needs the CSS. As with a plain fetch, same-origin stylesheets of the rendered page whose `media` applies to screen are inlined as `<style data-bfsg-inlined="…">`, taken from the stylesheets the browser has already loaded (at most `bfsg.fetch.max_stylesheets`, each at most `bfsg.fetch.max_stylesheet_bytes`). `<style>` elements that CSS-in-JS libraries fill through the CSSOM (`insertRule`, as styled-components and Emotion do in production) get their rules as text, so their colours are measured too. Stylesheets from other origins stay links. Sheets that could not be inlined are reported as `Warning: …` lines on stderr (at most 50 per page). `--no-inline-css` or `BFSG_INLINE_CSS=false` switch inlining off.

## What `--browser` does not do

- **No login.** The browser does not share `--auth`, `--sanctum`, `--bearer`, `--session`, `--as` or credentials from a login; combining them with `--browser` exits 2. Check pages behind a login without `--browser`, or make the page reachable for the check (for example on a test environment).
- **No TLS or login-page options.** `--insecure`, `--allow-login-page` and `--login-url` belong to the plain fetch and exit 2 with `--browser`; certificate errors are not ignored.
- **The requested URL is reported**, not the URL after redirects inside the browser.
- **No interaction.** Content that appears only after a click or on scroll is not rendered. Check a URL that shows the state you want to test.

## Exit codes

The same as without `--browser` (`0` threshold met, `1` exceeded, `2` operational error). A missing Playwright installation, an unknown `--engine`, a navigation error and a timeout exit 2 with the reason on stderr:

```text
Playwright is not installed in /var/www/app. Run: npm install playwright && npx playwright install
```

## CI

GitHub Actions, with PHP and the application already set up:

```yaml
- uses: actions/setup-node@v4
  with:
    node-version: 22
- name: Install Playwright
  run: |
    npm ci
    npx playwright install --with-deps chromium
- name: Accessibility check (rendered)
  run: php artisan bfsg:check https://staging.example.com/ --browser --wait-for="#app main" --format=json --output=bfsg.json
```

## From PHP

`Browser\BrowserAnalyzer` only renders; the analysis is the job of `Bfsg`:

```php
use ItsJustVita\LaravelBfsg\Browser\BrowserAnalyzer;
use ItsJustVita\LaravelBfsg\Browser\BrowserRenderFailed;
use ItsJustVita\LaravelBfsg\Facades\Bfsg;

$url = 'https://app.example.com/dashboard';
$browser = app(BrowserAnalyzer::class);

try {
    $html = $browser->render($url, ['engine' => 'chromium', 'timeout' => 30000, 'waitFor' => '#app main']);
} catch (BrowserRenderFailed $e) {
    report($e);

    return;
}

$result = Bfsg::analyze($html, ['url' => $url, 'fragment' => false]);
$warnings = $browser->warnings();   // stylesheets that were not inlined
```

`render()` options: `engine`, `headless`, `timeout` (milliseconds), `waitFor`, `ignoredSelectors` (default `bfsg.ignored_selectors`), `inlineStylesheets` (default `bfsg.fetch.inline_stylesheets`), `maxStylesheets`, `maxStylesheetBytes`.

## Troubleshooting

- **"Playwright is not installed in …"**: run `npm install --save-dev playwright` in the directory named in the message (your application root), then `npx playwright install chromium`.
- **"Executable doesn't exist"**: the browser binaries are missing or belong to another Playwright version; run `npx playwright install chromium` again after upgrading Playwright.
- **Timeouts**: raise `--timeout`, or point `--wait-for` at an element that appears reliably. Pages that keep polling the network may not reach "network idle" before the deadline.
- **The report misses content**: check that `--wait-for` matches an element that only exists after your app rendered, and that the content does not need a click.
