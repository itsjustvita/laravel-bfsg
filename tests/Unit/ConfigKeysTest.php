<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Tests\TestCase;

class ConfigKeysTest extends TestCase
{
    /** @return list<string> dotted paths of every leaf and every branch in config/bfsg.php */
    private function paths(array $config, string $prefix = ''): array
    {
        $paths = [];

        foreach ($config as $key => $value) {
            if (is_int($key)) {
                continue; // list items (ignored_selectors, ignored_paths)
            }

            $path = $prefix === '' ? $key : $prefix.'.'.$key;
            $paths[] = $path;

            if (is_array($value)) {
                $paths = array_merge($paths, $this->paths($value, $path));
            }
        }

        return $paths;
    }

    public function test_every_config_key_is_read_somewhere_in_src(): void
    {
        $config = require __DIR__.'/../../config/bfsg.php';
        $source = '';

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../../src')) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $source .= file_get_contents($file->getPathname());
            }
        }

        foreach ($this->paths($config) as $path) {
            if (str_starts_with($path, 'checks.') || str_starts_with($path, 'scoring.weights.')) {
                continue; // read as whole arrays
            }

            // Read directly, read as part of a longer key, or read through a parent array.
            $found = str_contains($source, "'bfsg.$path'") || str_contains($source, "'bfsg.$path.");
            $segments = explode('.', $path);

            while (! $found && count($segments) > 1) {
                array_pop($segments);
                $found = str_contains($source, "'bfsg.".implode('.', $segments)."'");
            }

            $this->assertTrue($found, "config key bfsg.$path is never read in src/");
        }
    }
}
