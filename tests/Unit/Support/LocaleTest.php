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
            'upper-case language' => ['DE', false],
            'path traversal' => ['../../tmp/zz', false],
            'slash' => ['de/x', false],
            'empty' => ['', false],
            'too long' => ['english', false],
            'two subtags' => ['zh-Hans-CN', false],
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
