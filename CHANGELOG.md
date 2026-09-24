# Changelog

Alle bemerkenswerten Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.0.0/),
und dieses Projekt verwendet [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased] — 3.0.0 (branch v3)

Phases 1 (foundation), 2 (analyzer round) and 3 (core fixes) of v3. See UPGRADE.md (written in Phase 4) for the migration guide.

### Changed (breaking)
- `Bfsg::analyze()` returns an `AnalysisResult`; violations are `Violation` value objects with a stable translation `key`, a single primary `rule` (`1.1.1`), `related` criteria, `tags`, `element`, `selector`, `snippet`, `params` and `meta`. `->toArray()` yields the JSON shape.
- Every analyzer implements `Contracts\Analyzer` (via `BaseAnalyzer`). Custom analyzers register through `Bfsg::register()`.
- All violation messages are translation keys with English and German texts (`lang/en`, `lang/de`, publish tag `bfsg-lang`).
- Aggregated `count` findings, `stats` arrays and the `severity`/`critical` keys are gone; every finding points at one element.
- `Services\` namespace removed (`Dom\HtmlDocument`, `Css\CssParser`, `Css\Color`).
- `Services\AuthenticatedHttpClient` moved to `Http\AuthenticatedHttpClient`.
- Config: `auto_fix`, `reporting.enabled`, `reporting.email`, `authentication.sanctum_enabled`, `authentication.timeout` removed; `locale` and `scoring.weights` added.
- `laravel/mcp` is optional (`suggest`); the MCP server registers only when it is installed.
- The MCP `list_analyzers` payload now carries `name`, `class`, `description`, `rules` (array of criteria such as `1.1.1`) and `enabled` per analyzer, instead of the previous `wcag_rules` string.
- Score: configurable weights, rounding instead of truncation, grade capped at B with any error and at D with five or more errors.
- The retired `critical` severity is counted as `error` (weight 5 instead of 10); historical scores in `bfsg_reports` are not comparable with v3 scores.
- Every finding is now per element — the aggregated "multiple h1", "mixed tabindex", "positive tabindex" and "light gray inline" counts became one finding per element, and the combined "new window + noopener" link finding became two findings.
- `bfsg:analyze` is removed; `bfsg:check --browser` renders the page with Playwright (`--engine`, `--headless`, `--timeout`, `--wait-for`) and analyzes it with the full registry. `BrowserAnalyzer` moved to `Browser\BrowserAnalyzer` and only renders (`render(string $url, array $options): string`).
- `bfsg:check` exit codes: `0` threshold met, `1` threshold exceeded (`--fail-on=error|warning|notice|none`, default `error`; `--min-score=`), `2` operational error (invalid option, fetch, login, browser, database). v2 failed on any finding and returned 1 for every error.
- `bfsg:check --format=json|markdown` writes only the report to stdout; status lines go to stderr. `html`/`pdf` (and any format with `--output=`) are written to a file whose path is printed on stderr. `--verify-ssl` is replaced by `--insecure`; unknown formats, analyzers (`--only`/`--except`) and option values exit 2 instead of falling back silently.
- `bfsg:check` validates its options before fetching anything and exits 2 with the reason on stderr for: unknown options (`--failon=warning`), a malformed or untranslated `--locale`, an `--only`/`--except` selection that leaves no analyzer, and ignored option combinations (`--browser` with authentication, `--as`, `--insecure`, `--allow-login-page` or `--login-url`, browser options without `--browser`, `--as` with an authentication option, `--guard` without `--as`, `--email`/`--password`/`--username-field`/`--password-field` without `--auth`/`--sanctum`, `--json-auth` without `--auth`, `--api-key-header` without `--api-key`). `-q` silences the status lines but still writes the report to stdout.
- `bfsg:check` fetches pages of the application itself (a path, or a URL on the origin of `app.url`: same scheme, host and port) in-process through the HTTP kernel; the `.test` / `server.php` detour is gone. `--as=<id|email>` (with `--guard=`) checks such pages as a user. Non-HTML answers (JSON, downloads) are errors, never a pass; a redirect to the login page exits 2 unless `--allow-login-page`.
- The JSON report follows the v3 contract: `url`, `package_version`, `locale`, `analyzed_at`, `analyzers`, `summary` (`total`, `errors`, `warnings`, `notices`, `score`, `grade`, `accessible`) and `violations` keyed by analyzer; empty `violations`, `params` and `meta` are JSON objects. `ReportGenerator` takes an `AnalysisResult` (`new ReportGenerator($result, $locale)`, `format()`, `render()`, `saveTo()`); the legacy array input, `setFormat()`, `generate()`, `saveToFile()` and `getStats()` are gone.
- `Http\AuthenticatedHttpClient` rewritten: one cookie jar and one request factory; `loginWithForm()` reads the `_token` field and the `XSRF-TOKEN` cookie and fails with `AuthenticationFailed` naming the cause (CSRF, invalid credentials, validation, no session); `loginWithJson()`, `loginWithSanctum()`, `withBearer()`, `withJwt()` (sent as `Authorization: Bearer`, was `JWT`), `withApiKey()`, `withHeaders()`, `withSessionCookie()`. Credentials can come from `BFSG_AUTH_EMAIL`, `BFSG_AUTH_PASSWORD`, `BFSG_AUTH_TOKEN`. `--guard` now selects the guard for `--as` (it was sent as a login form field).
- Credentials are bound to one origin (scheme, host and port): `withBearer()`, `withJwt()`, `withApiKey()` and `withHeaders()` take an optional `$origin` (default: the requested URL, bound before any redirect is followed), tokens from `loginWithJson()`/`loginWithSanctum()` are bound to the login origin, and `withSessionCookie()` is a host-only cookie of its origin (it is no longer in the Guzzle jar, `cookies()`). A redirect to another host, another port or from https to http drops them. `AuthenticatedHttpClient::origin()` treats a bare host as `https://host` and rejects non-http(s) input.
- Login success is detected strictly: a redirect back to the login page, or a 2xx answer that still shows the login form (a password field, the configured password field or a form posting to the login URL), is `credentials`; a redirect to a two-factor challenge, or JSON with `two_factor: true`, fails with the new reason `two_factor`; JSON answers with `ok: false`, `success: false` or `errors` are `credentials`; JSON logins without a token need a new or changed session cookie.
- Fetched pages are limited to 5 MiB (`UrlFetcher::MAX_PAGE_BYTES`, in-process pages too) and inlined stylesheets to `fetch.max_stylesheet_bytes`; larger responses are aborted while streaming (`FetchFailed` / an inliner warning). TLS verification stays on unless `fetch.verify_ssl` is explicitly false (null, `''` and unparsable values mean on).
- Middleware analyzes in `terminate()` after the response is sent (in `handle()` only with `app.debug`, for the `X-BFSG-Violations` header), skips XHR, Livewire and Inertia requests and non-`Illuminate\Http\Response` responses, logs one line with counts per severity (warning, or info for notice-only pages) on `bfsg.middleware.log_channel`, and stores every analyzed page (clean pages too) when `save_to_database` is on. Log lines and stored reports carry the page URL without its query string (`$request->url()`, was `fullUrl()`), so tokens in query strings do not end up in logs or the database. The default `middleware.ignored_paths` also skip `reset-password/*` and `password/reset/*`, whose paths carry Laravel's reset tokens.
- MCP tools fetch through `UrlFetcher` (paths of the app in-process), limited to `bfsg.mcp.allowed_hosts` with TLS verification per `bfsg.mcp.verify_ssl` (the `verify_ssl` tool argument is gone). With `allowed_hosts` null or `[]` any public host is allowed and a private-network guard refuses every non-public address (see Security); a list of hosts governs every URL and redirect hop on its own. An invalid or untranslated `locale` argument is a tool error before anything is fetched; `analyze_html`, `analyze_url` return the JSON report and accept `locale`; `generate_report` returns `summary` instead of `stats`; `list_analyzers` includes custom analyzers; annotations `readOnlyHint` / `openWorldHint`.
- Blade component: register/use `<x-bfsg-accessible-image>`; it renders a bare `<img>` (a `<figure>` only with `caption`), throws `InvalidArgumentException` without an alt text unless `:decorative="true"`, renders decorative images as `alt="" aria-hidden="true"` (no `role="presentation"`; an `aria-hidden` or `role` passed by the caller is dropped), throws `InvalidArgumentException` for a decorative image with a `caption` (a captioned image is content), and no longer adds `loading="lazy"` by default.
- The unused report labels `bfsg::report.issues_found` and `bfsg::report.snippet` are removed (en, de); published translations may drop them.
- `bfsg_violations` gains `key`, `fingerprint` and `context` (run `php artisan migrate`); `ReportRepository::store()` runs in one transaction. Published migrations keep their file names.
- `aria.dangling_idref` is an error only when `aria-labelledby` or `aria-activedescendant` dangles; dangling description, error-message, details, controls, owns or flowto references are warnings (Blade `@error` markup references the message before it exists).
- `page_title.generic_title` is a notice for "Home | Acme"-style titles (generic page segment next to the site name) and stays a warning for fully generic titles.
- `links.non_descriptive` skips language switchers (`hreflang`/`lang`); page-number links inside navigation are judged in context (notice).
- `input_purpose.missing_autocomplete` ignores search fields and `name` fields of things (`product_name`, `category[name]`, …).
- `isAccessible()` ignores notices.
- Analyzer round (spec Appendix A): every check re-keyed, re-rated and re-scoped. Findings on hidden subtrees (`hidden`, `aria-hidden`, inline `display:none`/`visibility:hidden`, `<template>`) are skipped; stylesheet-based hiding is honoured by `contrast` and `aria.hidden_focusable` only; document-level checks are skipped for fragments; enumerated attributes are compared case-insensitively.
- `contrast.insufficient` is a warning (not an error) when the measurement is approximate (unresolved `var()`, gradients, images, a truncated rule index); definite failures stay errors.
- `aria.redundant_role` no longer flags `role="list"` on `ul`/`ol`/`menu` or `role="listitem"` on `li` (Safari/VoiceOver list-style workaround).
- `td` inside a `table` with role `grid`/`treegrid` has the implicit role `gridcell` (so `aria-selected` on it is supported); `th` there stays `columnheader`/`rowheader`.
- Accessible names skip unrendered descendants (`hidden`, inline `display:none`/`visibility:hidden`) unless reached through `aria-labelledby`.
- Removed checks: `forms.form_missing_name`, `forms.required_missing_aria_required`, `contrast.light_gray_inline`, `aria.label_conflict`, `links.missing_href`, `language.no_html_element`, `media.audio_missing_controls`, `semantic.missing_nav`, `semantic.missing_header`, `semantic.missing_footer`, `semantic.div_ratio`, `semantic.anchor_as_button`, `error_handling.css_only_error_indicators`, `status_messages.no_live_region`.
- Severity changes: `headings.skipped_level` error → warning; `headings.missing_h1`, `headings.short_heading`, `images.possibly_decorative`, `links.url_as_text`, `links.new_window_unannounced`, `links.missing_noopener`, `links.download_unannounced`, `links.adjacent_duplicate`, `keyboard.missing_skip_link`, `keyboard.negative_tabindex_on_interactive`, `keyboard.mouse_only_handler`, `aria.redundant_role`, `tables.missing_caption`, `tables.nested_table`, `semantic.section_without_heading`, `semantic.button_with_href`, `page_title.long_title`, `media.video_missing_audio_description`, `media.embedded_video_captions_unknown`, `error_handling.no_error_strategy` → notice; `links.non_descriptive`, `keyboard.dialog_missing_aria_modal`, `media.video_missing_controls`, `tables.th_missing_scope` → warning.
- Rule changes: `semantic.missing_main` cites 2.4.1 (was 1.3.1), `semantic.section_without_heading` 1.3.1 (was 2.4.6), `semantic.button_with_href` 4.1.2 + tag `best-practice`, `keyboard.dialog_missing_aria_modal` 4.1.2 (was 2.1.2), `links.non_descriptive` no longer cites 2.4.9, `headings.missing_h1` no longer cites 2.4.6, `media.autoplay_with_audio` no longer cites 2.2.2, `error_handling.no_error_strategy` no longer cites 3.3.3; `contrast.insufficient` cites 1.4.6 with tag `aaa` for AAA-only failures under `compliance_level=AAA`.
- `contrast.insufficient` params are `ratio`, `required`, `foreground`, `background` (hex); `content` was removed. `headings.multiple_h1` params are `content` only. `aria.dangling_idref` and `tables.dangling_headers_ref` report one finding per element (all ids in `meta`).
- `Css\CssParser` rewritten: tokenizer, `@media` (screen/all only), `@layer`/`@supports`/`@container` unwrapped, cascade by `!important`, specificity and source order, a documented selector subset, 148 named colours, `rgb/hsl` in both syntaxes, alpha compositing, gradients (first stop, approximate). Inherited colours are exact; only unresolvable values (`var()`, `currentColor`, …) and an overflowing rule index mark a result approximate.
- `Css\CssParser::parse()` only collects rules; the element index is built lazily and once per document (`HtmlDocument::cssParser()` shares one parser between `contrast`, `aria` and `focus`), lone class selectors are indexed through a class map, and the index build stops at the contrast time budget (results then approximate).

