# Laravel BFSG - Accessibility Compliance Package

[![Latest Version on Packagist](https://img.shields.io/packagist/v/itsjustvita/laravel-bfsg.svg?style=flat-square)](https://packagist.org/packages/itsjustvita/laravel-bfsg)
[![Total Downloads](https://img.shields.io/packagist/dt/itsjustvita/laravel-bfsg.svg?style=flat-square)](https://packagist.org/packages/itsjustvita/laravel-bfsg)
[![License](https://img.shields.io/packagist/l/itsjustvita/laravel-bfsg.svg?style=flat-square)](https://packagist.org/packages/itsjustvita/laravel-bfsg)
[![PHP Version](https://img.shields.io/packagist/php-v/itsjustvita/laravel-bfsg.svg?style=flat-square)](https://packagist.org/packages/itsjustvita/laravel-bfsg)
[![Laravel Version](https://img.shields.io/badge/Laravel-12.x-FF2D20?style=flat-square&logo=laravel)](https://laravel.com)

A comprehensive Laravel package for BFSG (Barrierefreiheitsstärkungsgesetz) and WCAG compliance, helping developers create accessible web applications that comply with German and international accessibility standards.

## 🎯 Features

- ✅ **WCAG 2.1 Level AA/AAA Compliance Checking**
- ✅ **BFSG 2025 Ready** - Full compliance with German accessibility law
- ✅ **11 Specialized Analyzers** - Images, Forms, Headings, ARIA, Links, Keyboard, Language, Tables, Media, Semantic HTML
- ✅ **SPA Support** - Test React, Vue, Inertia apps with Playwright browser engine
- ✅ **Beautiful HTML Reports** - Professional reports with compliance scores and grades
- ✅ **Multiple Report Formats** - HTML, JSON, Markdown with statistics
- ✅ **Blade Components** - Pre-built accessible components
- ✅ **Artisan Commands** - CLI tools for accessibility testing
- ✅ **Detailed Reporting** - Comprehensive violation reports with suggestions
- ✅ **Laravel 12 Support** - Built for the latest Laravel version

## 📋 Requirements

- PHP 8.2 or higher
- Laravel 12.0 or higher

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

## ⚙️ Configuration

The configuration file `config/bfsg.php` allows you to customize:

```php
return [
    // WCAG compliance level: 'A', 'AA', or 'AAA'
    'compliance_level' => env('BFSG_LEVEL', 'AA'),

    // Enable automatic fixes for simple issues
    'auto_fix' => env('BFSG_AUTO_FIX', false),

    // Active checks to perform (all 11 analyzers)
    'checks' => [
        'images' => true,      // Alt text validation
        'forms' => true,       // Form label checking
        'headings' => true,    // Heading hierarchy
        'contrast' => true,    // Color contrast ratios
        'keyboard' => true,    // Keyboard navigation
        'aria' => true,        // ARIA attributes
        'links' => true,       // Link accessibility
        'language' => true,    // Language attributes (NEW)
        'tables' => true,      // Table accessibility (NEW)
        'media' => true,       // Video/audio captions (NEW)
        'semantic' => true,    // Semantic HTML structure (NEW)
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

## 🔍 Available Analyzers (11 Total)

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

### ContrastAnalyzer
- Calculates color contrast ratios
- Validates against WCAG AA/AAA standards
- Checks text and background combinations

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

### LanguageAnalyzer ⭐ NEW
- Validates `lang` attribute on `<html>` element
- Checks for valid ISO 639-1 language codes
- Detects language changes in content
- Validates `xml:lang` attributes (BFSG §3 requirement)

### TableAnalyzer ⭐ NEW
- Checks for `<caption>` elements
- Validates `<th>` with proper `scope` attributes
- Detects tables without header cells
- Identifies layout tables vs data tables
- Validates complex table relationships

### MediaAnalyzer ⭐ NEW
- Checks videos for captions/subtitles (`<track kind="captions">`)
- Validates audio transcript references
- Detects autoplay issues
- Ensures controls are present
- Validates YouTube iframe caption parameters

### SemanticHTMLAnalyzer ⭐ NEW
- Validates landmark elements (`<main>`, `<nav>`, `<header>`, `<footer>`)
- Detects "div-itis" (excessive div usage)
- Checks button vs link usage
- Validates section headings
- Ensures proper list structures

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

Run the test suite:

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