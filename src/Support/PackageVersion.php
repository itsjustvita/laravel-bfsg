<?php

namespace ItsJustVita\LaravelBfsg\Support;

use Composer\InstalledVersions;
use OutOfBoundsException;

/** The installed version of this package (report footers, JSON `package_version`, MCP server info). */
final class PackageVersion
{
    public const PACKAGE = 'itsjustvita/laravel-bfsg';

    public static function get(): string
    {
        try {
            return self::normalize(InstalledVersions::getPrettyVersion(self::PACKAGE) ?? 'unknown');
        } catch (OutOfBoundsException) {
            return 'unknown';
        }
    }

    /** Tags carry a leading "v" (v3.0.0); reports show the bare version (3.0.0, spec §12). Branch names stay. */
    public static function normalize(string $version): string
    {
        return (string) preg_replace('/^v(?=\d)/', '', $version);
    }
}