### Added
- `Bfsg::register()/forget()/only()/except()/keys()`, middleware alias `bfsg`, container binding `Bfsg::class`. The singleton reads `locale` and `ignored_selectors` live from the config; the registry (`checks`) is fixed when it is built.
- `Http\UrlFetcher`, `Http\FetchedPage`, `Http\FetchOptions`, `Http\FetchFailed`, `Http\InProcessFetcher`, `Http\StylesheetInliner`: same-origin `<link rel="stylesheet">` files (at most `fetch.max_stylesheets`, each at most `fetch.max_stylesheet_bytes`) are inlined so `contrast` sees them.
- Config keys `fetch.*` (`timeout`, `verify_ssl`, `user_agent`, `inline_stylesheets`, `max_stylesheets`, `max_stylesheet_bytes`), `middleware.log_channel`, `reporting.output_path`, `mcp.allowed_hosts`, `mcp.verify_ssl`.
- `bfsg:check` options `--output`, `--fail-on`, `--min-score`, `--only`, `--except`, `--locale`, `--insecure`, `--no-inline-css`, `--allow-login-page`, `--as`, `--browser` (with `--engine`, `--headless`, `--timeout`, `--wait-for`); localized CLI output (`bfsg::report.cli.*`).
- Markdown report view (`bfsg::reports.markdown`); the HTML report follows the report locale (`<html lang>`, labels from `bfsg::report`), shows the installed package version and passes the package's own analyzers.
- Factories for `BfsgReport` and `BfsgViolation`; `ReportRepository::isMigrated()`.
- Colour resolution: custom properties declared on `:root`, `html`, `:host` or `<html style>` (with `var()` fallbacks and nesting) and `oklch()`/`oklab()` colours, so Tailwind v4 utilities are measured exactly. A custom property that another rule overrides (`.dark`, `html.dark`, a conditional `@media`/`@supports`/`@container` root rule) makes the measurement approximate; `var()` expansion is capped (8 levels, 4096 bytes, memoised), so nested properties cannot expand exponentially.
- `Support\Locale`: locale validation (`validate()`, `isWellFormed()`: `de`, `en_US`, `es_419`, `sr_Latn_RS`, `zh-Hans-CN`; no path characters) for input from outside (`--locale`, the MCP `locale` argument), and a never-failing fallback for the configured locale (`default()`: `bfsg.locale`, `app.locale`, `app.fallback_locale`, then `en`, the first with a translation; `sanitize()`). A locale counts as available when the package ships it or the app publishes `lang/vendor/bfsg/<locale>/report.php`; reports never claim a locale they are not rendered in.
- `Http\PrivateNetworkGuard`, `Http\ResponseTooLarge`; `FetchFailed` reasons `privateAddress`, `malformedAddress`, `unsupportedHost`, `unresolvable`, `tooLarge`, `error`; `AuthenticationFailed::twoFactor()`.
- `Dom\HtmlDocument`, `Dom\Element`, `Dom\AccessibleName`, `Dom\Roles`, `Dom\Text`, `Css\Color`, `Reports\ScoreCalculator`, `Persistence\ReportRepository`.
- CI matrix for Laravel 12 and 13 on PHP 8.2–8.4.
- CI live smoke test (`tests/Live/`): installs the checkout into a fresh `laravel/laravel` app (PHP 8.4, with dompdf, laravel/mcp and Playwright/Chromium), publishes all tags, migrates, serves fixture pages and checks `bfsg:check` (including `--browser` and a form login behind HTTP basic auth), the middleware and the MCP server end to end.
- `bfsg:check --browser` inlines the same-origin stylesheets of the rendered page, read from the browser's CSSOM, as `<style data-bfsg-inlined>` (at most `fetch.max_stylesheets`, each at most `fetch.max_stylesheet_bytes`), and gives `<style>` elements that CSS-in-JS fills through `insertRule` their rules as text, so `contrast` measures single-page apps like fetched pages. `--no-inline-css` (now allowed with `--browser`) and `fetch.inline_stylesheets` switch it off; sheets that are skipped become `Warning:` status lines (`BrowserAnalyzer::warnings()`).
- New checks: `images.area_missing_alt`, `images.svg_missing_name`, `images.suspicious_alt`, `forms.button_missing_name`, `forms.radio_group_missing_legend`, `forms.required_not_indicated`, `contrast.analysis_truncated`, `aria.abstract_role`, `aria.duplicate_id`, `links.non_descriptive_in_context`, `links.pseudo_link`, `keyboard.skip_link_target_missing`, `keyboard.role_without_tabindex`, `language.empty_lang`, `language.unknown_lang`, `media.autoplay_without_pause`, `page_title.multiple_titles`, `input_purpose.autocomplete_off_on_personal_field`, `status_messages.empty_aria_live`, `status_messages.alert_without_live_region`.
- `bfsg.compliance_level=AAA` now drives the contrast thresholds (7:1 / 4.5:1).
- Fixture corpus (`tests/Fixtures`) with expected keys for Bootstrap, Tailwind v4, TYPO3, a Laravel form, an ARIA data grid and a card pattern.

