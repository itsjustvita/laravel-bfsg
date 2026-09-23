<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit\Support;

use ItsJustVita\LaravelBfsg\Support\PackageVersion;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class PackageVersionTest extends TestCase
{
    public function test_a_leading_v_of_a_tag_is_stripped(): void
    {
        $this->assertSame('3.0.0', PackageVersion::normalize('v3.0.0'));
        $this->assertSame('3.0.0', PackageVersion::normalize('3.0.0'));
        $this->assertSame('3.x-dev', PackageVersion::normalize('v3.x-dev'));
        $this->assertSame('dev-v3', PackageVersion::normalize('dev-v3'), 'branch names stay as they are');
        $this->assertStringStartsNotWith('v', PackageVersion::get());
    }
}
