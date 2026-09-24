# Upgrading

## From 2.x to 3.0

3.0 is a rewrite of the package's core: typed results instead of nested arrays, one analyzer registry for every entry point, localized messages, a reworked analyzer set, and a `bfsg:check` command with CI-friendly exit codes. Plan for about an hour for a typical application; most of it is updating code that reads violation arrays and CI scripts that call the commands.

Requirements are unchanged: PHP 8.2+, Laravel 12 or 13.

### Checklist

1. `composer require itsjustvita/laravel-bfsg:^3.0` (and `composer require laravel/mcp` if you use the MCP server).
2. If you published the migrations in 2.x, re-publish them (`php artisan vendor:publish --tag=bfsg-migrations --force`) or delete your copy of `create_bfsg_tables.php` (section 8).
3. `php artisan migrate`.
4. Re-publish or merge the config if you published it (`php artisan vendor:publish --tag=bfsg-config --force`, then re-apply your changes).
5. Delete published views and translations you did not change (`resources/views/vendor/bfsg`, `lang/vendor/bfsg`), re-publish the ones you did change and re-apply your edits.
6. Update code that reads analysis results (section 1), CI scripts (section 4) and Blade usage of the image component (section 9).

### 1. `Bfsg::analyze()` returns an `AnalysisResult`

Findings are `Violation` objects instead of arrays, grouped by analyzer.

Before (2.x):

<!-- docs: v2 -->
```php
use ItsJustVita\LaravelBfsg\Facades\Bfsg;

$violations = Bfsg::analyze($html);

foreach ($violations as $category => $issues) {
    foreach ($issues as $issue) {
        echo $issue['type'].' '.$issue['rule'].': '.$issue['message'];   // "error WCAG 1.1.1: …"
    }
}
```

After (3.0):

<!-- docs: run -->
```php
use ItsJustVita\LaravelBfsg\Facades\Bfsg;

$result = Bfsg::analyze('<main><img src="hero.jpg"></main>');

foreach ($result->byAnalyzer() as $analyzer => $violations) {
    foreach ($violations as $violation) {
        echo $violation->severity->value.' '.$violation->rule.' '.$violation->key.': '.$violation->message().PHP_EOL;
    }
}

$array = $result->toArray();   // ['analyzers' => […], 'summary' => […], 'violations' => ['images' => [[…]]]]
```

Keys of a violation array (`$violation->toArray()`, the JSON report):

| 2.x | 3.0 |
|---|---|
| `type` | `severity` (`error`, `warning`, `notice`; `critical` no longer exists and counts as `error`) |
| `rule` (`WCAG 1.1.1`) | `rule` (`1.1.1`, no prefix; exactly one criterion, `null` for non-WCAG findings) |
| | new: `id` (stable fingerprint), `analyzer`, `key` (`images.missing_alt`), `related`, `tags`, `selector`, `snippet`, `params`, `meta` |
| `element`, `message`, `suggestion`, `auto_fixable` | unchanged names; messages are translated and reworded (section 7) |
| `count`, `stats` | removed: every finding points at one element; count with `$result->count()`, `countBySeverity()` |
| `src`, `href`, `content`, `name`, `linkText` | removed; the values an analyzer keeps are in `params` or `meta` |
| `fix_example` | removed |