### Fixed
- Installs in a fresh Laravel 13 app without downgrading Guzzle: Guzzle is required as `guzzlehttp/guzzle ^7.8.2 || ^8.0` and `guzzlehttp/psr7 ^2.6.2 || ^3.0` (was `guzzlehttp/guzzle ^7.8`; the package uses Guzzle's cookie jar and PSR-7 classes directly). Install the branch as `itsjustvita/laravel-bfsg:3.x-dev` (Composer normalises branch `v3` to `3.x-dev`; `dev-v3` does not resolve).
- `bfsg:mcp-server` works with laravel/mcp 1.x (it crashed with a `StdioTransport` TypeError): the command starts the server through laravel/mcp's `Registrar` (handle `bfsg`), which builds the stdio transport for the installed version. Supported: `laravel/mcp ^0.6.4 || ^1.0`.
- MCP tools carry the documented snake_case names (`analyze_html`, `analyze_url`, `check_contrast`, `list_analyzers`, `get_history`, `get_report`, `generate_report`); they were exposed as kebab-case (`analyze-html`, …) before.
- `bfsg:history --trend --limit=N` shows the latest N reports in chronological order (it showed the oldest N).
- `forms` scales linearly with the number of controls (4000 labelled required inputs: 5.1 s → 0.05 s): `label[for]` is resolved through a per-document map (`HtmlDocument::labelsFor()`), and a form's legends/description are collected once per form.
- `bfsg:check --format=json` printed status lines before the JSON; `--format=markdown` printed the CLI output; every `*.test` (Herd) URL passed because a missing `server.php` produced a 200 fatal-error page that was analyzed as a fragment.
- The HTML report was always `lang="de"`, showed "v1.5.0" and failed its own contrast checks; the MCP server reported version 2.1.0. Versions now come from Composer.
- The Blade component copied every attribute onto both `<figure>` and `<img>` and rendered a missing alt as `alt=""`.
- `bfsg:history` prints whole-number scores and reports the number of rows `delete()` removed.
- CSS rules whose rightmost compound names a class or id (`.card .title`, `#nav a.active`) are matched per candidate element instead of by one document query each (2000 descendant rules on 2000 elements: 3.2 s → under 1 s).
- Snippets of findings on large containers (`body`, `main`) no longer serialize the whole subtree.
- Closing libxml-unknown void elements (`track`, `source`, `wbr`, `embed`, `keygen`) no longer touches `<script>`, `<style>`, `<textarea>`, `<title>` or comments.
- `language.possible_language_change` ignores URLs and e-mail addresses; `error_handling` ignores hidden `formnovalidate` controls.
- CSS rule bucketing uses the parsed tokens of the rightmost compound, so `#id` and `.class` inside attribute values or `:not()` (`a[href$=".pdf"]`, `p:not(#intro)`, escaped Tailwind classes such as `.bg-\[\#fff\]`) no longer put a rule into the wrong bucket.
- Cut snippets end with `…` instead of closing tags; closing unknown void elements matches exact element names (`<source>` inside `<script-loader>`, not `<style-guide>`).
- `input_purpose.invalid_autocomplete` is reported inside search forms again (only the missing-autocomplete check skips search); the `hreflang`/`lang` exemption of `links.non_descriptive` covers language names only ("click here" with `hreflang` is still reported); generic title segments are recognised on either side ("Acme | Home").
- The JSON, HTML and Markdown reports show the package version without a leading `v` (`3.0.0`, not `v3.0.0`); Markdown reports escape page-controlled text and keep snippets inside their code spans; default report file names carry a random suffix so two reports in the same second do not overwrite each other; a PDF without dompdf fails before any directory is created.
- `bfsg:check --browser` uses one deadline for launch, navigation and `--wait-for`; a process timeout is a `BrowserRenderFailed` (exit 2) and the temporary script (mode 0600) is always removed.
- `bfsg:check` / MCP: a fetch of this application runs isolated: guards, session store, session drivers and the request instance of the caller are restored afterwards (a logged-in caller stays logged in, `url()` is unchanged, no session data leaks either way), and the request-rebinding callbacks of guards created by the fetch are dropped, so a long-running MCP server does not accumulate them.
- Same-app detection compares the origin (scheme, host and port) with `app.url`; after a hop to a remote host, redirects back into the application go over HTTP and never through the kernel with `--as`; every redirect hop must be an http(s) URL with a host (`file:`, `javascript:` are refused); malformed URLs and in-process exceptions are `FetchFailed` (exit 2) instead of uncaught errors.
- Stylesheet inlining scans linearly (large inline scripts no longer hit the PCRE backtrack limit) and never inlines `<link>` tags inside comments, `script`, `style`, `template`, `noscript`, `textarea` or `title`; stylesheets served from `public/` must be `.css` files and are size-checked before they are read.
- An in-process fetch restores the caller's app locale, so a page that calls `app()->setLocale()` no longer switches a long-running MCP server to its language.
- `bfsg:check --save` and MCP `generate_report` store the URL without query string and fragment (like the middleware), cut to the 255-character `url` column; a long URL no longer fails the save after the report was written.
- Stored URLs also drop credentials (`user:pass@`, for programmatic `ReportRepository::store()` too), and `bfsg:history --url` and MCP `get_history` compare the given URL in its stored form, so a pasted URL with query string, fragment or credentials finds its reports.
- `bfsg:history` prints the `php artisan migrate` hint and exits 1 when the tables are missing, instead of a raw `QueryException`.
- The middleware no longer analyzes a page a second time in `terminate()` after its debug analysis in `handle()` failed.

### Security
- MCP private-network guard (`Http\PrivateNetworkGuard`), active when `bfsg.mcp.allowed_hosts` is null or `[]`: the host of the URL and of every redirect hop (stylesheets included) is resolved (A and AAAA) and refused when any address is non-public: everything `FILTER_FLAG_GLOBAL_RANGE` rejects plus 0/8, 10/8, CGNAT 100.64/10, 127/8, 169.254/16 (cloud metadata), 172.16/12, 192.168/16, 198.18/15, 240/4, `::`, `::1`, `64:ff9b:1::/48`, `fc00::/7`, `fe80::/10`, `fec0::/10`, and IPv6 addresses embedding a non-public IPv4 address (IPv4-mapped, IPv4-compatible, NAT64 `64:ff9b::/96`, 6to4 `2002::/16`). Numeric host forms (`0x7f000001`, `0177.0.0.1`, `2130706433`, `127.1`) are parsed like `inet_aton()` and classified without a lookup; malformed numeric hosts, names that are not plain ASCII (letters, digits, dots, hyphens; IDNs in punycode) and names that do not resolve are refused (fail closed).
- Every guarded request is pinned to the vetted addresses with `CURLOPT_RESOLVE`, so a DNS answer that changes after the check (rebinding) is never connected to; without the curl handler such fetches fail closed.
- Only the origin of `app.url` (scheme, host and port) is exempt from the guard, not other ports or schemes of its host (`http://localhost:6379` is refused with `app.url=http://localhost`), and only while the page is rendered in-process: a remote page that redirects to the app origin sends that hop over HTTP, so it is guarded and pinned like any other.
- Credentials in a URL (`https://user:pass@staging.example.com/`) are used for the request only: `UrlFetcher` sends them as an `Authorization: Basic` header bound to that URL's origin (unless an Authorization header is already set) and removes them from status lines, reports (JSON, HTML, Markdown, PDF), stored URLs, `FetchFailed` messages and every other error message (`UrlFetcher::redact()`).
- `bfsg:check` binds credentials in the URL to the page's origin before `--auth`/`--sanctum` log in, so the login requests of a site behind HTTP basic auth carry them too (they failed with HTTP 401). Credentials in the URL together with another use of the Authorization header exit 2 instead of being dropped silently: `--bearer`, `--jwt`, `--api-key-header=Authorization`, or an authentication that produces a bearer token (a JSON or Sanctum login answering with a token, `BFSG_AUTH_TOKEN`). New `UrlFetcher::basicAuthorization()`.

## [2.2.4] - 2026-09-18

Re-release of 2.2.3 with the CI matrix fix. The 2.2.3 tag was moved after Packagist had
already indexed it, and Packagist keeps published stable versions immutable, so 2.2.3 is
withdrawn there. Install 2.2.4.

### Fixed
- CI: the PHP 8.2 matrix job could never install PHPUnit 12 (requires PHP 8.3) and, with
  `fail-fast`, cancelled the 8.3/8.4 jobs too. PHPUnit 11 is now allowed for that leg and the
  matrix no longer fails fast.

## [2.2.3] - 2026-09-18

Hotfix release, withdrawn on Packagist in favour of 2.2.4 (same fixes). No new features, no
breaking changes.

### Fixed
- **Middleware could break downloads and analyzed the wrong responses**: `CheckAccessibility`
  inspected every GET response whose Content-Type mentioned `text/html`, including redirects
  (Symfony sets `text/html` on `RedirectResponse`), 404/500 pages, and streamed or binary
  responses whose `getContent()` returns `false`. The latter ended in
  `DOMDocument::loadHTML('')` throwing a `ValueError`, so file downloads behind the middleware
  returned HTTP 500. The middleware now only analyzes successful, non-empty HTML page responses,
  and wraps analysis in a `try/catch` so an analyzer failure is logged instead of surfacing to
  the visitor. The hard-coded fallback for `bfsg.middleware.enabled` now matches the config
  default (`false`).
- **HTML and PDF reports rendered every finding as "notice"**: the report template still read the
  legacy `severity` key although analyzers emit `type` since 2.2.0. The badge now follows `type`.
- **UTF-8 pages without `<meta charset>` were decoded as ISO-8859-1**, which garbled every umlaut
  and silently defeated the German skip-link, link-text and page-title patterns added in 2.2.0.
  All `loadHTML()` call sites now go through a shared `HtmlLoader` that adds an encoding hint
  for UTF-8 input without a declared charset and restores libxml's error handling afterwards.
- **`Bfsg::analyze('')` threw `ValueError`**; blank input now yields an empty result.
- **Sanctum login dropped the session cookie**: `Set-Cookie` headers were read via
  `Response::header()`, which joins multiple cookies with commas, so only `XSRF-TOKEN` survived
  and the login POST was answered with 419. Cookies are now read from the PSR-7 response.
- **`--verify-ssl` only applied to the final page fetch**; login and CSRF requests always
  verified, so `--auth` against a self-signed local HTTPS host failed at login.
  `AuthenticatedHttpClient::setVerifySsl()` now applies to every request.
- **`--jwt`, `--api-key` and `--api-key-header` were silently ignored** unless combined with
  `--auth`, `--bearer` or `--session`.
- **Login URL was built from the page URL instead of its origin**:
  `bfsg:check https://example.com/dashboard --auth --login-url=/admin/login` posted to
  `https://example.com/dashboard/admin/login`. The login URL (and Sanctum's `csrf-cookie`
  endpoint) is now resolved against `scheme://host[:port]`, absolute `--login-url` values are
  accepted, and the configured `bfsg.authentication.default_login_url` is honoured.
- Two `ContrastAnalyzerTest` cases left red by 2.2.2 fixed; the light-gray heuristic message is
  English again.
- README: `bfsg:history --trends` corrected to `--trend`.

## [2.2.2] - 2026-05-11

### Fixed
- **ContrastAnalyzer false positives**: the `placeholder` and `disabled` heuristics flagged every
  input with a placeholder attribute and every disabled element as a WCAG 1.4.3 warning without
  checking any actual colour. Both heuristics were removed; only the inline light-gray heuristic
  (`#999`/`#aaa`/`#bbb`/`#ccc` in a `style` attribute) remains.

## [2.2.1] - 2026-04-20

### Fixed
- **`ContrastAnalyzer` / `CssParser` performance catastrophe**: on pages with
  many text nodes and many CSS rules (typical modern marketing sites with
  Tailwind-style utility CSS) the analyzer ran an XPath query on the entire
  document for every `(element, rule)` pair via `getMatchingRules`. That is
  `O(N * M * docsize)` and caused scans of sites such as `moonflag.de` and
  `sichergutbauen.com` to hang for minutes before the worker killed them.

  `CssParser::parse()` now eagerly builds a single
  `element-path → matching-rule-indexes` map (`O(M * docsize)`), and
  `getMatchingRules()` does an `O(1)` hash lookup. Contrast analysis on a
  1100-element page drops from *effectively never finishes* to **under 70 ms**.

  Additional belt-and-braces safety: `ContrastAnalyzer` now caps processed
  text elements at 500 and enforces a 5-second soft time budget, so even
  pathological documents cannot stall the full scan. Rule-index build is
  also capped at 2000 rules to protect against sites that inline entire
  Tailwind stylesheets.

## [2.2.0] - 2026-04-20

This release addresses false-positive and noise issues surfaced when scanning real-world
German TYPO3 sites. Analyzer output is now more consistent, less noisy, and German-aware.

### Fixed
- **Field-name consistency across analyzers**: Four analyzers (`SemanticHTMLAnalyzer`,
  `LanguageAnalyzer`, `TableAnalyzer`, `MediaAnalyzer`) previously emitted `severity`
  while the other twelve emitted `type`. All sixteen analyzers now emit the `type`
  key, so downstream consumers see correct severity values instead of silently
  falling back to `notice`. The non-standard `severity: 'critical'` values have been
  canonicalised to `type: 'error'` (the highest severity used elsewhere).
- **ContrastAnalyzer XPath grouping bug**: The `light_gray_text` pattern's XPath
  (`//*[@style and contains(...) or contains(...) or ...]`) was parsed with `or` at
  a higher level than intended, which caused the selector to match beyond the
  intended subtree. The `or` chain is now wrapped in parentheses.
- **`ReportGenerator`, `CheckAccessibility` middleware, `BfsgCheckCommand`, MCP
  `GenerateReport` tool**: all now read `type` first, falling back to `severity`
  for backwards compatibility with anyone still emitting the legacy key.

### Changed
- **Empty `<ul>` / `<ol>` detection**: downgraded from `error` to `notice`, and skipped
  entirely when the list carries a class/`data-*` attribute/role suggesting JS
  population (carousel, swiper, slider, slick, owl, menu, dropdown, tabs, nav,
  pagination, tree, listbox, tablist, menubar, navigation). Previously this rule
  produced high-severity false positives on virtually every modern site.
- **`<a>` without `href`** (KeyboardNavigationAnalyzer): downgraded from `error` to
  `warning`; anchors with `tabindex="0"` plus either a keyboard handler
  (`onkeydown` / `onkeyup` / `onkeypress`) or `role="button"` are now recognised
  as keyboard-accessible and no longer flagged.
- **StatusMessageAnalyzer**: the "page has interactive elements but no aria-live
  region" finding has been downgraded from `warning` to `notice` to reduce noise.
- **LinkAnalyzer external-link findings**: when an external `target="_blank"` link
  both lacks a "new window" warning and lacks `rel="noopener noreferrer"`, the
  analyzer now emits **one combined finding** (was two separate findings per link,
  which inflated counts). Links missing only one of the two concerns still produce
  their respective single finding.

### Added
- **German skip-link detection** (KeyboardNavigationAnalyzer): recognises
  "Überspringen", "Zum Inhalt", "Zum Hauptinhalt", "Zur Navigation", "Zum Menü",
  "Inhalt springen" in addition to the English patterns. Matching uses
  `mb_stripos` to correctly handle umlauts.
- **German non-descriptive link text** (LinkAnalyzer): `NON_DESCRIPTIVE_TEXTS`
  extended with "hier klicken", "hier", "klicken", "mehr", "mehr erfahren",
  "weiterlesen", "weiter", "lesen", "jetzt", "los", "herunterladen".
- **German generic page titles** (PageTitleAnalyzer): `$genericTitles` extended
  with "Startseite", "Willkommen", "Seite", "Dokument", "Unbenannt", "Neu",
  "Beispiel".
- 24 new unit tests covering every fix above (253 tests total, up from 229).

## [2.1.0] - 2026-03-31

### Added
- **MCP Server**: Built-in Model Context Protocol server with 7 accessibility analysis tools for AI assistant integration
- `bfsg:mcp-server` Artisan command for stdio-based MCP server
- Tools: analyze_html, analyze_url, check_contrast, list_analyzers, get_history, get_report, generate_report

## [2.0.0] - 2026-03-30

### Added
- **5 new WCAG 2.1 analyzers**: PageTitleAnalyzer (2.4.2), InputPurposeAnalyzer (1.3.5), FocusAnalyzer (2.4.7), ErrorHandlingAnalyzer (3.3.1/3.3.3), StatusMessageAnalyzer (4.1.3)
- **CSS-based contrast analysis**: New CssParser service that parses `<style>` blocks, resolves cascade/specificity/inheritance for accurate color contrast checking beyond inline styles
- **PDF report generation**: Full PDF support via barryvdh/laravel-dompdf with `--format=pdf` option
- **Database persistence**: BfsgReport and BfsgViolation Eloquent models with publishable migration for tracking violations over time
- **`bfsg:history` command**: View stored reports, filter by URL, show score trends, cleanup old reports
- **Laravel 13 support**
- Comprehensive test suite: 215 tests, 424 assertions (up from 60 tests)

### Changed
- **AuthenticatedHttpClient** rewritten from `file_get_contents`/`stream_context_create` to Laravel's Http facade for better testability and reliability
- **BfsgCheckCommand** and **AnalyzeUrlCommand** now use Laravel Http facade
- **ContrastAnalyzer** extended to check CSS classes and inherited styles, not just inline styles
- **Middleware `storeViolations()`** fully implemented with score calculation
- **`saveResults()` in BfsgCheckCommand** fully implemented

### Fixed
- All previously skipped tests now pass
- BrowserAnalyzer temp file cleanup guaranteed via try/finally

## [1.5.1] - 2025-10-28

### Hinzugefügt
- **Ignored Selectors Configuration** - Filter third-party widgets from analysis
  - New `ignored_selectors` config option for CSS selectors
  - BrowserAnalyzer removes ignored elements before accessibility analysis
  - Pre-configured for Chatbase and other common widgets
  - Useful for analytics scripts, chat widgets, and third-party iframes

### Verbessert
- BrowserAnalyzer now respects `ignored_selectors` config
- Cleaner analysis results by excluding non-content elements

## [1.5.0] - 2025-10-28

### Hinzugefügt
- **TableAnalyzer** - Umfassende Tabellenbarrierefreiheit
  - Prüfung von `<caption>` Elementen für Tabellenbeschreibungen
  - Validierung von `<th>` mit `scope`-Attributen (`row`/`col`)
  - Erkennung von Tabellen ohne Header-Zellen
  - Unterscheidung zwischen Layout- und Datentabellen
  - Validierung komplexer Tabellenbeziehungen mit `headers` Attribut

- **MediaAnalyzer** - Audio/Video Barrierefreiheit
  - Prüfung von Video-Untertiteln (`<track kind="captions">`)
  - Validierung von Audio-Transkript-Referenzen
  - Erkennung problematischer `autoplay`-Attribute
  - Überprüfung auf `controls`-Attribute
  - YouTube iframe Untertitel-Parameter (`cc_load_policy`)

- **SemanticHTMLAnalyzer** - Semantische HTML-Struktur
  - Validierung von Landmark-Elementen (`<main>`, `<nav>`, `<header>`, `<footer>`)
  - Erkennung von "div-itis" (übermäßige `<div>` Nutzung >40%)
  - Prüfung korrekte Verwendung von `<button>` vs `<a>`
  - Validierung von Überschriften in `<section>` Elementen
  - Überprüfung von Listen-Strukturen

- **Report-System** - Professionelle Accessibility-Reports
  - `ReportGenerator` Klasse für mehrere Ausgabeformate
  - **HTML-Reports** mit Compliance-Score (0-100%) und Grades (A+ bis F)
  - **JSON-Reports** für CI/CD Integration
  - **Markdown-Reports** für Dokumentation
  - Detaillierte Statistiken (Critical, Errors, Warnings, Notices)
  - Report-Speicherung in `storage/app/bfsg-reports/`

- **SPA-Testing Guide** - Umfassende Dokumentation
  - Neue `SPA-TESTING.md` Dokumentation
  - Playwright Setup und Installation Anleitung
  - Browser-Konfiguration (Chromium, Firefox, WebKit)
  - Timeout und Wait-Selector Beispiele
  - CI/CD Integration Guides

### Verbessert
- README.md komplett überarbeitet
  - Klare Trennung zwischen `bfsg:check` (Full-Featured) und `bfsg:analyze` (SPA Support)
  - Alle 11 Analyzer dokumentiert (4 neue mit ⭐ NEW Badge)
  - Report-Generation Sektion mit Beispielen
  - SPA-Testing Sektion mit Playwright-Anleitung
  - Config-Beispiel mit allen 11 Analyzern
- Test-Suite auf 60 Tests erweitert (103 Assertions)
- Alle Tests angepasst für semantisches HTML mit `<header>`, `<main>`, `<footer>`

### Technische Details
- 3 neue Analyzer-Klassen (Table, Media, SemanticHTML)
- Report-System mit HTML/JSON/Markdown Templates
- Blade-Template für HTML-Reports (`resources/views/reports/html.blade.php`)
- Integration in `BfsgCheckCommand` für `--format=html` und `--format=json`
- Compliance-Score Algorithmus mit gewichteten Issue-Typen

## [1.4.0] - 2025-10-06

### Hinzugefügt
- **LanguageAnalyzer** für WCAG 3.1.1 und BFSG §3 Compliance
  - Prüfung des `lang`-Attributs auf dem `<html>`-Element
  - Validierung von ISO 639-1 Sprachcodes (inkl. Region-Codes wie "en-US")
  - Erkennung von Sprachwechseln im Content
  - Überprüfung von `xml:lang` Attributen
- **CONTRIBUTING.md** - Umfassende Dokumentation für Contributors
  - Code Standards und Guidelines
  - Testing Best Practices
  - Pull Request Prozess
- **GitHub Actions CI/CD Pipeline** (`.github/workflows/tests.yml`)
  - Automatische Tests auf PHP 8.2, 8.3, 8.4
  - Laravel 12 Kompatibilitätstests
  - Code-Style-Checks mit Pint
- **Development Tools**
  - `.gitignore` für sauberes Repository
  - `.gitattributes` für Export-Optimierung
  - Laravel Pint als dev-dependency

### Verbessert
- LanguageAnalyzer zu `AnalyzeUrlCommand` hinzugefügt
- Test-Suite erweitert: `lang`-Attribute in allen Test-HTML-Beispielen ergänzt
- Alle 60 Tests laufen erfolgreich durch (103 Assertions)
- Konsistente Fehlerbehandlung in allen Analyzern
- Vollständige Testabdeckung für alle 8 Analyzer

### Behoben
- Fehlgeschlagene Tests durch fehlende lang-Attribute (BfsgTest.php)
- CONTRIBUTING.md Referenz in README.md jetzt valide

## [1.3.0] - 2025-09-21

### Hinzugefügt
- **Automatische Herd-Domain-Erkennung**
  - Automatische Erkennung von `.test` Domains (Laravel Herd)
  - Temporärer PHP-Server auf verfügbarem Port (8100-8199)
  - Sauberes Server-Shutdown nach Check
  - Nahtlose BFSG-Prüfung von lokalen Herd-Sites

## [1.2.0] - 2025-09-20

### Hinzugefügt
- **Browser-Engine-Integration für SPA-Unterstützung**
  - Neue `BrowserAnalyzer`-Klasse mit Playwright-Integration
  - Unterstützung für JavaScript-gerenderte Inhalte (React, Vue, Inertia)
  - Neues Artisan-Command: `php artisan bfsg:analyze {url}`
  - `--browser` Flag für Browser-basierte Analyse
  - `--headless=false` Option für sichtbaren Browser beim Debugging
- **Umfassende Test-Suite**
  - Tests für alle Analyzer-Komponenten
  - Tests für BrowserAnalyzer
  - Tests für Artisan-Commands
  - 58 erfolgreiche Tests mit 103 Assertions

### Verbessert
- Konsistente Rückgabestruktur aller Analyzer mit `['issues' => [...]]` Format
- Fallback-Mechanismen für fehlende Keys in Analyzer-Ergebnissen
- Verbesserte Fehlerbehandlung in Commands

### Behoben
- "Undefined array key 'rule'" Fehler in BfsgCheckCommand
- Inkonsistente Datenstrukturen zwischen Analyzern und Hauptklasse
- Fehlende 'issues' Key-Behandlung in Bfsg-Klasse

## [1.1.0] - 2025-09-20

### Hinzugefügt
- KeyboardNavigationAnalyzer für umfassende Tastaturzugänglichkeitstests
  - Erkennung von fokussierbaren Elementen
  - Analyse der Tab-Reihenfolge
  - Erkennung von Tastatur-Fallen
  - Überprüfung von visuellen Fokusindikatoren
  - Analyse von Skip-Links
  - Validierung von ARIA-Attributen
  - Kompatibilität mit dynamischen Inhalten

### Geändert
- Verbesserte Testabdeckung für Analyzer-Komponenten
- Erweiterte Dokumentation mit Beispielen für KeyboardNavigationAnalyzer

## [1.0.0] - 2025-09-20

### Hinzugefügt
- Initiale Veröffentlichung des Laravel BFSG Accessibility Package
- Grundlegende BFSG/WCAG-Konformitätsprüfungen
- Automatisierte Accessibility-Analysen
- Laravel-Integration mit Service Provider und Facade
- Umfassende Testsuite
- MIT-Lizenz