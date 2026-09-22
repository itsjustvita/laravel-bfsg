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

    public function test_page_level_live_region_notice_is_gone(): void
    {
        $this->assertSame([], $this->analyze('<html><body><form><input type="text"><button>Send</button></form></body></html>'));
    }

    public function test_invalid_aria_live_values(): void
    {
        $violations = $this->analyze('<div aria-live="loud">A</div><div aria-live="POLITE">B</div><div aria-live="rude">C</div>');

        $violation = $this->assertHasViolation($violations, 'status_messages.invalid_aria_live', element: 'div', severity: Severity::Error);
        $this->assertSame('4.1.3', $violation->rule);
        $this->assertSame(['value' => 'loud'], $violation->params);
        $this->assertViolationCount($violations, 'status_messages.invalid_aria_live', 2);
    }

    public function test_every_valid_aria_live_value_is_accepted(): void
    {
        $this->assertSame([], $this->analyze('<div aria-live="polite">a</div><div aria-live="assertive">b</div><div aria-live="off">c</div>'));
    }

    public function test_empty_aria_live_is_a_notice(): void
    {
        $violation = $this->assertHasViolation($this->analyze('<div aria-live=" ">x</div>'), 'status_messages.empty_aria_live', severity: Severity::Notice);

        $this->assertSame('4.1.3', $violation->rule);
    }

    public function test_message_containers_need_a_live_region(): void
    {
        $html = '<div id="flash" class="alert alert-success">Saved.</div>'
            .'<div class="toast" role="status">Copied</div>'
            .'<div class="notification is-danger" role="ALERT">Failed</div>'
            .'<div aria-live="polite"><p class="message">Inside a live region</p></div>'
            .'<output class="message">42</output>'
            .'<div class="notice" aria-live="off">Off is not announced</div>'
            .'<div class="alert-box">Not a token match</div>'
            .'<div class="flash"><span class="message">Nested</span></div>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'status_messages.alert_without_live_region', element: 'div#flash.alert.alert-success', severity: Severity::Warning);
        $this->assertSame('4.1.3', $violation->rule);
        $this->assertViolationCount($violations, 'status_messages.alert_without_live_region', 3);
    }

    public function test_hidden_containers_are_skipped(): void
    {
        $this->assertSame([], $this->analyze('<div class="toast" hidden>Later</div><div aria-hidden="true" class="alert">x</div>'));
    }
}
