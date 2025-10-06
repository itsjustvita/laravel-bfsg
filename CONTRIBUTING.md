# Contributing to Laravel BFSG

Thank you for considering contributing to Laravel BFSG! This document outlines the guidelines for contributing to this package.

## Code of Conduct

Please be respectful and constructive in all interactions. We aim to foster an inclusive and welcoming community.

## How to Contribute

### Reporting Bugs

If you discover a bug, please create an issue on GitHub with:
- A clear, descriptive title
- Steps to reproduce the issue
- Expected vs. actual behavior
- Your environment (PHP version, Laravel version, etc.)
- Any relevant code samples or error messages

### Suggesting Features

We welcome feature suggestions! Please create an issue with:
- A clear description of the feature
- Use cases and benefits
- Any relevant examples or mockups
- Consideration of WCAG/BFSG compliance impact

### Pull Requests

1. **Fork the repository** and create a new branch from `main`
2. **Write tests** for any new functionality
3. **Update documentation** if needed (README.md, code comments, etc.)
4. **Follow the coding standards** (see below)
5. **Ensure all tests pass** before submitting
6. **Create a pull request** with a clear description of the changes

#### Pull Request Process

- Ensure your code follows PSR-12 coding standards
- Run the test suite: `vendor/bin/phpunit`
- Run code style fixes: `vendor/bin/pint`
- Update CHANGELOG.md with your changes
- Reference any related issues in your PR description

## Development Setup

```bash
# Clone the repository
git clone https://github.com/itsjustvita/laravel-bfsg.git
cd laravel-bfsg

# Install dependencies
composer install

# Run tests
vendor/bin/phpunit

# Run code style fixer
vendor/bin/pint
```

## Coding Standards

- Follow **PSR-12** coding standards
- Use **type hints** for all parameters and return types
- Write **descriptive variable and method names**
- Add **PHPDoc blocks** for all classes and methods
- Keep methods focused and concise
- **Write tests** for new features and bug fixes

### Example

```php
<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

class ExampleAnalyzer
{
    /**
     * Analyze HTML for specific accessibility issues
     */
    public function analyze(\DOMDocument $dom): array
    {
        $issues = [];

        // Analysis logic here

        return [
            'issues' => $issues,
            'stats' => [
                'total_issues' => count($issues),
            ],
        ];
    }
}
```

## Testing

All contributions must include tests. We use PHPUnit for testing.

### Writing Tests

- Place unit tests in `tests/Unit/`
- Place feature tests in `tests/Feature/`
- Follow existing test patterns
- Test both success and failure cases
- Include edge cases

### Running Tests

```bash
# Run all tests
vendor/bin/phpunit

# Run specific test file
vendor/bin/phpunit tests/Unit/ImageAnalyzerTest.php

# Run with coverage (requires Xdebug)
vendor/bin/phpunit --coverage-html coverage
```

## Accessibility Standards

This package focuses on WCAG 2.1 and BFSG compliance. When contributing:

- Familiarize yourself with [WCAG 2.1 guidelines](https://www.w3.org/WAI/WCAG21/quickref/)
- Reference specific WCAG criteria in your code and tests
- Ensure analyzers check for real accessibility issues
- Provide helpful, actionable suggestions in violation messages

## Documentation

Good documentation is crucial:

- Update README.md for new features
- Add code comments for complex logic
- Include usage examples
- Document configuration options
- Keep CHANGELOG.md updated

## Questions?

If you have questions about contributing, feel free to:
- Open an issue for discussion
- Email: hello@itsjustvita.com

## License

By contributing to Laravel BFSG, you agree that your contributions will be licensed under the MIT License.

---

**Thank you for helping make the web more accessible!**