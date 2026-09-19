<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class SeverityTest extends TestCase
{
    public function test_maps_legacy_values(): void
    {
        $this->assertSame(Severity::Error, Severity::fromLegacy('error'));
        $this->assertSame(Severity::Error, Severity::fromLegacy('critical'));
        $this->assertSame(Severity::Warning, Severity::fromLegacy('WARNING'));
        $this->assertSame(Severity::Notice, Severity::fromLegacy('notice'));
        $this->assertSame(Severity::Notice, Severity::fromLegacy(null));
        $this->assertSame(Severity::Notice, Severity::fromLegacy('bogus'));
    }

    public function test_ranks_and_compares(): void
    {
        $this->assertTrue(Severity::Error->atLeast(Severity::Warning));
        $this->assertTrue(Severity::Warning->atLeast(Severity::Warning));
        $this->assertFalse(Severity::Notice->atLeast(Severity::Warning));
        $this->assertGreaterThan(Severity::Warning->rank(), Severity::Error->rank());
    }
}
