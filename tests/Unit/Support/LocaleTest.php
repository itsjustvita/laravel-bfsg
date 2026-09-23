<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit\Support;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use ItsJustVita\LaravelBfsg\Support\Locale;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class LocaleTest extends TestCase
{
    /** @return array<string, array{0: string, 1: bool}> */
    public static function shapes(): array
    {
        return [
            'en' => ['en', true],
            'de' => ['de', true],
            'three letters' => ['gsw', true],
            'region with underscore' => ['de_AT', true],
            'region with dash' => ['pt-BR', true],
            'script' => ['zh-Hans', true],
            'numeric region' => ['es_419', true],
            'script and region' => ['sr_Latn_RS', true],
            'script and region with dashes' => ['zh-Hans-CN', true],
            'upper-case language' => ['DE', false],
            'three subtags' => ['sr_Latn_RS_x', false],
            'encoding suffix' => ['en_US.UTF-8', false],
            'backslash' => ['de\\x', false],
            'semicolon' => ['de;x', false],
            'path traversal' => ['../../tmp/zz', false],
            'slash' => ['de/x', false],
            'empty' => ['', false],
            'too long' => ['english', false],
        ];
    }

    #[DataProvider('shapes')]
    public function test_well_formed(string $locale, bool $expected): void
    {
        $this->assertSame($expected, Locale::isWellFormed($locale));
    }

    public function test_shipped_locales_are_available(): void
    {
        $this->assertSame(['de', 'en'], Locale::shipped());
        $this->assertTrue(Locale::isAvailable('en'));
        $this->assertTrue(Locale::isAvailable('de'));
        $this->assertFalse(Locale::isAvailable('xx'));
        $this->assertFalse(Locale::isAvailable('../en'), 'never a path');
    }

    public function test_a_published_vendor_override_makes_a_locale_available(): void
    {
        $directory = lang_path('vendor/bfsg/fr');
        File::ensureDirectoryExists($directory);
        File::put($directory.'/report.php', '<?php return [];');

        try {
            $this->assertTrue(Locale::isAvailable('fr'));
            $this->assertSame('fr', Locale::validate('fr'));
        } finally {
            File::deleteDirectory($directory);
        }

        $this->assertFalse(Locale::isAvailable('fr'));
    }

    /** @return array<string, array{0: ?string, 1: string, 2: string, 3: string}> bfsg.locale, app.locale, app.fallback_locale, expected */
    public static function defaults(): array
    {
        return [
            'bfsg.locale wins' => ['de', 'en', 'en', 'de'],
            'then app.locale' => [null, 'de', 'en', 'de'],
            'malformed app.locale falls back' => [null, 'en_US.UTF-8', 'de', 'de'],
            'app.locale without translations falls back' => [null, 'zh_Hans_CN', 'de', 'de'],
            'malformed bfsg.locale falls back to app.locale' => ['../x', 'de', 'en', 'de'],
            'then en' => ['xx', 'zh_Hans_CN', 'fr', 'en'],
        ];
    }

    #[DataProvider('defaults')]
    public function test_the_default_locale_comes_from_config_and_never_throws(?string $bfsg, string $app, string $fallback, string $expected): void
    {
        config()->set('bfsg.locale', $bfsg);
        config()->set('app.fallback_locale', $fallback);
        $this->app->setLocale($app);

        $this->assertSame($expected, Locale::default());
    }

    public function test_sanitize_keeps_available_locales_and_replaces_the_rest_with_the_default(): void
    {
        $this->assertSame('de', Locale::sanitize('de'));
        $this->assertSame('en', Locale::sanitize('zh_Hans_CN'));
        $this->assertSame('en', Locale::sanitize('../../tmp/zz'));
        $this->assertSame('en', Locale::sanitize(null));
    }

    public function test_validate_names_the_problem(): void
    {
        $this->assertSame('de', Locale::validate('de'));

        foreach (['../../tmp/zz' => 'Invalid locale [../../tmp/zz]', 'xx' => 'Unsupported locale [xx]'] as $locale => $message) {
            try {
                Locale::validate($locale);
                $this->fail("{$locale} was accepted");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }
}
