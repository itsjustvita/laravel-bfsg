<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Tests\TestCase;

class ConfigKeysTest extends TestCase
{
    /** Settings that src/ reads as one array; their children are data, not keys of their own. */
    private const WHOLE_ARRAYS = ['checks', 'scoring.weights'];

    /** @return list<string> dotted paths of every setting (a value that is not a map with string keys) */
    private function leaves(array $config, string $prefix = ''): array
    {
        $paths = [];

        foreach ($config as $key => $value) {
            $path = $prefix === '' ? $key : $prefix.'.'.$key;

            if (is_array($value) && $value !== [] && ! array_is_list($value) && ! in_array($path, self::WHOLE_ARRAYS, true)) {
                $paths = array_merge($paths, $this->leaves($value, $path));

                continue;
            }

            $paths[] = $path;
        }

        return $paths;
    }

    private function source(): string
    {
        $source = '';

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../../src')) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $source .= file_get_contents($file->getPathname());
            }
        }

        return $source;
    }

    public function test_every_config_setting_is_read_by_its_full_key_somewhere_in_src(): void
    {
        $config = require __DIR__.'/../../config/bfsg.php';
        $source = $this->source();

        foreach ($this->leaves($config) as $path) {
            $this->assertStringContainsString("'bfsg.$path'", $source, "config setting bfsg.$path is never read in src/ (a read of a parent array does not count)");
        }
    }

    public function test_leaves_do_not_climb_to_parents(): void
    {
        $leaves = $this->leaves(['a' => ['b' => 1, 'c' => ['d' => true]], 'list' => ['x', 'y'], 'checks' => ['images' => true]]);

        $this->assertSame(['a.b', 'a.c.d', 'list', 'checks'], $leaves);
    }
}
