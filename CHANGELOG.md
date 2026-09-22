# Changelog

Alle bemerkenswerten Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.0.0/),
und dieses Projekt verwendet [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased] — 3.0.0 (branch v3)

Phases 1 (foundation) and 2 (analyzer round) of v3. See UPGRADE.md (written in Phase 4) for the migration guide.

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
- `bfsg:analyze` and the browser mode now run the full analyzer registry (all 16 analyzers) instead of hard-coded subsets, and print registry keys (`images`, `forms`, …) instead of class names.
- `isAccessible()` ignores notices.
- Analyzer round (spec Appendix A): every check re-keyed, re-rated and re-scoped. Findings on hidden subtrees (`hidden`, `aria-hidden`, inline or stylesheet `display:none`, `<template>`) are skipped; document-level checks are skipped for fragments; enumerated attributes are compared case-insensitively.
- Removed checks: `forms.form_missing_name`, `forms.required_missing_aria_required`, `contrast.light_gray_inline`, `aria.label_conflict`, `links.missing_href`, `language.no_html_element`, `media.audio_missing_controls`, `semantic.missing_nav`, `semantic.missing_header`, `semantic.missing_footer`, `semantic.div_ratio`, `semantic.anchor_as_button`, `error_handling.css_only_error_indicators`, `status_messages.no_live_region`.
- Severity changes: `headings.skipped_level` error → warning; `headings.missing_h1`, `headings.short_heading`, `images.possibly_decorative`, `links.url_as_text`, `links.new_window_unannounced`, `links.missing_noopener`, `links.download_unannounced`, `links.adjacent_duplicate`, `keyboard.missing_skip_link`, `keyboard.negative_tabindex_on_interactive`, `keyboard.mouse_only_handler`, `aria.redundant_role`, `tables.missing_caption`, `tables.nested_table`, `semantic.section_without_heading`, `semantic.button_with_href`, `page_title.long_title`, `media.video_missing_audio_description`, `media.embedded_video_captions_unknown`, `error_handling.no_error_strategy` → notice; `links.non_descriptive`, `keyboard.dialog_missing_aria_modal`, `media.video_missing_controls`, `tables.th_missing_scope` → warning.
- Rule changes: `semantic.missing_main` cites 2.4.1 (was 1.3.1), `semantic.section_without_heading` 1.3.1 (was 2.4.6), `semantic.button_with_href` 4.1.2 + tag `best-practice`, `keyboard.dialog_missing_aria_modal` 4.1.2 (was 2.1.2), `links.non_descriptive` no longer cites 2.4.9, `headings.missing_h1` no longer cites 2.4.6, `media.autoplay_with_audio` no longer cites 2.2.2, `error_handling.no_error_strategy` no longer cites 3.3.3; `contrast.insufficient` cites 1.4.6 with tag `aaa` for AAA-only failures under `compliance_level=AAA`.
- `contrast.insufficient` params are `ratio`, `required`, `foreground`, `background` (hex); `content` was removed. `headings.multiple_h1` params are `content` only. `aria.dangling_idref` and `tables.dangling_headers_ref` report one finding per element (all ids in `meta`).
- `Css\CssParser` rewritten: tokenizer, `@media` (screen/all only), `@layer`/`@supports`/`@container` unwrapped, cascade by `!important`, specificity and source order, a documented selector subset, 148 named colours, `rgb/hsl` in both syntaxes, alpha compositing, gradients (first stop, approximate). Inherited colours are exact; only unresolvable values (`var()`, `currentColor`, …) and an overflowing rule index mark a result approximate.

### Added
- `Bfsg::register()/forget()/only()/except()`, middleware alias `bfsg`, container binding `Bfsg::class`.
- `Dom\HtmlDocument`, `Dom\Element`, `Dom\AccessibleName`, `Dom\Roles`, `Dom\Text`, `Css\Color`, `Reports\ScoreCalculator`, `Persistence\ReportRepository`.
- CI matrix for Laravel 12 and 13 on PHP 8.2–8.4.
- New checks: `images.area_missing_alt`, `images.svg_missing_name`, `images.suspicious_alt`, `forms.button_missing_name`, `forms.radio_group_missing_legend`, `forms.required_not_indicated`, `contrast.analysis_truncated`, `aria.abstract_role`, `aria.duplicate_id`, `links.non_descriptive_in_context`, `links.pseudo_link`, `keyboard.skip_link_target_missing`, `keyboard.role_without_tabindex`, `language.empty_lang`, `language.unknown_lang`, `media.autoplay_without_pause`, `page_title.multiple_titles`, `input_purpose.autocomplete_off_on_personal_field`, `status_messages.empty_aria_live`, `status_messages.alert_without_live_region`.
- `bfsg.compliance_level=AAA` now drives the contrast thresholds (7:1 / 4.5:1).
- Fixture corpus (`tests/Fixtures`) with expected keys for Bootstrap, Tailwind v4, TYPO3, a Laravel form, an ARIA data grid and a card pattern.

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