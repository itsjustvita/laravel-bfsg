<?php

namespace ItsJustVita\LaravelBfsg\Tests\Feature;

use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class BfsgServiceProviderTest extends TestCase
{
    public function test_bfsg_is_registered_as_singleton(): void
    {
        $instance1 = app('bfsg');
        $instance2 = app('bfsg');

        $this->assertInstanceOf(Bfsg::class, $instance1);
        $this->assertSame($instance1, $instance2);
    }

    public function test_config_is_merged(): void
    {
        $this->assertNotNull(config('bfsg'));
        $this->assertEquals('AA', config('bfsg.compliance_level'));
    }

    public function test_checks_config_has_all_analyzers(): void
    {
        $checks = config('bfsg.checks');

        $expectedKeys = [
            'images',
            'forms',
            'headings',
            'contrast',
            'aria',
            'links',
            'keyboard',
            'language',
            'tables',
            'media',
            'semantic',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $checks, "Missing check key: {$key}");
        }
    }

    public function test_commands_are_registered(): void
    {
        $registeredCommands = array_keys(\Artisan::all());

        $this->assertContains('bfsg:check', $registeredCommands);
        $this->assertContains('bfsg:analyze', $registeredCommands);
    }

    public function test_facade_resolves(): void
    {
        $html = '<html lang="en"><body><h1>Test</h1></body></html>';

        $result = \ItsJustVita\LaravelBfsg\Facades\Bfsg::analyze($html);

        $this->assertIsArray($result);
    }
}
