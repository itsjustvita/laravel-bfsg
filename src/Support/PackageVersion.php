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
            return InstalledVersions::getPrettyVersion(self::PACKAGE) ?? 'unknown';
        } catch (OutOfBoundsException) {
            return 'unknown';
        }
    }
}
