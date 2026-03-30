<?php

namespace ItsJustVita\LaravelBfsg\Tests;

use Barryvdh\DomPDF\ServiceProvider;
use ItsJustVita\LaravelBfsg\BfsgServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app)
    {
        return [
            BfsgServiceProvider::class,
            ServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing.foreign_key_constraints', true);
    }
}
