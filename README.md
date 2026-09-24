# Laravel BFSG

[![Latest Version on Packagist](https://img.shields.io/packagist/v/itsjustvita/laravel-bfsg.svg?style=flat-square)](https://packagist.org/packages/itsjustvita/laravel-bfsg)
[![Tests](https://img.shields.io/github/actions/workflow/status/itsjustvita/laravel-bfsg/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/itsjustvita/laravel-bfsg/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/itsjustvita/laravel-bfsg.svg?style=flat-square)](https://packagist.org/packages/itsjustvita/laravel-bfsg)
[![License](https://img.shields.io/packagist/l/itsjustvita/laravel-bfsg.svg?style=flat-square)](LICENSE.md)

Accessibility checks for Laravel applications, built around the German Barrierefreiheitsstärkungsgesetz (BFSG) and WCAG 2.1 level AA. The package analyzes HTML with 16 analyzers and reports every finding with a stable key, a severity, the WCAG success criterion, the element, and a message in English or German. Use it on the command line and in CI (`bfsg:check`), as middleware, from PHP, or from an AI assistant through its MCP server.

Automated checks find a part of the accessibility problems of a page, not all of them. A clean report does not prove BFSG conformance. Use it to catch regressions early, and test with real users and assistive technology as well.

Upgrading from 2.x? Read [UPGRADE.md](UPGRADE.md).

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [bfsg:check](#bfsgcheck)
- [Checking pages behind a login](#checking-pages-behind-a-login)
- [Browser mode for single-page apps](#browser-mode-for-single-page-apps)
- [bfsg:history](#bfsghistory)
- [Middleware](#middleware)
- [Programmatic API](#programmatic-api)
- [Custom analyzers](#custom-analyzers)
- [Reports and score](#reports-and-score)
- [Localization](#localization)
- [Persistence](#persistence)
- [MCP server](#mcp-server)
- [Blade component](#blade-component)
- [Configuration reference](#configuration-reference)
- [Analyzers](#analyzers)
- [Limitations](#limitations)
- [Development](#development)

## Requirements

- PHP 8.2 or higher with the `dom`, `libxml` and `mbstring` extensions
- Laravel 12 or 13
- Optional: `laravel/mcp` (`^0.6.4 || ^1.0`) for the MCP server
- Optional: `barryvdh/laravel-dompdf` (`^3.0`) for PDF reports
- Optional: Node.js with Playwright for `bfsg:check --browser`

## Installation

```bash
composer require itsjustvita/laravel-bfsg:^3.0
```

The service provider and the `Bfsg` facade are discovered automatically. The package loads its migrations itself, so create the tables for stored reports with:

```bash
php artisan migrate
```

Everything else is optional to publish:

| Tag | Publishes | When you need it |
|---|---|---|
| `bfsg-config` | `config/bfsg.php` | to change settings beyond the environment variables |
| `bfsg-lang` | `lang/vendor/bfsg/{en,de}` | to reword messages or add a locale |
| `bfsg-views` | `resources/views/vendor/bfsg` | to change the HTML/Markdown report or the Blade component |
| `bfsg-migrations` | the migrations, under their original file names | to keep the migrations in your repository |

```bash
php artisan vendor:publish --tag=bfsg-config
```

## Quick start

```bash
# A page of this application: rendered in-process through the HTTP kernel, no web server needed
php artisan bfsg:check /contact

# Any page on the web
php artisan bfsg:check https://example.com

# The same, as JSON for a script, failing only on errors (the default)
php artisan bfsg:check https://example.com --format=json > bfsg.json
```

The CLI output lists the findings per analyzer and ends with a summary line:

<!-- docs: cli-output -->
```text
Checking http://localhost/contact
images (1)
  [Error] WCAG 1.1.1 Image without text alternative (/produkt.jpg)
      Suggestion: Add an alt attribute that describes the image, or alt="" if it is purely decorative

links (1)
  [Warning] WCAG 2.4.4 Link text "hier klicken" does not describe the destination (/more)
      Suggestion: Use link text that names the destination, or add an aria-label that does

2 findings (1 errors, 1 warnings, 0 notices), score 93 of 100, grade B
Failed: findings with severity Error or higher
```

## bfsg:check

```text
php artisan bfsg:check [options] [--] [<url>]
```

`url` is an absolute `http`/`https` URL or a path of this application (default `/`). Paths, and URLs on the origin of `app.url` (same scheme, host and port), are rendered in-process through the HTTP kernel of the running application: no web server, no network, and `--as` can act as a user. Every other URL is fetched over HTTP; redirects are followed (at most five), and same-origin `<link rel="stylesheet">` files are inlined so the contrast analyzer sees them.

### Exit codes

| Code | Meaning |
|---|---|
| `0` | The threshold is met (no finding at or above `--fail-on`, score not below `--min-score`) |
| `1` | The threshold is exceeded |
| `2` | Operational error: unknown or invalid option, an option combination that would be ignored, fetch or login failure, a redirect to the login page, Playwright missing, tables missing for `--save` |

Status lines ("Checking …", warnings, the summary) go to stderr. With `--format=json` or `--format=markdown` stdout carries only the report, so `php artisan bfsg:check / --format=json | jq .summary` works; `-q` silences the status lines but never the report. When you call the command with `Artisan::call()`, the default buffered output has no stderr, so the status lines end up in the same buffer as the report: pass `--output=<file>` to get the report alone.

### Options

Output and thresholds:

| Option | Default | Description |
|---|---|---|
| `--format=` | `cli` | `cli`, `json`, `markdown`, `html` or `pdf`. `html` and `pdf` are always written to a file |
| `--output=` | | Write the report to this file (not with `cli`). Without it, `html`/`pdf` go to `bfsg.reporting.output_path` |
| `--fail-on=` | `error` | Exit 1 on findings of this severity or higher: `error`, `warning`, `notice` or `none` |
| `--min-score=` | | Exit 1 when the score is below this value (0-100) |
| `--detailed` | | CLI output: also print element, selector and HTML snippet of every finding |
| `--save` | | Store the report in the database (needs `php artisan migrate`) |

Analyzers and language:

| Option | Description |
|---|---|
| `--only=` | Comma-separated analyzer keys to run, for example `--only=images,forms` |
| `--except=` | Comma-separated analyzer keys to skip |
| `--locale=` | Locale of messages and reports (`en`, `de`, or a locale your app provides). Default: `bfsg.locale`, then `app.locale` |

Fetching:

| Option | Description |
|---|---|
| `--insecure` | Do not verify TLS certificates. Only for hosts you control with self-signed certificates: with `--insecure`, credentials and tokens are sent without verifying the server |
| `--no-inline-css` | Do not inline same-origin stylesheets (also with `--browser`) |
| `--allow-login-page` | Analyze the page even when the URL redirected to the login page (otherwise exit 2) |
| `--login-url=` | Login page, absolute or relative to the checked site. Default: `bfsg.authentication.default_login_url` |

Authentication (see [Checking pages behind a login](#checking-pages-behind-a-login)):

| Option | Description |
|---|---|
| `--as=` | For pages of this application: act as the user with this id or email (in-process) |
| `--guard=` | Guard used by `--as` |
| `--auth` | Log in first with a form login (`--email`, `--password`) |
| `--email=`, `--password=` | Credentials for `--auth` and `--sanctum` |
| `--username-field=`, `--password-field=` | Field names of the login form (default `email`, `password`) |
| `--json-auth` | With `--auth`: log in with a JSON request; a token in the answer becomes the bearer token |
| `--sanctum` | Log in through Laravel Sanctum (`/sanctum/csrf-cookie`, then the login URL) |
| `--bearer=`, `--jwt=` | Send `Authorization: Bearer <token>` |
| `--api-key=`, `--api-key-header=` | Send an API key header (default header `X-API-Key`) |
| `--session=` | Send an existing session cookie, given as `name=value` |

Browser (see [Browser mode](#browser-mode-for-single-page-apps)):

| Option | Default | Description |
|---|---|---|
| `--browser` | | Render the page with Playwright before the analysis |
| `--engine=` | `chromium` | `chromium`, `firefox` or `webkit` |
| `--headless=` | `true` | `false` shows the browser window |
| `--timeout=` | `30000` | One deadline in milliseconds for launch, navigation and `--wait-for` |
| `--wait-for=` | `body` | CSS selector to wait for before the DOM is taken |

Options that would be silently ignored in a combination are an error (exit 2) instead: browser options without `--browser`, `--browser` with any login option, `--insecure`, `--allow-login-page` or `--login-url`, `--as` with a remote authentication option, `--guard` without `--as`, `--email`/`--password`/`--username-field`/`--password-field` without `--auth` or `--sanctum`, `--json-auth` without `--auth`, and `--api-key-header` without `--api-key`.

### Examples

```bash
# Fail the build on warnings too, and on a score below 90
php artisan bfsg:check / --fail-on=warning --min-score=90

# Only contrast and forms, German messages, with element details
php artisan bfsg:check /checkout --only=contrast,forms --locale=de --detailed

# HTML report for stakeholders
php artisan bfsg:check https://example.com --format=html --output=storage/app/bfsg/home.html

# Keep the result for bfsg:history
php artisan bfsg:check https://example.com --save
```

### In CI

Paths are rendered in-process, so a CI job needs no web server:

```yaml
- name: Accessibility check
  run: |
    php artisan migrate --force
    php artisan bfsg:check / --format=json --output=bfsg-home.json
    php artisan bfsg:check /contact --fail-on=warning
```

## Checking pages behind a login

**Pages of this application:** `--as=<id|email>` renders the page in-process as that user (with `--guard=` for another guard). Nothing goes over the network and no password is needed.

```bash
php artisan bfsg:check /dashboard --as=admin@example.com
```

**Remote pages** (or pages of this application fetched over HTTP, which every remote authentication option forces):

```bash
# Form login: reads the CSRF token, posts the credentials, keeps the session cookie
php artisan bfsg:check https://staging.example.com/dashboard --auth --email=qa@example.com --password=secret

# Credentials from the environment (BFSG_AUTH_EMAIL / BFSG_AUTH_PASSWORD, or BFSG_AUTH_TOKEN as bearer token)
php artisan bfsg:check https://staging.example.com/dashboard --auth

# Sanctum SPA login, JSON login, tokens, an existing session
php artisan bfsg:check https://app.example.com/dashboard --sanctum --email=qa@example.com --password=secret
php artisan bfsg:check https://api.example.com/profile --auth --json-auth --email=qa@example.com --password=secret
php artisan bfsg:check https://api.example.com/profile --bearer=token
php artisan bfsg:check https://app.example.com/dashboard --session="laravel_session=eyJpdiI6..."
```

Without `--email`/`--password` in an interactive terminal, `--auth` asks for them. A failed login exits 2 with its cause (CSRF token rejected, invalid credentials, validation error, two-factor challenge, no session). A page that redirects to the login page exits 2 unless you pass `--allow-login-page`.

In CI, pass credentials as `BFSG_AUTH_EMAIL`, `BFSG_AUTH_PASSWORD` or `BFSG_AUTH_TOKEN` from your CI secrets rather than `--password`, `--bearer` or `--session` on the command line: command-line arguments end up in the shell history, job logs and the process list.

Credentials are bound to one origin (scheme, host and port): tokens, API keys and session cookies are only sent to the checked site (or, for a login token, the login site), and a redirect to another host, another port or from `https` to `http` drops them.

**HTTP basic auth** (a protected staging site): put the credentials in the URL. They become an `Authorization: Basic` header for that origin, also for the login requests of `--auth`/`--sanctum`, and are removed from every status line, report and stored URL.

```bash
php artisan bfsg:check "https://deploy:s3cret@staging.example.com/dashboard" --auth --email=qa@example.com --password=secret
```

There is only one `Authorization` header, so credentials in the URL cannot be combined with `--bearer`, `--jwt`, `--api-key-header=Authorization`, or a login that answers with a bearer token (`--json-auth` or `--sanctum` returning a token, `BFSG_AUTH_TOKEN`): these combinations exit 2. Pages of this application are rendered in-process and see no basic-auth header; for routes behind `auth.basic` use `--as`.

## Browser mode for single-page apps

Pages that build their content with JavaScript (React, Vue, Inertia without SSR) look empty to a plain fetch. `--browser` renders them with Playwright first:

```bash
npm install --save-dev playwright
npx playwright install chromium

php artisan bfsg:check https://app.example.com/dashboard --browser --wait-for="#app main"
```

Same-origin stylesheets of the rendered page are inlined from the browser's CSSOM (so are `<style>` elements that CSS-in-JS libraries fill through `insertRule`), unless you pass `--no-inline-css`. `--browser` does not share a login and ignores TLS options, so it cannot be combined with the authentication options, `--insecure`, `--allow-login-page` or `--login-url`. See [SPA-TESTING.md](SPA-TESTING.md) for setup, CI and troubleshooting.

## bfsg:history

Stored reports (`--save`, the middleware, the MCP `generate_report` tool) can be listed, followed over time and cleaned up:

```bash
php artisan bfsg:history                                   # the latest 20 reports
php artisan bfsg:history --url=https://example.com/pricing --limit=50
php artisan bfsg:history --url=https://example.com/pricing --trend
php artisan bfsg:history --cleanup --days=90               # delete reports older than 90 days
```

| Option | Default | Description |
|---|---|---|
| `--url=` | | Only reports of this URL. It is compared in its stored form (without query string, fragment and credentials), so you can paste the URL you checked |
| `--limit=` | `20` | Number of reports |
| `--trend` | | The latest reports of `--url` in chronological order, with the score change |
| `--cleanup` | | Delete reports older than `--days` |
| `--days=` | `30` | Age limit for `--cleanup` |

The command exits 1 with the `php artisan migrate` hint when the tables are missing, and when `--trend` is given without `--url`.

## Middleware

The middleware analyzes HTML pages your application serves, after the response has been sent (`terminate()`), so visitors never wait for it. It is registered under the alias `bfsg` and is off until you enable it:

```dotenv
BFSG_MIDDLEWARE_ENABLED=true
```

Use it on some routes:

```php
use Illuminate\Support\Facades\Route;

Route::middleware('bfsg')->group(function () {
    Route::view('/', 'welcome');
    Route::view('/contact', 'contact');
});
```

or on every web route in `bootstrap/app.php`:

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
use ItsJustVita\LaravelBfsg\Middleware\CheckAccessibility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [CheckAccessibility::class]);
    })
    ->create();
```

It analyzes successful `GET` responses with an HTML body and skips XHR, Livewire and Inertia requests, redirects, downloads, streamed responses, the paths in `bfsg.middleware.ignored_paths`, and the in-process requests of `bfsg:check` and the MCP server. For each page with findings it logs one line with the counts per severity (`warning`, or `info` when there are only notices) on `bfsg.middleware.log_channel`:

<!-- docs: log-line -->
```text
BFSG: 4 violations on https://example.com/contact {"errors":1,"warnings":2,"notices":1}
```

With `bfsg.reporting.save_to_database` every analyzed page is stored, clean pages too. URLs are logged and stored without their query string. With `app.debug` on, the analysis runs before the response is sent and adds an `X-BFSG-Violations` header with the number of findings. The middleware never breaks a page: failures are logged with `Log::error`.

## Programmatic API

<!-- docs: run -->
```php
use ItsJustVita\LaravelBfsg\Facades\Bfsg;

$result = Bfsg::analyze('<main><img src="hero.jpg"><a href="/more">click here</a></main>');

foreach ($result->all() as $violation) {
    echo $violation->severity->value.' '.$violation->rule.' '.$violation->key.': '.$violation->message().PHP_EOL;
}

echo count($result).' findings, accessible: '.($result->isAccessible() ? 'yes' : 'no').PHP_EOL;
```

<!-- docs: output -->
```text
error 1.1.1 images.missing_alt: Image without text alternative (hero.jpg)
warning 2.4.4 links.non_descriptive: Link text "click here" does not describe the destination (/more)
2 findings, accessible: no
```

`Bfsg::analyze(string $html, array $options = [])` returns an `AnalysisResult`. Options: `url`, `locale`, `fragment` (`null` detects: input without `<html>` is a fragment, and document-level checks such as the page title are skipped), `ignoredSelectors` (default `bfsg.ignored_selectors`).

`AnalysisResult`: `all()`, `byAnalyzer()`, `forAnalyzer('images')`, `count()`, `countBySeverity()` (`['error' => …, 'warning' => …, 'notice' => …]`), `hasErrors()`, `isAccessible()` (no errors and no warnings; notices do not count), `analyzersRun()`, `url()`, `locale()`, `toArray()`, and `json_encode()` support.

Each `Violation` is a readonly value object:

| Property | Example | Notes |
|---|---|---|
| `analyzer` | `images` | registry key |
| `key` | `images.missing_alt` | stable; match on this, never on the message |
| `severity` | `Severity::Error` | `Error`, `Warning` or `Notice` |
| `rule` | `1.1.1` | primary WCAG success criterion; `null` only for non-WCAG findings (tag `security`) |
| `related` | `['1.3.1']` | further criteria |
| `tags` | `['aaa']` | `best-practice`, `aaa`, `security`, `approximate` |
| `element`, `selector`, `snippet` | `img.hero`, `/html[1]/body[1]/img[1]`, `<img src="hero.jpg" class="hero">` | `null` for document-level findings |
| `params`, `meta` | `['src' => 'hero.jpg']` | message placeholders; analyzer extras such as a contrast ratio |
| `autoFixable` | `false` | `true` only for mechanical fixes |

Methods: `message(?string $locale = null)`, `suggestion(?string $locale = null)`, `fingerprint()` (stable id from analyzer, key, rule and selector), `toArray(?string $locale = null)`.

Run a subset, or check a document you already parsed:

<!-- docs: run -->
```php
use ItsJustVita\LaravelBfsg\Dom\HtmlDocument;
use ItsJustVita\LaravelBfsg\Facades\Bfsg;

$html = '<!DOCTYPE html><html lang="en"><head><title>Contact – Example</title></head><body><main><h1>Contact</h1></main></body></html>';

$formsOnly = Bfsg::only(['forms', 'input_purpose'])->analyze($html);
$withoutContrast = Bfsg::except(['contrast'])->analyze($html, ['locale' => 'de']);
$result = Bfsg::analyzeDocument(HtmlDocument::fromHtml($html), ['url' => 'https://example.com/contact']);

echo Bfsg::isAccessible($html) ? 'accessible' : 'not accessible';
```

`only()` and `except()` return a copy and leave the application-wide registry untouched.

## Custom analyzers

Extend `BaseAnalyzer`, implement `inspect()` and call `report()` for every finding:

<!-- docs: run -->
```php
namespace App\Bfsg;

use ItsJustVita\LaravelBfsg\Analyzers\BaseAnalyzer;
use ItsJustVita\LaravelBfsg\Severity;

class MarqueeAnalyzer extends BaseAnalyzer
{
    protected string $key = 'marquee';

    protected string $description = 'Moving content in <marquee>';

    protected array $rules = ['2.2.2'];

    protected function inspect(): void
    {
        foreach ($this->queryVisible('//marquee') as $element) {
            $this->report('moving_content', Severity::Error, '2.2.2', $element, ['text' => $this->text($element)]);
        }
    }
}
```

Register it once, in the `boot()` method of a service provider:

<!-- docs: run -->
```php
use App\Bfsg\MarqueeAnalyzer;
use ItsJustVita\LaravelBfsg\Facades\Bfsg;

Bfsg::register('marquee', MarqueeAnalyzer::class);
```

From then on it runs everywhere: `bfsg:check` (also `--only=marquee`), the middleware, the MCP tools and `Bfsg::analyze()`. `BaseAnalyzer` provides `query()`, `queryVisible()` (skips hidden elements), `isHidden()`, `name()` (accessible name), `text()`, `ownText()` and `isFragment()`; `report()` derives element, selector and snippet from the element. A class that implements `ItsJustVita\LaravelBfsg\Contracts\Analyzer` (`key()`, `analyze(HtmlDocument $document): array`) works too.

Messages are translation keys (`bfsg::violations.marquee.moving_content.message` and `.suggestion`). Add them next to the package's own in `lang/vendor/bfsg/en/violations.php` (and `de`, …), otherwise the raw key is shown:

```php
<?php

return [
    'marquee' => [
        'moving_content' => [
            'message' => 'Moving content in <marquee>: ":text"',
            'suggestion' => 'Remove the marquee, or give users a way to pause and stop it',
        ],
    ],
];
```

## Reports and score

`--format` and `ReportGenerator` produce four formats: `json`, `markdown`, `html` (a self-contained page in the report locale that passes the package's own analyzers) and `pdf` (needs `barryvdh/laravel-dompdf`).

<!-- docs: run -->
```php
use ItsJustVita\LaravelBfsg\Facades\Bfsg;
use ItsJustVita\LaravelBfsg\Reports\ReportGenerator;

$result = Bfsg::analyze('<main><img src="hero.jpg"></main>', ['url' => 'https://example.com/']);
$report = new ReportGenerator($result, 'de');

$json = $report->format('json')->render();
$html = $report->format('html')->render();
echo $report->score().' '.$report->grade();
```

`saveTo($path)` writes the rendered report and returns the path; `defaultPath()` is a file name under `bfsg.reporting.output_path`.

The JSON report is a stable contract. For `https://example.com/pricing`, a page with `lang`, a title, a heading and one image without `alt`:

<!-- docs: json-report -->
```json
{
    "url": "https://example.com/pricing",
    "package_version": "3.0.0",
    "locale": "en",
    "analyzed_at": "2026-09-24T10:00:00+00:00",
    "analyzers": [
        "images", "forms", "headings", "contrast", "aria", "links", "keyboard", "language",
        "tables", "media", "semantic", "page_title", "input_purpose", "focus", "error_handling", "status_messages"
    ],
    "summary": {"total": 1, "errors": 1, "warnings": 0, "notices": 0, "score": 95, "grade": "B", "accessible": false},
    "violations": {
        "images": [
            {
                "id": "8234b16bb4d47385726cc633887ae210ae453184",
                "analyzer": "images",
                "key": "images.missing_alt",
                "severity": "error",
                "rule": "1.1.1",
                "related": [],
                "tags": [],
                "message": "Image without text alternative (hero.jpg)",
                "suggestion": "Add an alt attribute that describes the image, or alt=\"\" if it is purely decorative",
                "element": "img",
                "selector": "/html[1]/body[1]/main[1]/img[1]",
                "snippet": "<img src=\"hero.jpg\">",
                "params": {"src": "hero.jpg"},
                "meta": {},
                "auto_fixable": false
            }
        ]
    }
}
```

**Score:** `score = max(0, round(100 − Σ weight))` with the weights from `bfsg.scoring.weights` (default: error 5, warning 2, notice 0.5 per finding).

**Grade:** A+ from 95, A from 90, B+ from 85, B from 80, C+ from 75, C from 70, D from 60, F below. A page with at least one error gets at most B, with five or more errors at most D.

## Localization

Messages, suggestions, CLI status lines and reports are available in English (`en`) and German (`de`). The locale is, in this order: `--locale` / the MCP `locale` argument / `Bfsg::analyze($html, ['locale' => 'de'])`, then `bfsg.locale` (`BFSG_LOCALE`), then `app.locale`. A configured locale without translations falls back to `app.fallback_locale`, then `en`; an explicit `--locale` or MCP `locale` without translations is an error.

To reword messages or add a language, publish the translations (`php artisan vendor:publish --tag=bfsg-lang`) and edit or copy `lang/vendor/bfsg/<locale>/violations.php` and `report.php`. A locale counts as available for `--locale` once `lang/vendor/bfsg/<locale>/report.php` exists.

## Persistence

`bfsg_reports` holds one row per report (URL, score, grade, number of findings, metadata), `bfsg_violations` one row per finding (analyzer, key, severity, rule, message, suggestion, element, fingerprint, and a JSON `context` with selector, snippet, params, meta, related criteria and tags). The models are `ItsJustVita\LaravelBfsg\Models\BfsgReport` and `BfsgViolation`; `ItsJustVita\LaravelBfsg\Persistence\ReportRepository::store()` is the only writer.

<!-- docs: run -->
```php
use ItsJustVita\LaravelBfsg\Facades\Bfsg;
use ItsJustVita\LaravelBfsg\Models\BfsgReport;
use ItsJustVita\LaravelBfsg\Persistence\ReportRepository;

$result = Bfsg::analyze('<main><img src="hero.jpg"></main>', ['url' => 'https://example.com/?utm_source=mail']);
$report = app(ReportRepository::class)->store($result, ['source' => 'nightly']);

$history = BfsgReport::forUrl('https://example.com/')->latest()->get();
```

URLs are stored without query string, fragment and credentials (at most 2048 characters); `BfsgReport::forUrl()` and `bfsg:history --url` normalise the URL you give the same way. Set `bfsg.reporting.database.connection` (`BFSG_DB_CONNECTION`) to keep the tables on another connection; the migrations follow it.

## MCP server

The MCP server lets an AI assistant run the checks. It needs `laravel/mcp`:

```bash
composer require laravel/mcp
```

Register it with your client, for example Claude Code:

```bash
claude mcp add bfsg -- php artisan bfsg:mcp-server
```

or in `.mcp.json` in the project root:

```json
{
    "mcpServers": {
        "bfsg": {
            "command": "php",
            "args": ["artisan", "bfsg:mcp-server"]
        }
    }
}
```

`bfsg:mcp-server` registers the server with laravel/mcp under the handle `bfsg`, so `Mcp::local('bfsg', \ItsJustVita\LaravelBfsg\Mcp\BfsgMcpServer::class)` in `routes/ai.php` together with `php artisan mcp:start bfsg` works as well. With Laravel Boost installed, the tools are added to Boost's MCP server automatically.

| Tool | Arguments | Result |
|---|---|---|
| `analyze_html` | `html`, `locale` | the JSON report of the HTML |
| `analyze_url` | `url` (a URL, or a path of this application), `locale` | the JSON report of the page |
| `check_contrast` | `foreground`, `background` | ratio and AA/AAA pass flags |
| `list_analyzers` | | key, class, description, WCAG criteria and enabled state of every analyzer |
| `get_history` | `url`, `limit` | stored reports |
| `get_report` | `report_id` | one stored report with its findings |
| `generate_report` | `url`, `format` (`json`, `markdown`, `html`, `pdf`), `locale`, `save` | the summary and the rendered report (a file path for `pdf`) |

**Network access.** `analyze_url` and `generate_report` fetch what the assistant asks for, so they are restricted:

- `bfsg.mcp.allowed_hosts`: a list of hostnames. When set, the list replaces the public-host default: only the listed hosts are fetched (a public host that is not listed is refused), and every URL and every redirect hop must be on one of them. Listed hosts are trusted as they are, without the private-network check and address pinning below, so list only hosts whose DNS you control.
- When it is `null` (the default) or empty, any public host is allowed and a private-network guard refuses every non-public address: loopback, private and link-local ranges (including the cloud metadata address `169.254.169.254`), CGNAT, reserved ranges, IPv6 unique-local and site-local, and IPv6 forms that embed such an IPv4 address. Numeric host spellings (`0x7f000001`, `127.1`) are recognised, hosts that do not resolve are refused, and each request is pinned to the addresses that were checked, so DNS rebinding cannot redirect it. Pinning needs PHP's curl extension; without it such fetches fail.
- Pages of this application (paths, or the exact origin of `app.url`) are rendered in-process and exempt; a remote page that redirects to the application is guarded like any other hop.
- TLS verification follows `bfsg.mcp.verify_ssl` only; the assistant cannot switch it off.

## Blade component

```blade
<x-bfsg-accessible-image src="/img/team.jpg" alt="Our team of five in front of the office" width="600" />

<x-bfsg-accessible-image src="/img/lake.jpg" alt="Mountain lake at sunrise" caption="Photo: Anna Example" />

<x-bfsg-accessible-image src="/img/divider.svg" :decorative="true" />
```

The component renders a bare `<img>` with your extra attributes, or a `<figure>` with `<figcaption>` when `caption` is given. It throws an `InvalidArgumentException` when the alt text is empty and the image is not marked `:decorative="true"`, so a content image can never end up with `alt=""` by accident. Decorative images get `alt="" aria-hidden="true"` (a `role` or `aria-hidden` you pass is dropped); a decorative image cannot have a caption. `loading` is only set when you pass it (`loading="lazy"`). Pass `decorative` as a boolean binding (`:decorative="true"`); the string `decorative="false"` would be true in PHP.

## Configuration reference

All settings live in `config/bfsg.php` (`php artisan vendor:publish --tag=bfsg-config`).

| Key | Env | Default | Description |
|---|---|---|---|
| `bfsg.compliance_level` | `BFSG_LEVEL` | `AA` | `AA` (the BFSG requirement) or `AAA` (contrast 7:1 / 4.5:1; AAA-only findings are notices tagged `aaa`) |
| `bfsg.locale` | `BFSG_LOCALE` | `null` | Locale of messages and reports; `null` = `app.locale` |
| `bfsg.checks` | | all `true` | Analyzer key => enabled; the registry is built from it once per application |
| `bfsg.ignored_selectors` | | chat widget selectors | CSS selectors removed before the analysis (third-party widgets), in every entry point |
| `bfsg.scoring.weights` | | `error` 5, `warning` 2, `notice` 0.5 | Score deduction per finding |
| `bfsg.fetch.timeout` | `BFSG_FETCH_TIMEOUT` | `30` | HTTP timeout in seconds |
| `bfsg.fetch.verify_ssl` | `BFSG_VERIFY_SSL` | `true` | TLS verification; only an explicit false turns it off (`--insecure` per run) |
| `bfsg.fetch.user_agent` | | `laravel-bfsg/3.0 (+https://github.com/itsjustvita/laravel-bfsg)` | User agent of every request |
| `bfsg.fetch.inline_stylesheets` | `BFSG_INLINE_CSS` | `true` | Inline same-origin stylesheets (fetch and `--browser`) |
| `bfsg.fetch.max_stylesheets` | | `5` | Stylesheets inlined per page |
| `bfsg.fetch.max_stylesheet_bytes` | | `524288` | Largest stylesheet inlined |
| `bfsg.authentication.default_login_url` | | `/login` | Login page for `--auth`, and the page a redirect is recognised as "login page" by |
| `bfsg.middleware.enabled` | `BFSG_MIDDLEWARE_ENABLED` | `false` | Switches the middleware on |
| `bfsg.middleware.log_violations` | | `true` | Log one line per page with findings |
| `bfsg.middleware.log_channel` | | `null` | Log channel; `null` = the default channel |
| `bfsg.middleware.ignored_paths` | | `admin/*`, `api/*`, `_debugbar/*`, `livewire/*`, `telescope/*`, `horizon/*`, `reset-password/*`, `password/reset/*` | Paths the middleware skips (`Request::is()` patterns) |
| `bfsg.reporting.save_to_database` | `BFSG_SAVE_TO_DB` | `false` | Middleware: store every analyzed page |
| `bfsg.reporting.database.connection` | `BFSG_DB_CONNECTION` | `null` | Connection of the report tables; `null` = default |
| `bfsg.reporting.output_path` | | `storage/app/bfsg-reports` | Directory of `html`/`pdf` reports written without `--output` |
| `bfsg.mcp.allowed_hosts` | | `null` | Hosts the MCP tools may fetch; `null` = any public host (see [MCP server](#mcp-server)) |
| `bfsg.mcp.verify_ssl` | | `true` | TLS verification of the MCP tools |

## Analyzers

| Key | Checks | WCAG 2.1 |
|---|---|---|
| `images` | Text alternatives for images, image inputs, image maps and SVG | 1.1.1 |
| `forms` | Labels of form controls, names of buttons, radio groups, required fields | 4.1.2, 1.3.1, 3.3.2 |
| `headings` | Heading hierarchy and heading text | 1.3.1, 2.4.6 |
| `contrast` | Colour contrast of text (inline styles, `<style>` and inlined stylesheets, custom properties, `oklch()`) | 1.4.3, 1.4.6 |
| `aria` | ARIA roles, required and supported states, ID references, `aria-hidden` on focusable elements | 4.1.2, 1.3.1, 4.1.1 |
| `links` | Link names, link purpose, new windows, downloads, `rel="noopener"` | 2.4.4, 4.1.2, 3.2.5 |
| `keyboard` | Keyboard operability, skip links, tab order, dialogs | 2.1.1, 2.4.1, 2.4.3, 4.1.2 |
| `language` | Language of the page and of parts | 3.1.1, 3.1.2 |
| `tables` | Data table headers, scope, captions, header references | 1.3.1 |
| `media` | Captions, audio description, autoplay and controls of audio, video and embeds | 1.2.1, 1.2.2, 1.2.5, 1.4.2, 2.1.1, 2.2.2, 4.1.2 |
| `semantic` | Landmarks and document structure | 1.3.1, 2.4.1, 4.1.2 |
| `page_title` | Presence and quality of the page title | 2.4.2 |
| `input_purpose` | `autocomplete` on personal data fields | 1.3.5 |
| `focus` | Removed focus outlines without replacement | 2.4.7 |
| `error_handling` | Error identification in forms | 3.3.1 |
| `status_messages` | Live regions for status messages | 4.1.3 |

Severity policy: **error** is a definite AA failure detectable in the markup, **warning** a likely failure that needs a manual check (and contrast results that are approximate), **notice** a best practice, an AAA criterion or something that cannot be verified statically. Every finding points at one element, or at the document.

## Limitations

- The analysis works on HTML and CSS, not on a rendered page: layout, JavaScript behaviour, focus order at runtime and screen-reader output are not tested. `--browser` adds the JavaScript-built DOM, not interaction.
- The accessible-name computation covers the common cases (`aria-labelledby`, `aria-label`, native labels, alt texts, subtree text, `title`); it is not a complete implementation of the W3C algorithm.
- Contrast is measured from inline styles, `<style>` elements and inlined same-origin stylesheets with a documented CSS subset (no `:hover`, `:nth-child`, pseudo-elements). Background images, gradients and unresolved custom properties make a measurement approximate: such failures are warnings with the tag `approximate`.

## Development

```bash
composer test    # PHPUnit
composer lint    # Pint, check only
composer fix     # Pint
```

`tests/Live/` contains a smoke test that installs the package into a fresh Laravel application and exercises the commands, the middleware, the browser mode and the MCP server end to end; CI runs it on every push. See [CONTRIBUTING.md](CONTRIBUTING.md).

## Roadmap

Planned for the next releases: an ignore mechanism for known findings, diff reports between two runs, SARIF output for code scanning, axe-core in browser mode, Livewire and Filament integration, and a GitHub Action.

## BFSG

The Barrierefreiheitsstärkungsgesetz implements the European Accessibility Act in Germany and applies since 28 June 2025 to many digital products and services, including e-commerce. Its technical reference is EN 301 549, which for the web points to WCAG 2.1 level AA.

- [WCAG 2.1 quick reference](https://www.w3.org/WAI/WCAG21/quickref/)
- [BFSG (Bundesministerium für Arbeit und Soziales)](https://www.bmas.de/DE/Service/Gesetze-und-Gesetzesvorhaben/barrierefreiheitsstaerkungsgesetz.html)

## Changelog, security, license

See [CHANGELOG.md](CHANGELOG.md) for the changes of every release. Please report security issues to hello@itsjustvita.com instead of the issue tracker. The package is written by [Vitalis Feist-Wurm](https://github.com/itsjustvita) and released under the [MIT license](LICENSE.md).
