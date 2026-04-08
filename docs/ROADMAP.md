# Roadmap

High-impact improvements planned for future releases of laravel-bfsg.

## High Impact

### 1. axe-core Integration via Playwright

Integrate [axe-core](https://github.com/dequelabs/axe-core) (the industry standard accessibility testing engine from Deque) by injecting it during browser-based analysis.

**Why this matters:**
- axe-core has years of rule refinement and false-positive reduction that's impossible to match with a from-scratch analyzer
- Combines the best of both worlds: BFSG-specific rules from this package + the battle-tested axe ruleset
- Would give laravel-bfsg a credibility boost for serious enterprise audits

**How it works:**
- In `--browser` mode (via Playwright), inject `axe-core` into the rendered page
- Run `axe.run()` and collect the results
- Merge axe violations with the package's native analyzer results
- Deduplicate overlapping findings (e.g., both tools catching the same alt text issue)
- New config option to toggle axe integration on/off

**Acceptance:**
- `bfsg:check https://example.com --browser --with-axe` runs axe + native analyzers
- Violations include a `source` field (`native` or `axe-core`) for transparency
- Documentation explains when to use each mode

### 2. Ignore Mechanism

Allow developers to suppress specific false positives without disabling entire analyzer categories.

**Why this matters:**
- Without this, the package becomes unusable in large real-world codebases
- Every accessibility tool has false positives — the question is how you handle them
- Enables gradual adoption: teams can suppress existing violations and prevent new ones

**Implementation options:**
- HTML comments: `<!-- bfsg-ignore:contrast -->` on specific elements
- Inline attribute: `<div data-bfsg-ignore="contrast,aria">...</div>`
- Blade directive: `@bfsgIgnore('contrast') ... @endbfsgIgnore`
- Config-based ignores: `ignore_rules` array with URL patterns and rule keys

**Acceptance:**
- Elements marked with ignore comments/attributes are skipped by the specified analyzers
- Ignored violations don't count toward the compliance score
- `bfsg:check --show-ignored` flag displays what was skipped for transparency

### 3. Livewire Integration

Add first-class support for Livewire components, which currently aren't analyzed properly because they re-mount HTML dynamically.

**Why this matters:**
- Livewire is one of the most popular Laravel frontends
- Current middleware only catches the initial page load, missing updates triggered by Livewire actions
- Livewire-specific a11y issues (loading states, form errors) need dedicated checks

**What to build:**
- Middleware that also catches Livewire `update` responses (not just full page loads)
- Detection of Livewire components in the DOM
- Specific checks for Livewire patterns:
  - `wire:loading` states without `aria-busy`
  - `wire:click` without keyboard equivalent
  - Dynamic error messages without `aria-live`
- Support for Livewire testing (assert accessibility in Livewire component tests)

**Acceptance:**
- `CheckAccessibility` middleware analyzes Livewire update responses
- New analyzer category: `livewire` with Livewire-specific rules
- Pest/PHPUnit helper: `$this->livewireIsAccessible($component)`

### 4. GitHub Action

Publish a reusable GitHub Action at `itsjustvita/bfsg-action@v1` on the GitHub Marketplace.

**Why this matters:**
- Makes CI integration a one-liner instead of requiring manual workflow setup
- Gives the package viral distribution via the Marketplace
- Lowers adoption barrier dramatically

**What it does:**
- Action inputs: `url`, `format` (sarif/json/html), `fail-on` (severity threshold), `auth-token`
- Runs `bfsg:check` against the given URL in a Laravel context
- Outputs SARIF file for GitHub Code Scanning integration
- Can post a comment on PRs with the accessibility score and delta from main

**Acceptance:**
- Marketplace listing with verified publisher badge
- Example workflow in the README
- Works with both public and authenticated URLs
- Violations appear as annotations in PR review

## Medium Impact

### 5. Filament Plugin

A Filament v3 plugin providing a full admin dashboard for the BFSG package.

**Features:**
- Report history table with filtering and sorting
- Score trend charts (line chart showing grade over time per URL)
- Top violations widget (most common issues across all reports)
- Compare two reports side-by-side (diff view)
- Drill-down from report to individual violations
- Trigger new checks directly from the admin panel

**Why:** Filament has a large active Laravel community. A well-done plugin exposes the package to thousands of potential users.

### 6. Diff Reports

Compare two runs of `bfsg:check` against the same URL and show what changed.

**CLI:**
```bash
bfsg:history --diff --url=https://example.com --from=10 --to=20
```

**Output example:**
```
Comparing reports #10 (2026-03-20) and #20 (2026-03-31):

Score:  78% → 85% (+7)
Grade:  C+  → B+

Fixed (5 violations):
  - images: Missing alt on <img src="hero.jpg">
  - contrast: #999 on #ccc (ratio 1.84:1)
  ...

New (2 violations):
  - forms: Input without label in #signup-form
  - page_title: Title too generic ("Home")
```

**Why:** Shows teams their actual progress. Single-snapshot reports don't motivate improvement; trends do.

### 7. Blade Directive Scanner

Instead of only analyzing rendered HTML, scan Blade component files directly at the source level.

**Features:**
- `bfsg:scan-blade` command that analyzes `.blade.php` files
- Catches issues at the source where they can be fixed
- Works even for components that aren't currently rendered on any route
- Faster than full HTTP-based checking for CI

**Challenges:**
- Blade has dynamic content (`{{ $variable }}`) that can't be fully analyzed
- Need to handle directives like `@if`, `@foreach` sensibly
- Should focus on static accessibility issues (alt, labels, landmarks)

### 8. SARIF Output Format

Export violations in SARIF (Static Analysis Results Interchange Format), the standard format for security/quality tools.

**Why:**
- GitHub Code Scanning natively supports SARIF → violations show up as PR annotations
- GitLab, Azure DevOps, and many other CI platforms consume SARIF
- Industry standard for static analysis tools

**Implementation:**
- New format in `ReportGenerator`: `--format=sarif`
- Valid SARIF 2.1.0 schema output
- Includes WCAG rule references, severity levels, and file locations

### 9. Rule Configuration

Fine-grained control over which rules apply where.

**Config structure:**
```php
'rules' => [
    'contrast' => [
        'severity' => 'warning', // downgrade from error
        'threshold_aa' => 4.5,
        'ignore_urls' => ['admin/*'], // don't check contrast on admin pages
    ],
    'input_purpose' => [
        'enabled' => false, // disabled globally
    ],
],
```

**Why:** One-size-fits-all rules don't work across all projects. Some teams need stricter, some more lenient, some context-dependent.

## Lower Priority

### 10. German Localization

Since BFSG is a German law, many users will be German-speaking. Provide German translations for all violation messages via Laravel's localization system.

### 11. PDF Accessibility Checking

Check uploaded PDFs for accessibility (tagged PDFs, alt text in images, reading order). No PHP package does this currently.

### 12. Documentation Site

GitHub Pages site with proper guides, tutorials, migration guides from other tools (pa11y, axe), troubleshooting, and examples for different frameworks.

### 13. Incremental Analysis

Cache analysis results per URL + content hash. Skip re-analyzing pages that haven't changed since the last check. Significant speedup for large sites.

### 14. Screen Reader Hints

Not actual screen reader testing (impossible without real AT), but deeper semantic analysis that simulates how a screen reader would interpret content.

## Strategic Bundles

These features pair well together:

**The "Enterprise Bundle":** axe-core + SARIF + GitHub Action + Rule Configuration
- Positions laravel-bfsg as the serious enterprise choice
- Competes directly with axe-based tooling while staying Laravel-native

**The "Developer Experience Bundle":** Ignore Mechanism + Diff Reports + Blade Scanner
- Makes the package frictionless to adopt in existing codebases
- Focuses on the day-to-day developer workflow

**The "Ecosystem Bundle":** Livewire + Filament Plugin + German Localization
- Doubles down on the Laravel ecosystem and German-speaking market
- Hard for generic tools to replicate

## Recommended Next Steps

If only one feature gets built next: **Ignore Mechanism** (#2). Without it, the package has a hard ceiling on adoption because false positives in real codebases are inevitable.

If doing a feature trio: **axe-core + Ignore Mechanism + GitHub Action** — this combination transforms the package from "solid niche tool" into "serious contender for accessibility testing in Laravel projects".