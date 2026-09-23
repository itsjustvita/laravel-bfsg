<?php

namespace ItsJustVita\LaravelBfsg\Support;

use InvalidArgumentException;

/**
 * Locales for messages and reports. A locale reaches the translator's file loader as a path segment, so values
 * from outside (CLI options, MCP parameters) go through validate(): well-formed and backed by translations (shipped
 * with the package or published by the application to lang/vendor/bfsg/<locale>). Locales from configuration
 * (bfsg.locale, app.locale) never fail: default() / sanitize() fall back to app.fallback_locale, then en.
 */
final class Locale
{
    /**
     * Language (lower-case, 2–3 letters) with up to two script/region subtags: en, gsw, de_AT, pt-BR, zh-Hans,
     * es_419, sr_Latn_RS. Never contains a path character (/ . \\).
     */
    public const PATTERN = '/^[a-z]{2,3}([_-]([A-Za-z]{2,4}|\d{3})){0,2}$/';

    public const LAST_RESORT = 'en';

    public static function isWellFormed(string $locale): bool
    {
        return preg_match(self::PATTERN, $locale) === 1;
    }

    /** @return list<string> locales the package ships translations for */
    public static function shipped(): array
    {
        $directories = glob(self::packageLangPath().'/*', GLOB_ONLYDIR) ?: [];
        $locales = array_map('basename', $directories);
        sort($locales);

        return $locales;
    }

    /** Well-formed and translated by the package or by the application's lang/vendor/bfsg/<locale>/report.php. */
    public static function isAvailable(string $locale): bool
    {
        if (! self::isWellFormed($locale)) {
            return false;
        }

        return in_array($locale, self::shipped(), true) || is_file(lang_path("vendor/bfsg/{$locale}/report.php"));
    }

    /** The configured locale for messages and reports: bfsg.locale, app.locale, app.fallback_locale, en — the first available. */
    public static function default(): string
    {
        foreach ([config('bfsg.locale'), app()->getLocale(), config('app.fallback_locale')] as $candidate) {
            if (is_string($candidate) && self::isAvailable($candidate)) {
                return $candidate;
            }
        }

        return self::LAST_RESORT;
    }

    /** $locale when it is available, default() otherwise (for locales that did not come from outside). */
    public static function sanitize(?string $locale): string
    {
        return $locale !== null && self::isAvailable($locale) ? $locale : self::default();
    }

    /**
     * For locales from outside (CLI option, MCP parameter).
     *
     * @return string the locale, unchanged
     *
     * @throws InvalidArgumentException when the locale is malformed or has no bfsg translations
     */
    public static function validate(string $locale): string
    {
        if (! self::isWellFormed($locale)) {
            throw new InvalidArgumentException("Invalid locale [{$locale}]. Use a language code such as en, de or de_AT.");
        }

        if (! self::isAvailable($locale)) {
            throw new InvalidArgumentException("Unsupported locale [{$locale}]. Available: ".implode(', ', self::shipped())
                ."; add lang/vendor/bfsg/{$locale}/report.php (php artisan vendor:publish --tag=bfsg-lang) for another locale.");
        }

        return $locale;
    }

    private static function packageLangPath(): string
    {
        return dirname(__DIR__, 2).'/lang';
    }
}