The report shape (`bfsg:check --format=json`, MCP) is documented in the [README](README.md#reports-and-score).

### 2. `getViolations()` is gone; `isAccessible()` ignores notices

Before (2.x):

<!-- docs: v2 -->
```php
use ItsJustVita\LaravelBfsg\Facades\Bfsg;

if (! Bfsg::isAccessible($html)) {
    $violations = Bfsg::getViolations();
}
```

After (3.0):

<!-- docs: run -->
```php
use ItsJustVita\LaravelBfsg\Facades\Bfsg;

$result = Bfsg::analyze('<main><img src="hero.jpg"></main>');

if (! $result->isAccessible()) {
    $violations = $result->all();
}
```

`isAccessible()` is `true` when there is no error and no warning; notices (best practices, AAA) no longer make a page "not accessible". The analyzer object holds no state between calls.

### 3. Custom analyzers implement `Contracts\Analyzer` and are registered with `Bfsg::register()`

2.x had no registration API for the main registry; analyzers were duck-typed objects with `analyze(DOMDocument $dom): array` returning `['issues' => [...]]`, and only the browser analyzer accepted extra ones.

Before (2.x):

<!-- docs: v2 -->
```php
use ItsJustVita\LaravelBfsg\BrowserAnalyzer;

class MarqueeAnalyzer
{
    public function analyze(\DOMDocument $dom): array
    {
        $issues = [];

        foreach ($dom->getElementsByTagName('marquee') as $marquee) {
            $issues[] = ['type' => 'error', 'rule' => 'WCAG 2.2.2', 'element' => 'marquee', 'message' => 'Moving content'];
        }

        return ['issues' => $issues];
    }
}

(new BrowserAnalyzer)->addAnalyzer(new MarqueeAnalyzer);
```

After (3.0), in a service provider's `boot()`:

<!-- docs: run -->
```php
namespace App\Bfsg;

use ItsJustVita\LaravelBfsg\Analyzers\BaseAnalyzer;
use ItsJustVita\LaravelBfsg\Facades\Bfsg;
use ItsJustVita\LaravelBfsg\Severity;

class LegacyMarqueeAnalyzer extends BaseAnalyzer
{
    protected string $key = 'legacy_marquee';

    protected function inspect(): void
    {
        foreach ($this->queryVisible('//marquee') as $element) {
            $this->report('moving_content', Severity::Error, '2.2.2', $element);
        }
    }
}

Bfsg::register('legacy_marquee', LegacyMarqueeAnalyzer::class);
```

The analyzer then runs in `bfsg:check`, the middleware, MCP and `Bfsg::analyze()`. Its messages come from `lang/vendor/bfsg/<locale>/violations.php` (`'legacy_marquee' => ['moving_content' => ['message' => …, 'suggestion' => …]]`); see [Custom analyzers](README.md#custom-analyzers).

### 4. Commands: `bfsg:analyze` removed, exit codes, `--fail-on`, `--insecure`, pure JSON

| 2.x | 3.0 |
|---|---|
| `bfsg:analyze <url> --browser` | `bfsg:check <url> --browser` |
| `bfsg:analyze <url>` | `bfsg:check <url>` |
| `--verify-ssl=false` (the default: certificates were **not** verified) | certificates are verified; `--insecure` turns it off for one run (only for hosts you control) |
| exit 1 on any finding (notices included) and on every error | `0` threshold met, `1` threshold exceeded, `2` operational error |
| no threshold options | `--fail-on=error` (default), `warning`, `notice`, `none`; `--min-score=` |
| `--format=json` mixed status lines into stdout | stdout carries only the report for `json` and `markdown`; status lines go to stderr |
| `--format=html` / `pdf` wrote to `storage/app/bfsg-reports` | the same by default (`bfsg.reporting.output_path`), or `--output=<file>` |
| an unknown `--format` fell back to the CLI output; unknown options exited 1 | unknown options, formats, analyzers and invalid values exit 2 with the reason; so do option combinations that would be ignored |
| a JSON answer or a download was analyzed as a page | non-HTML answers (JSON, downloads) exit 2 |
| pages of any size were loaded | fetched pages larger than 5 MiB are aborted while loading (exit 2; `--browser` pages are not capped) |
| `--guard` was sent as a login form field | `--guard` selects the guard for the new `--as=<user>` |
| `--jwt` was sent as `Authorization: JWT …` | sent as `Authorization: Bearer …` |

Before (2.x), a CI step:

<!-- docs: v2 -->
```bash
php artisan bfsg:analyze https://staging.example.com --browser --verify-ssl=false
php artisan bfsg:check https://staging.example.com --format=json > report.json || true
```

After (3.0):

```bash
php artisan bfsg:check https://staging.example.com --browser
php artisan bfsg:check https://staging.example.com --insecure --format=json --fail-on=none > report.json
```

Use `--insecure` only for hosts you control with self-signed certificates: with it, credentials and tokens are sent without verifying the server.

Paths are rendered in-process now (`php artisan bfsg:check /pricing`), so CI jobs that started a web server only for the check can drop it. The `.test`/`server.php` detour of 2.x is gone.

### 5. Configuration

Re-publish the config (`php artisan vendor:publish --tag=bfsg-config --force`) and re-apply your changes, or edit your published file:

| Key | Change |
|---|---|
| `auto_fix` (`BFSG_AUTO_FIX`) | removed (it never did anything) |
| `reporting.enabled`, `reporting.email` (`BFSG_REPORTING`, `BFSG_REPORT_EMAIL`) | removed |
| `authentication.sanctum_enabled` | removed; use `--sanctum` |
| `authentication.timeout` | moved to `fetch.timeout` (`BFSG_FETCH_TIMEOUT`) |
| `compliance_level` | `AA` (default) or `AAA`; it now sets the contrast thresholds (2.x only stored it with the report) |
| `locale` | new (`BFSG_LOCALE`) |
| `scoring.weights` | new |
| `fetch.*` | new: `timeout`, `verify_ssl` (`BFSG_VERIFY_SSL`), `user_agent`, `inline_stylesheets` (`BFSG_INLINE_CSS`), `max_stylesheets`, `max_stylesheet_bytes` |
| `reporting.output_path` | new |
| `mcp.allowed_hosts`, `mcp.verify_ssl` | new |
| `middleware.log_channel` | new |
| `middleware.ignored_paths` | new defaults: `livewire/*`, `telescope/*`, `horizon/*`, `reset-password/*`, `password/reset/*` |

A config file published from 2.x keeps its old `middleware.ignored_paths`. Add at least `reset-password/*` and `password/reset/*`: Laravel's password reset links carry the reset token in the path, and the middleware would otherwise log and store those URLs.

```php
return [
    'middleware' => [
        'ignored_paths' => [
            'admin/*',
            'api/*',
            '_debugbar/*',
            'livewire/*',
            'telescope/*',
            'horizon/*',
            'reset-password/*',
            'password/reset/*',
        ],
    ],
];
```

### 6. MCP: `laravel/mcp` is optional, tools are `snake_case`

`laravel/mcp` is no longer installed with the package. Install it (`composer require laravel/mcp`; `^0.6.4` and `^1.0` are supported); without it, `bfsg:mcp-server` is not registered.

The tools are named `analyze_html`, `analyze_url`, `check_contrast`, `list_analyzers`, `get_history`, `get_report` and `generate_report` (2.x exposed them as `analyze-html`, …). Update tool allow-lists in your MCP client. Further changes: `analyze_url` and `generate_report` fetch any public host while `bfsg.mcp.allowed_hosts` is empty; a list replaces that default, so only the listed hosts are fetched (an internal staging host must be listed, and so must every public host the tools should still reach). Listed hosts are trusted as they are, without the private-network check and address pinning, so list only hosts whose DNS you control. An invalid or untranslated `locale` argument is a tool error before anything is fetched. The `verify_ssl` tool argument is gone (`bfsg.mcp.verify_ssl`), `generate_report` returns `summary` instead of `stats`, and `list_analyzers` returns `rules` as an array (was `wcag_rules`, a string).

### 7. Messages are localized and reworded

Every message and suggestion is a translation (`lang/en`, `lang/de`); the wording changed throughout. Code, tests or dashboards that matched message text must match the `key` instead:

Before (2.x):

<!-- docs: v2 -->
```php
$missingAlt = array_filter($violations['images'] ?? [], fn ($issue) => str_contains($issue['message'], 'alt'));
```

After (3.0):

<!-- docs: run -->
```php
use ItsJustVita\LaravelBfsg\Facades\Bfsg;
use ItsJustVita\LaravelBfsg\Violation;

$result = Bfsg::analyze('<main><img src="hero.jpg"></main>');
$missingAlt = array_filter($result->forAnalyzer('images'), fn (Violation $violation) => $violation->key === 'images.missing_alt');
```

The locale is `bfsg.locale`, else `app.locale`. Several checks were removed, added, re-rated or re-scoped (see the CHANGELOG), so expect different findings and counts on the same page.

### 8. Database: run `php artisan migrate`

Two migrations upgrade the 2.x tables in place and keep your data:

- `bfsg_violations` gets `key`, `fingerprint` and `context` (selector, snippet, params, meta, related criteria, tags).
- `bfsg_reports.url` becomes a text column (URLs up to 2048 characters) with an indexed `url_hash`; existing rows get their hash.

Published copies of the package migrations keep their file names, so the published and the package copy never run twice.

If you published the migrations in 2.x, re-publish them with `php artisan vendor:publish --tag=bfsg-migrations --force`, or delete your copy of `create_bfsg_tables.php`. Your 2.x copy has the same name as the package copy and takes its place, so every fresh database (CI, `RefreshDatabase` tests, a new machine) gets the 2.x tables from it, after both upgrade migrations have already run and found no table. 3.0 ships an undated `upgrade_bfsg_tables` migration that sorts after `create_bfsg_tables` and adds whatever is still missing, so such databases end up with the 3.0 schema anyway, but your repository should not keep the 2.x schema. Re-publishing is safe on existing databases: `create_bfsg_tables` is already recorded there and does not run again.

Rows written by 2.x keep a `null` key and fingerprint, and their `critical` severities stay as they were. Scores are computed differently (section 12), so 2.x and 3.0 scores of the same page are not comparable. `bfsg:check --save` and `bfsg:history` exit with the `migrate` hint until the migrations have run.

### 9. Blade component: `<x-bfsg-accessible-image>`, boolean `decorative`

The tag is `<x-bfsg-accessible-image>`. The 2.x README documented `<x-bfsg::accessible-image>`, which does not resolve to the component class; the registered tag was already `<x-bfsg-accessible-image>`. The component changed:

- A missing or empty `alt` throws an `InvalidArgumentException` unless the image is decorative; 2.x rendered `alt=""` silently.
- `decorative` must be a boolean binding: `decorative="false"` is the non-empty string `"false"`, which PHP treats as `true`, so in 2.x it made the image decorative.
- Decorative images render `alt="" aria-hidden="true"` instead of `role="presentation"`, and cannot have a caption.
- It renders a bare `<img>`; the `<figure class="bfsg-image">` wrapper only appears with `caption`, and the attributes you pass are no longer copied onto both elements.
- `loading="lazy"` is no longer added by default.

Before (2.x):

<!-- docs: v2 -->
```blade
<x-bfsg::accessible-image src="/img/logo.svg" alt="Company logo" decorative="false" />
<x-bfsg::accessible-image src="/img/divider.svg" decorative="true" />
```

After (3.0):

```blade
<x-bfsg-accessible-image src="/img/logo.svg" alt="Company logo" />
<x-bfsg-accessible-image src="/img/divider.svg" :decorative="true" />
```

If you published the component view (`resources/views/vendor/bfsg/components/accessible-image.blade.php`), delete it or re-publish it.

### 10. Namespaces: `Services\*` is gone, `BrowserAnalyzer` and `ReportGenerator` changed

| 2.x | 3.0 |
|---|---|
| `ItsJustVita\LaravelBfsg\Services\HtmlLoader::load($html)` | `ItsJustVita\LaravelBfsg\Dom\HtmlDocument::fromHtml($html)` |
| `ItsJustVita\LaravelBfsg\Services\CssParser` | `ItsJustVita\LaravelBfsg\Css\CssParser`, colours in `Css\Color` |
| `ItsJustVita\LaravelBfsg\Services\AuthenticatedHttpClient` | `ItsJustVita\LaravelBfsg\Http\AuthenticatedHttpClient` (rewritten: `loginWithForm()`, `loginWithJson()`, `loginWithSanctum()`, `withBearer()`, `withJwt()`, `withApiKey()`, `withHeaders()`, `withSessionCookie()`, `withVerifySsl()`) |
| `ItsJustVita\LaravelBfsg\BrowserAnalyzer::analyzeUrl($url)` | `ItsJustVita\LaravelBfsg\Browser\BrowserAnalyzer::render($url, $options)` returns the HTML; analyze it with `Bfsg::analyze()` |
| `new ReportGenerator($url, $violations)`, `setFormat()`, `generate()`, `saveToFile()`, `getStats()` | `new ReportGenerator($result, $locale)`, `format()`, `render()`, `saveTo($path)`, `summary()`, `score()`, `grade()` |
| analyzers: `analyze(DOMDocument $dom): array` | `analyze(HtmlDocument $document): array` returning `list<Violation>` |

`Http\AuthenticatedHttpClient` binds credentials to one origin: `withSessionCookie()` sets a host-only cookie of its origin that is no longer in the Guzzle cookie jar or `cookies()`, and `withBearer()`, `withJwt()`, `withApiKey()` and `withHeaders()` take an optional `$origin` (default: the requested URL). A redirect to another host, another port or from `https` to `http` drops them.

Before (2.x):

<!-- docs: v2 -->
```php
use ItsJustVita\LaravelBfsg\Facades\Bfsg;
use ItsJustVita\LaravelBfsg\Reports\ReportGenerator;

$report = new ReportGenerator($url, Bfsg::analyze($html));
$path = $report->setFormat('html')->saveToFile();
$stats = $report->getStats();
```

After (3.0):

<!-- docs: run -->
```php
use ItsJustVita\LaravelBfsg\Facades\Bfsg;
use ItsJustVita\LaravelBfsg\Reports\ReportGenerator;

$report = new ReportGenerator(Bfsg::analyze('<main><img src="hero.jpg"></main>', ['url' => 'https://example.com/']));
$html = $report->format('html')->render();
$summary = $report->summary();   // total, errors, warnings, notices, score, grade, accessible
```

### 11. Middleware: analysis after the response, count-only logs

The middleware is registered under the alias `bfsg` (2.x had no alias). It now analyzes in `terminate()`, after the response was sent, so pages are no longer slowed down; with `app.debug` on it still analyzes before sending to set `X-BFSG-Violations`. The log line carries the counts per severity instead of every violation, on `bfsg.middleware.log_channel`. It skips XHR, Livewire and Inertia requests, stores every analyzed page (clean ones too) when `bfsg.reporting.save_to_database` is on, and logs and stores URLs without their query string.

Before (2.x), `app/Http/Kernel.php`:

<!-- docs: v2 -->
```php
use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    protected $routeMiddleware = [
        'accessible' => \ItsJustVita\LaravelBfsg\Middleware\CheckAccessibility::class,
    ];
}
```

After (3.0), `routes/web.php`:

```php
use Illuminate\Support\Facades\Route;

Route::middleware('bfsg')->group(function () {
    Route::view('/', 'welcome');
});
```

The middleware stays inactive until `BFSG_MIDDLEWARE_ENABLED=true`. If your log alerts parse the old log context, switch them to the `errors`, `warnings` and `notices` counts.

### 12. Score and grade

`score = max(0, round(100 − Σ weight))` with configurable weights (`bfsg.scoring.weights`, default error 5, warning 2, notice 0.5; 2.x truncated instead of rounding and weighted `critical` 10). The grade is capped: any error means at most B, five or more errors at most D. Together with the reworked analyzers, scores move; compare 3.0 scores only with 3.0 scores.

### 13. Things that are easy to miss

- **In-process checks and `auth.basic`.** Pages of your application are rendered in-process; credentials in the URL are not passed to them. A route behind `auth.basic` answers 401 (exit 2): check it with `--as=<user>` instead of `https://user:pass@…`.
- **Credentials in the URL** of a remote page become a `Basic` header for that origin, also for the login of `--auth`/`--sanctum`. They cannot be combined with `--bearer`, `--jwt`, `--api-key-header=Authorization` or a login that returns a bearer token (exit 2).
- **`--sanctum` with an absolute `--login-url`** on another origin than the checked page exits 2: Sanctum logs in on the checked site and uses only the path of the login URL.
- **`--browser`** cannot be combined with authentication options, `--insecure`, `--allow-login-page` or `--login-url` (exit 2): the browser does not share them.
- **Published translations** from 2.x contain the old flat messages; delete `lang/vendor/bfsg` or re-publish it (`--tag=bfsg-lang --force`).
- **Installing a development version** of 3.x needs the constraint `3.x-dev` (the `main` branch).
