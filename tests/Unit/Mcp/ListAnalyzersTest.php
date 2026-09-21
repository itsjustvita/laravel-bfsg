<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit\Mcp;

use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Mcp\Tools\ListAnalyzers;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use Laravel\Mcp\Request;

class ListAnalyzersTest extends TestCase
{
    public function test_lists_every_registered_analyzer_from_the_registry(): void
    {
        config()->set('bfsg.checks.contrast', false);

        $response = (new ListAnalyzers)->handle(new Request([]));
        $payload = json_decode((string) $response->content(), true);

        $this->assertSame(array_keys(Bfsg::ANALYZERS), array_column($payload, 'name'));
        $byName = array_column($payload, null, 'name');
        $this->assertFalse($byName['contrast']['enabled']);
        $this->assertTrue($byName['images']['enabled']);
        $this->assertSame(['1.1.1'], $byName['images']['rules']);
        $this->assertNotSame('', $byName['images']['description']);
    }
}
