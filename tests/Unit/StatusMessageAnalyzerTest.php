<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Analyzers\StatusMessageAnalyzer;
use ItsJustVita\LaravelBfsg\Contracts\Analyzer;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\Support\AnalyzerTestCase;

class StatusMessageAnalyzerTest extends AnalyzerTestCase
{
    protected function analyzer(): Analyzer
    {
        return new StatusMessageAnalyzer;
    }

    public function test_detects_page_with_forms_but_no_live_region(): void
    {
        $violations = $this->analyze('<html><body><form><input type="text" name="search"><button type="submit">Search</button></form></body></html>');

        // Downgraded from Warning to Notice in v2.2.0 — firing on every page with forms
        // was too noisy and created many false positives.
        $violation = $this->assertHasViolation($violations, 'status_messages.no_live_region', severity: Severity::Notice);
        $this->assertNull($violation->element);
        $this->assertSame('4.1.3', $violation->rule);
        $this->assertSame([], $violation->params);
        $this->assertFalse($violation->autoFixable);
        $this->assertCount(1, $violations);
    }

    public function test_accepts_page_with_aria_live_polite(): void
    {
        $this->assertSame([], $this->analyze('<html><body><form><button type="submit">Submit</button></form><div aria-live="polite"></div></body></html>'));
    }

    public function test_accepts_page_with_role_status(): void
    {
        $this->assertSame([], $this->analyze('<html><body><form><button type="submit">Submit</button></form><div role="status"></div></body></html>'));
    }

    public function test_no_live_region_is_reported_as_a_document_level_notice(): void
    {
        // v2.2.0 Fix 9: was `warning`, now `notice` — too noisy otherwise.
        $violations = $this->analyze('<html><body><form><button type="submit">Submit</button></form></body></html>');

        $violation = $this->assertHasViolation($violations, 'status_messages.no_live_region', severity: Severity::Notice);
        $this->assertNull($violation->element);
        $this->assertNull($violation->selector);
        $this->assertCount(1, $violations);
    }

    public function test_no_issues_without_dynamic_content(): void
    {
        $this->assertSame([], $this->analyze('<html><body><p>Static content only</p></body></html>'));
    }

    public function test_detects_invalid_aria_live_value(): void
    {
        $violations = $this->analyze('<html><body><div aria-live="yes">Saved</div></body></html>');

        $violation = $this->assertHasViolation($violations, 'status_messages.invalid_aria_live', element: 'div', severity: Severity::Error);
        $this->assertSame('4.1.3', $violation->rule);
        $this->assertSame(['value' => 'yes'], $violation->params);
        $this->assertFalse($violation->autoFixable);
        $this->assertCount(1, $violations);
    }

    public function test_accepts_every_valid_aria_live_value(): void
    {
        $this->assertSame([], $this->analyze('<html><body><div aria-live="polite"></div><div aria-live="assertive"></div><div aria-live="off"></div></body></html>'));
    }

    public function test_reports_each_invalid_aria_live_element(): void
    {
        $violations = $this->analyze('<html><body><div id="a" aria-live="true"></div><span id="b" aria-live="loud"></span></body></html>');

        $this->assertViolationCount($violations, 'status_messages.invalid_aria_live', 2);
        $this->assertHasViolation($violations, 'status_messages.invalid_aria_live', element: 'div#a');
        $this->assertHasViolation($violations, 'status_messages.invalid_aria_live', element: 'span#b');
        $this->assertNoViolation($violations, 'status_messages.no_live_region');
    }

    public function test_implicit_live_role_suppresses_the_missing_live_region_notice(): void
    {
        foreach (['status', 'alert', 'log', 'progressbar', 'timer'] as $role) {
            $violations = $this->analyze('<html><body><form><button type="submit">Go</button></form><div role="'.$role.'"></div></body></html>');

            $this->assertNoViolation($violations, 'status_messages.no_live_region');
        }
    }
}
