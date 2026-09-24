# Contributing to Laravel BFSG

Thank you for helping make the web more accessible. This document describes how to report problems and how to change the package.

## Reporting bugs and false positives

Open an issue on GitHub with:

- the PHP, Laravel and package versions (`composer show itsjustvita/laravel-bfsg`),
- the smallest HTML that shows the problem, and the command or code you ran,
- the finding's `key` (for example `links.non_descriptive`) and what you expected instead,
- for a false positive: why the markup meets the WCAG success criterion (a link to the W3C understanding document or technique helps).

Please report security issues to hello@itsjustvita.com instead of the issue tracker.

## Suggesting features

Describe the use case, the WCAG success criterion or BFSG requirement behind it, and an example. Check the roadmap in the README first.

## Development setup

```bash
git clone https://github.com/itsjustvita/laravel-bfsg.git
cd laravel-bfsg
composer install

composer test    # PHPUnit (the suite never touches the network or spawns node)
composer lint    # Pint, check only
composer fix     # Pint
```

The live smoke test installs your checkout into a fresh Laravel application and runs the commands, the middleware, the MCP server and (with Playwright installed in that application) the browser mode against it. It needs network access for Composer:

```bash
composer create-project laravel/laravel /tmp/bfsg-live-app
tests/Live/setup.sh "$PWD" /tmp/bfsg-live-app
tests/Live/smoke.sh /tmp/bfsg-live-app
```

CI runs PHPUnit for PHP 8.2 to 8.4 with Laravel 12 and 13, Pint, and the live smoke test on every push and pull request.

## Pull requests

1. Branch from `main`.
2. Write the test first; every bug fix comes with a test that fails without it.
3. Keep the documentation in step: `tests/Feature/DocumentationTest.php` checks that the code samples in `README.md`, `UPGRADE.md` and `SPA-TESTING.md` still lint and run, and that every command option, config key, analyzer and MCP tool is documented.
4. Add a line to the `Unreleased` section of `CHANGELOG.md`.
5. Run `composer test` and `composer lint`.
6. Use a conventional commit message (`fix(links): …`, `feat(commands): …`, `docs: …`).

## Writing an analyzer check

Analyzers extend `ItsJustVita\LaravelBfsg\Analyzers\BaseAnalyzer` and report findings with a translation key:

```php
namespace ItsJustVita\LaravelBfsg\Analyzers;

use ItsJustVita\LaravelBfsg\Severity;

class ExampleAnalyzer extends BaseAnalyzer
{
    protected string $key = 'example';

    protected string $description = 'Example checks';

    protected array $rules = ['1.3.1'];

    protected function inspect(): void
    {
        foreach ($this->queryVisible('//table[not(.//th)]') as $table) {
            $this->report('table_without_headers', Severity::Warning, '1.3.1', $table);
        }
    }
}
```

Rules the analyzers follow:

- One finding per element; no counts or aggregates.
- Exactly one primary WCAG 2.1 success criterion per finding (`related` for more); severity `error` only for definite AA failures detectable in the markup, `warning` for likely failures that need a manual check, `notice` for best practices, AAA and what cannot be verified statically.
- Skip hidden elements (`queryVisible()`), use the accessible name (`name()`) wherever a name matters, and compare enumerated attributes case-insensitively.
- Keep the first argument of `report()` a string literal: `TranslationCompletenessTest` requires a message and a suggestion for every key in `lang/en/violations.php` and `lang/de/violations.php`, with the same placeholders. German texts use the neutral infinitive form ("Alt-Attribut ergänzen").
- Tests assert on keys, never on message text (`AnalyzerTestCase::assertHasViolation('example.table_without_headers')`).

## License

By contributing, you agree that your contributions are licensed under the MIT License.
