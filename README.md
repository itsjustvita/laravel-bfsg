# Laravel BFSG - Accessibility Compliance Package

[![Latest Version on Packagist](https://img.shields.io/packagist/v/itsjustvita/laravel-bfsg.svg?style=flat-square)](https://packagist.org/packages/itsjustvita/laravel-bfsg)
[![Total Downloads](https://img.shields.io/packagist/dt/itsjustvita/laravel-bfsg.svg?style=flat-square)](https://packagist.org/packages/itsjustvita/laravel-bfsg)
[![License](https://img.shields.io/packagist/l/itsjustvita/laravel-bfsg.svg?style=flat-square)](https://packagist.org/packages/itsjustvita/laravel-bfsg)
[![PHP Version](https://img.shields.io/packagist/php-v/itsjustvita/laravel-bfsg.svg?style=flat-square)](https://packagist.org/packages/itsjustvita/laravel-bfsg)
[![Laravel Version](https://img.shields.io/badge/Laravel-12.x%20|%2013.x-FF2D20?style=flat-square&logo=laravel)](https://laravel.com)

A comprehensive Laravel package for BFSG (Barrierefreiheitsstärkungsgesetz) and WCAG compliance, helping developers create accessible web applications that comply with German and international accessibility standards.

## 🎯 Features

- ✅ **WCAG 2.1 Level AA/AAA Compliance Checking**
- ✅ **BFSG 2025 Ready** - Full compliance with German accessibility law
- ✅ **16 Specialized Analyzers** - Images, Forms, Headings, ARIA, Links, Keyboard, Language, Tables, Media, Semantic HTML, Contrast, Page Title, Input Purpose, Focus, Error Handling, Status Messages
- ✅ **CSS-Based Contrast Analysis** - Parses `<style>` blocks with cascade/specificity/inheritance resolution
- ✅ **SPA Support** - Test React, Vue, Inertia apps with Playwright browser engine
- ✅ **Beautiful HTML & PDF Reports** - Professional reports with compliance scores and grades
- ✅ **Multiple Report Formats** - HTML, PDF, JSON, Markdown with statistics
- ✅ **Database Persistence** - Track violations over time with Eloquent models and publishable migrations
- ✅ **Blade Components** - Pre-built accessible components
- ✅ **Artisan Commands** - CLI tools for accessibility testing
- ✅ **Detailed Reporting** - Comprehensive violation reports with suggestions
- ✅ **Laravel 12 + 13 Support** - Built for the latest Laravel versions

## 📋 Requirements

- PHP 8.2 or higher
- Laravel 12.0 or 13.0 or higher

## 📦 Installation

You can install the package via composer:

```bash
composer require itsjustvita/laravel-bfsg
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=bfsg-config
```

Optionally publish the views:

```bash
php artisan vendor:publish --tag=bfsg-views
```

Publish the database migrations (required for `--save` and `bfsg:history`):

```bash
php artisan vendor:publish --tag=bfsg-migrations
php artisan migrate
```

## ⚙️ Configuration

The configuration file `config/bfsg.php` allows you to customize:

```php
return [
    // WCAG compliance level: 'A', 'AA', or 'AAA'
    'compliance_level' => env('BFSG_LEVEL', 'AA'),

    // Enable automatic fixes for simple issues
    'auto_fix' => env('BFSG_AUTO_FIX', false),

    // Active checks to perform (all 16 analyzers)
    'checks' => [
        'images' => true,         // Alt text validation
        'forms' => true,          // Form label checking
        'headings' => true,       // Heading hierarchy
        'contrast' => true,       // Color contrast ratios (incl. CSS)
        'keyboard' => true,       // Keyboard navigation
        'aria' => true,           // ARIA attributes
        'links' => true,          // Link accessibility
        'language' => true,       // Language attributes
        'tables' => true,         // Table accessibility
        'media' => true,          // Video/audio captions
        'semantic' => true,       // Semantic HTML structure
        'page_title' => true,     // Page title (WCAG 2.4.2) (NEW in 2.0)
        'input_purpose' => true,  // Input purpose (WCAG 1.3.5) (NEW in 2.0)
        'focus' => true,          // Focus visible (WCAG 2.4.7) (NEW in 2.0)
        'error_handling' => true, // Error handling (WCAG 3.3.1/3.3.3) (NEW in 2.0)
        'status_messages' => true, // Status messages (WCAG 4.1.3) (NEW in 2.0)
    ],

    // Reporting configuration
    'reporting' => [
        'enabled' => env('BFSG_REPORTING', true),
        'email' => env('BFSG_REPORT_EMAIL', null),
        'save_to_database' => false,
    ],
];
```

## 🚀 Usage

### Command Line

The package provides two commands:

#### `bfsg:check` - Full-Featured Checker
For production use with authentication, reports, and database storage:

```bash
# Check your application's homepage
php artisan bfsg:check

# Check a specific URL
php artisan bfsg:check https://example.com

# Check with detailed output
php artisan bfsg:check https://example.com --detailed

# Generate HTML report with compliance score
php artisan bfsg:check https://example.com --format=html

# Generate JSON report
php artisan bfsg:check https://example.com --format=json

# Generate PDF report (requires barryvdh/laravel-dompdf)
php artisan bfsg:check https://example.com --format=pdf

# Save results to database for historical tracking
php artisan bfsg:check https://example.com --save
```

#### `bfsg:analyze` - Quick Analysis + SPA Support
For quick checks and Single Page Applications (React, Vue, Inertia):

```bash
# Quick server-side analysis
php artisan bfsg:analyze https://example.com

# Analyze SPAs with real browser rendering (Playwright)
php artisan bfsg:analyze https://example.com --browser

# Browser with visible window (debugging)
php artisan bfsg:analyze https://example.com --browser --headless=false

# Adjust timeout for slow-loading SPAs
php artisan bfsg:analyze https://example.com --browser --timeout=60000
```

#### `bfsg:history` - Report History
View stored reports, track score trends, and manage historical data:

```bash
# View all stored reports
php artisan bfsg:history

# Filter by URL
php artisan bfsg:history --url=https://example.com

# Show score trends over time
php artisan bfsg:history --trends

# Cleanup reports older than 90 days
php artisan bfsg:history --cleanup --days=90
```

#### Authentication Support

Check protected pages that require authentication:

```bash
# With email/password authentication
php artisan bfsg:check https://example.com/dashboard --auth --email=user@example.com --password=secret

# Interactive authentication (prompts for credentials)
php artisan bfsg:check https://example.com/dashboard --auth

# With custom login URL
php artisan bfsg:check https://example.com/dashboard --auth --login-url=/admin/login

# With bearer token (for API authentication)
php artisan bfsg:check https://api.example.com/protected --bearer="your-api-token"

# With existing session cookie
php artisan bfsg:check https://example.com/dashboard --session="laravel_session=abc123..."

# With Laravel Sanctum
php artisan bfsg:check https://example.com/dashboard --auth --sanctum --email=user@example.com
```

## MCP Server

Laravel BFSG includes a built-in MCP (Model Context Protocol) server that allows AI assistants like Claude to directly run accessibility checks.

### Setup

Start the MCP server:

```bash
php artisan bfsg:mcp-server
```

Add to your Claude Code MCP configuration (`.claude/settings.json` or project settings):

```json
{
  "mcpServers": {
    "bfsg": {
      "command": "php",
      "args": ["artisan", "bfsg:mcp-server"],
      "cwd": "/path/to/your/laravel-project"
    }
  }
}
```

### Available Tools

| Tool | Description |
|------|-------------|
| `analyze_html` | Analyze raw HTML for accessibility violations |
| `analyze_url` | Fetch and analyze a URL |
| `check_contrast` | Check contrast ratio between two colors |
| `list_analyzers` | List all 16 analyzers with enabled status |
| `get_history` | Retrieve stored accessibility reports |
| `get_report` | Get a single report with all violations |
| `generate_report` | Analyze URL and generate formatted report (JSON/HTML/Markdown/PDF) |

### Programmatic Usage

```php
use ItsJustVita\LaravelBfsg\Facades\Bfsg;

// Analyze HTML content
$html = '<img src="photo.jpg"><form><input type="text"></form>';
$violations = Bfsg::analyze($html);

// Check if content is accessible
if (!Bfsg::isAccessible($html)) {
    $violations = Bfsg::getViolations();
    // Handle violations
}
```

### Blade Components

Use pre-built accessible components:

```blade
{{-- Accessible Image Component --}}
<x-bfsg::accessible-image
    src="/path/to/image.jpg"
    alt="Description of image"
    decorative="false"
/>

{{-- More components coming soon --}}
```

### Middleware (Optional)

Add accessibility checking middleware to your routes:

```php
// In app/Http/Kernel.php or bootstrap/app.php
protected $routeMiddleware = [
    'accessible' => \ItsJustVita\LaravelBfsg\Middleware\CheckAccessibility::class,
];

// In routes/web.php
Route::middleware(['accessible'])->group(function () {
    // Your routes
});
```

## 🔍 Available Analyzers (16 Total)

### ImageAnalyzer
- Checks for missing alt attributes
- Validates decorative image markup
- Suggests appropriate alt text

### FormAnalyzer
- Validates form labels
- Checks required field indicators
- Ensures proper ARIA labels

### HeadingAnalyzer
- Validates heading hierarchy (h1-h6)
- Checks for missing h1
- Ensures logical heading structure

### ContrastAnalyzer (enhanced in 2.0)
- Calculates color contrast ratios
- Validates against WCAG AA/AAA standards
- Checks text and background combinations
- **NEW**: Parses `<style>` blocks via CssParser for CSS-based contrast checking
- **NEW**: Resolves cascade, specificity, and inheritance for accurate color detection

### AriaAnalyzer
- Validates ARIA roles
- Checks ARIA properties
- Ensures proper ARIA relationships

### LinkAnalyzer
- Checks for descriptive link text
- Validates link context
- Identifies "click here" anti-patterns

### KeyboardNavigationAnalyzer
- Detects missing skip links
- Validates tab order and tabindex usage
- Checks for keyboard traps in modals
- Ensures click handlers are keyboard accessible
- Validates focus management
- Detects mouse-only event handlers

### LanguageAnalyzer
- Validates `lang` attribute on `<html>` element
- Checks for valid ISO 639-1 language codes
- Detects language changes in content
- Validates `xml:lang` attributes (BFSG §3 requirement)

### TableAnalyzer
- Checks for `<caption>` elements
- Validates `<th>` with proper `scope` attributes
- Detects tables without header cells
- Identifies layout tables vs data tables
- Validates complex table relationships

### MediaAnalyzer
- Checks videos for captions/subtitles (`<track kind="captions">`)
- Validates audio transcript references
- Detects autoplay issues
- Ensures controls are present
- Validates YouTube iframe caption parameters

### SemanticHTMLAnalyzer
- Validates landmark elements (`<main>`, `<nav>`, `<header>`, `<footer>`)
- Detects "div-itis" (excessive div usage)
- Checks button vs link usage
- Validates section headings
- Ensures proper list structures

### PageTitleAnalyzer ⭐ NEW in 2.0
- Validates page `<title>` element exists (WCAG 2.4.2)
- Checks for descriptive, non-generic titles
- Detects duplicate or missing titles

### InputPurposeAnalyzer ⭐ NEW in 2.0
- Validates `autocomplete` attributes on input fields (WCAG 1.3.5)
- Checks for appropriate input purpose identification
- Ensures user data fields support autofill

### FocusAnalyzer ⭐ NEW in 2.0
- Validates visible focus indicators (WCAG 2.4.7)
- Detects `outline: none` / `outline: 0` without replacement styles
- Checks for custom focus indicator implementations

### ErrorHandlingAnalyzer ⭐ NEW in 2.0
- Validates form error identification (WCAG 3.3.1)
- Checks for error suggestions (WCAG 3.3.3)
- Ensures error messages are associated with form fields

### StatusMessageAnalyzer ⭐ NEW in 2.0
- Validates status messages use ARIA live regions (WCAG 4.1.3)
- Checks for `role="status"`, `role="alert"`, `aria-live` attributes
- Ensures dynamic content updates are announced to assistive technologies

## 📊 Report Generation

Generate professional accessibility reports in multiple formats:

### HTML Reports
Beautiful, printable reports with compliance scores and grades:

```bash
php artisan bfsg:check https://example.com --format=html
```

Reports include:
- **Compliance Score** (0-100%) with grade (A+ to F)
- **Detailed Statistics** (critical, errors, warnings, notices)
- **Issue Breakdown** by analyzer with severity badges
- **WCAG Rule References** for each violation
- **Suggestions** for fixing each issue

Reports are saved to `storage/app/bfsg-reports/`.

### PDF Reports
Professional PDF reports for stakeholders and compliance documentation (requires `barryvdh/laravel-dompdf`):

```bash
php artisan bfsg:check https://example.com --format=pdf
```

### JSON Reports
Machine-readable format for CI/CD integration:

```bash
php artisan bfsg:check https://example.com --format=json
```

### Programmatic Report Generation

```php
use ItsJustVita\LaravelBfsg\Reports\ReportGenerator;

$violations = Bfsg::analyze($html);
$report = new ReportGenerator($url, $violations);

// Generate HTML report
$htmlReport = $report->setFormat('html')->generate();

// Or save to file
$filename = $report->setFormat('html')->saveToFile();

// Get statistics
$stats = $report->getStats();
// ['compliance_score' => 85, 'grade' => 'B+', 'total_issues' => 12, ...]
```

## 🗄️ Database Persistence

Track accessibility violations over time by saving reports to your database.

### Setup

Publish and run the migrations:

```bash
php artisan vendor:publish --tag=bfsg-migrations
php artisan migrate
```

This creates `bfsg_reports` and `bfsg_violations` tables with corresponding `BfsgReport` and `BfsgViolation` Eloquent models.

### Saving Reports

```bash
# Save check results to database
php artisan bfsg:check https://example.com --save
```

### Viewing History

```bash
# View all stored reports
php artisan bfsg:history

# Filter by URL
php artisan bfsg:history --url=https://example.com

# Show score trends
php artisan bfsg:history --trends

# Cleanup old reports
php artisan bfsg:history --cleanup --days=90
```

## 📱 Testing Single Page Applications (SPAs)

For React, Vue, Inertia.js, and other SPAs, use browser rendering:

```bash
# Analyze with Playwright browser engine
php artisan bfsg:analyze https://spa-app.com --browser

# See full documentation
```

See [SPA-TESTING.md](SPA-TESTING.md) for complete guide including:
- Playwright setup and installation
- Browser configuration (Chromium, Firefox, WebKit)
- Timeout and wait selectors
- CI/CD integration
- Debugging with visible browser

## 📊 Understanding Violations

Each violation includes:

```php
[
    'type' => 'error',           // error, warning, or notice
    'rule' => 'WCAG 1.1.1',      // WCAG rule reference
    'element' => 'img',          // HTML element type
    'message' => 'Image without alt text found',
    'suggestion' => 'Add an alt attribute to describe the image',
    'auto_fixable' => true,      // Can be automatically fixed
]
```

## 🧪 Testing

Run the test suite (215 tests, 424 assertions):

```bash
composer test
```

Run specific tests:

```bash
php artisan test --filter=ImageAnalyzerTest
```

## 🤝 BFSG Compliance

This package helps you comply with the German Barrierefreiheitsstärkungsgesetz (BFSG), which requires:

- **Level AA WCAG 2.1 Compliance** (minimum)
- **Perceivable** content (text alternatives, captions)
- **Operable** interfaces (keyboard accessible)
- **Understandable** information and UI
- **Robust** content for assistive technologies

### Key Dates
- **June 28, 2025**: BFSG comes into full effect
- Applies to all digital products and services in Germany

## 📚 Resources

- [WCAG 2.1 Guidelines](https://www.w3.org/WAI/WCAG21/quickref/)
- [BFSG Information (German)](https://www.bmas.de/DE/Service/Gesetze-und-Gesetzesvorhaben/barrierefreiheitsstaerkungsgesetz.html)
- [Laravel Documentation](https://laravel.com/docs)

## 🤝 Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## 🐛 Issues

If you discover any security-related issues, please email hello@itsjustvita.com instead of using the issue tracker.

## 📝 Changelog

Please see [CHANGELOG.md](CHANGELOG.md) for more information on what has changed recently.

## 👤 Author

**Vitalis Feist-Wurm**
- Email: hello@itsjustvita.com
- GitHub: [@itsjustvita](https://github.com/itsjustvita)

## 📄 License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## 🙏 Acknowledgments

- Thanks to the Laravel community
- Inspired by various accessibility tools and standards
- Built with ❤️ for a more accessible web

---

**Made with ❤️ for accessibility compliance**

*This package is actively maintained and regularly updated to comply with the latest WCAG guidelines and BFSG requirements.*