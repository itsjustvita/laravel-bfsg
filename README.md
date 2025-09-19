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
- ✅ **Real-time Analysis** - Check your pages for accessibility issues
- ✅ **Multiple Analyzers** - Images, Forms, Headings, ARIA, Links, Keyboard Navigation, and more
- ✅ **Blade Components** - Pre-built accessible components
- ✅ **Artisan Commands** - CLI tools for accessibility testing
- ✅ **Auto-fix Capabilities** - Automatic correction of common issues
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

    // Active checks to perform
    'checks' => [
        'images' => true,      // Alt text validation
        'forms' => true,       // Form label checking
        'headings' => true,    // Heading hierarchy
        'contrast' => true,    // Color contrast ratios
        'keyboard' => true,    // Keyboard navigation
        'aria' => true,        // ARIA attributes
        'links' => true,       // Link accessibility
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

Check a URL for accessibility issues:

```bash
# Check your application's homepage
php artisan bfsg:check

# Check a specific URL
php artisan bfsg:check https://example.com

# Check with detailed output
php artisan bfsg:check https://example.com --detailed

# Check with JSON output
php artisan bfsg:check https://example.com --format=json
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

## 🔍 Available Analyzers

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