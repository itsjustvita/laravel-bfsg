<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class SeverityTest extends TestCase
{
    public function test_ranks_and_compares(): void
    {
        $this->assertTrue(Severity::Error->atLeast(Severity::Warning));
        $this->assertTrue(Severity::Warning->atLeast(Severity::Warning));
        $this->assertFalse(Severity::Notice->atLeast(Severity::Warning));
        $this->assertGreaterThan(Severity::Warning->rank(), Severity::Error->rank());
    }

    public function test_label_is_translated(): void
    {
        $this->assertSame('Error', Severity::Error->label());
        $this->assertSame('Warnung', Severity::Warning->label('de'));
        $this->assertSame('Hinweis', Severity::Notice->label('de'));
    }
}
