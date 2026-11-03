<?php

namespace ItsJustVita\LaravelBfsg\Tests\Feature;

use ItsJustVita\LaravelBfsg\Analyzers\ContrastAnalyzer;
use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Dom\HtmlDocument;
use ItsJustVita\LaravelBfsg\Tests\Support\BenchmarkPage;
use ItsJustVita\LaravelBfsg\Tests\Support\PerformanceBudget;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use ItsJustVita\LaravelBfsg\Violation;
use PHPUnit\Framework\Attributes\Group;

/** Spec C7 and the §2 performance anti-goal on the AN4 benchmark page. */
class ContrastPerformanceTest extends TestCase
{
    /** 3.0 on the AN4 benchmark, from the spec (§2): contrast and all analyzers, in seconds (ledger ruling R39). */
    private const BASELINE_CONTRAST_SECONDS = 1.6;

    private const BASELINE_TOTAL_SECONDS = 7.7;

    #[Group('performance')]
    public function test_benchmark_page_within_budget(): void
    {
        $html = BenchmarkPage::cards();
        $document = HtmlDocument::fromHtml($html);

        $start = microtime(true);
        $violations = (new ContrastAnalyzer(level: 'AA'))->analyze($document);
        $contrast = microtime(true) - $start;

        $start = microtime(true);
        (new Bfsg)->analyze($html);
        $total = microtime(true) - $start;

        // The whole page is measured: every card's date in one finding, and the footer note after them.
        $keys = array_map(fn (Violation $violation) => $violation->key.' '.$violation->element, $violations);
        $this->assertSame(['contrast.insufficient p.date', 'contrast.insufficient p.note'], $keys);
        $this->assertSame(3000, $violations[0]->meta['occurrences']);

        $this->assertLessThan(PerformanceBudget::seconds(1.2 * self::BASELINE_CONTRAST_SECONDS), $contrast, sprintf('contrast took %.2f s', $contrast));
        $this->assertLessThan(PerformanceBudget::seconds(1.2 * self::BASELINE_TOTAL_SECONDS), $total, sprintf('all analyzers took %.2f s', $total));
    }
}
