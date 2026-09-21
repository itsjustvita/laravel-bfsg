<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Analyzers\KeyboardNavigationAnalyzer;
use ItsJustVita\LaravelBfsg\Contracts\Analyzer;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\Support\AnalyzerTestCase;

class KeyboardNavigationAnalyzerTest extends AnalyzerTestCase
{
    protected function analyzer(): Analyzer
    {
        return new KeyboardNavigationAnalyzer;
    }

    public function test_detects_missing_skip_links(): void
    {
        $html = '<!DOCTYPE html><html><body>
            <nav>Navigation</nav>
            <main>Main content</main>
        </body></html>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'keyboard.missing_skip_link', severity: Severity::Warning);
        $this->assertNull($violation->element);
        $this->assertSame('2.4.1', $violation->rule);
        $this->assertFalse($violation->autoFixable);
        $this->assertCount(1, $violations);
    }

    public function test_accepts_pages_with_skip_links(): void
    {
        $html = '<!DOCTYPE html><html><body>
            <a href="#main" class="skip-link">Skip to main content</a>
            <nav>Navigation</nav>
            <main id="main">Main content</main>
        </body></html>';

        $this->assertNoViolation($this->analyze($html), 'keyboard.missing_skip_link');
    }

    public function test_warns_about_positive_tabindex_values(): void
    {
        $html = '<!DOCTYPE html><html><body>
            <button tabindex="1">First</button>
            <button tabindex="2">Second</button>
            <button tabindex="3">Third</button>
        </body></html>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'keyboard.positive_tabindex', element: 'button', severity: Severity::Warning);
        $this->assertSame('2.4.3', $violation->rule);
        $this->assertSame(['value' => 1], $violation->params);
        $this->assertViolationCount($violations, 'keyboard.positive_tabindex', 3);
    }

    public function test_detects_negative_tabindex_on_interactive_elements(): void
    {
        $html = '<!DOCTYPE html><html><body>
            <a href="#main">Skip to main content</a>
            <button tabindex="-1">Hidden from tab order</button>
        </body></html>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'keyboard.negative_tabindex_on_interactive', element: 'button', severity: Severity::Warning);
        $this->assertSame('2.1.1', $violation->rule);
        $this->assertSame(['tag' => 'button'], $violation->params);
        $this->assertViolationCount($violations, 'keyboard.negative_tabindex_on_interactive', 1);
    }

    public function test_detects_modals_without_proper_focus_management(): void
    {
        $html = '<!DOCTYPE html><html><body>
            <div role="dialog">
                <h2>Modal Title</h2>
                <p>Modal content</p>
            </div>
        </body></html>';

        $violations = $this->analyze($html);

        $missingModal = $this->assertHasViolation($violations, 'keyboard.dialog_missing_aria_modal', element: 'div', severity: Severity::Error);
        $this->assertSame('2.1.2', $missingModal->rule);

        $missingName = $this->assertHasViolation($violations, 'keyboard.dialog_missing_name', element: 'div', severity: Severity::Error);
        $this->assertSame('4.1.2', $missingName->rule);

        $this->assertViolationCount($violations, 'keyboard.dialog_missing_aria_modal', 1);
        $this->assertViolationCount($violations, 'keyboard.dialog_missing_name', 1);
    }

    public function test_accepts_properly_configured_modals(): void
    {
        $html = '<!DOCTYPE html><html><body>
            <div role="dialog" aria-modal="true" aria-labelledby="modal-title">
                <h2 id="modal-title">Modal Title</h2>
                <p>Modal content</p>
            </div>
        </body></html>';

        $violations = $this->analyze($html);

        $this->assertNoViolation($violations, 'keyboard.dialog_missing_aria_modal');
        $this->assertNoViolation($violations, 'keyboard.dialog_missing_name');
    }

    public function test_detects_links_without_href(): void
    {
        $html = '<!DOCTYPE html><html><body>
            <a>Click me</a>
            <a href="#">Valid link</a>
        </body></html>';

        $violations = $this->analyze($html);

        // v2.2.0 Fix 3: a warning, not an error — many modern <a> elements
        // use tabindex+JS and ARE keyboard-accessible.
        $violation = $this->assertHasViolation($violations, 'keyboard.anchor_not_focusable', element: 'a', severity: Severity::Warning);
        $this->assertSame('2.1.1', $violation->rule);
        $this->assertSame([], $violation->params);
        $this->assertViolationCount($violations, 'keyboard.anchor_not_focusable', 1);
    }

    public function test_anchor_without_href_but_with_tabindex_and_keyboard_handler_is_accepted(): void
    {
        // v2.2.0 Fix 3: <a tabindex="0" onkeydown="..."> is keyboard-accessible.
        $html = '<!DOCTYPE html><html><body>
            <a tabindex="0" onkeydown="handleKey(event)" onclick="handleClick()">Interactive</a>
        </body></html>';

        $this->assertNoViolation($this->analyze($html), 'keyboard.anchor_not_focusable');
    }

    public function test_anchor_without_href_but_with_role_button_and_tabindex_is_accepted(): void
    {
        // v2.2.0 Fix 3: <a role="button" tabindex="0"> is a button-styled anchor.
        $html = '<!DOCTYPE html><html><body>
            <a role="button" tabindex="0">Button-styled anchor</a>
        </body></html>';

        $this->assertNoViolation($this->analyze($html), 'keyboard.anchor_not_focusable');
    }

    public function test_german_skip_link_zum_inhalt_is_recognized(): void
    {
        // v2.2.0 Fix 4: German "Zum Inhalt" must count as a skip link.
        $html = '<!DOCTYPE html><html><body>
            <a href="#main" class="skip-link">Zum Inhalt</a>
            <nav>Navigation</nav>
            <main id="main">Main content</main>
        </body></html>';

        $this->assertNoViolation($this->analyze($html), 'keyboard.missing_skip_link');
    }

    public function test_german_skip_link_ueberspringen_is_recognized(): void
    {
        // v2.2.0 Fix 4: German "Überspringen" (with umlaut) must count.
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>
            <a href="#main">Navigation überspringen</a>
            <nav>Navigation</nav>
            <main id="main">Main content</main>
        </body></html>';

        $this->assertNoViolation($this->analyze('<?xml encoding="utf-8" ?>'.$html), 'keyboard.missing_skip_link');
    }

    public function test_german_skip_link_zur_navigation_is_recognized(): void
    {
        // v2.2.0 Fix 4: "Zur Navigation" is a common German skip-link label.
        $html = '<!DOCTYPE html><html><body>
            <a href="#nav">Zur Navigation</a>
            <nav id="nav">Navigation</nav>
            <main>Main content</main>
        </body></html>';

        $this->assertNoViolation($this->analyze($html), 'keyboard.missing_skip_link');
    }

    public function test_detects_click_handlers_on_non_interactive_elements(): void
    {
        $html = '<!DOCTYPE html><html><body>
            <div onclick="doSomething()">Clickable div</div>
            <span onclick="handleClick()">Clickable span</span>
            <button onclick="valid()">Valid button</button>
        </body></html>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'keyboard.click_without_keyboard', element: 'div', severity: Severity::Error);
        $this->assertSame('2.1.1', $violation->rule);
        $this->assertSame(['tag' => 'div'], $violation->params);
        $this->assertHasViolation($violations, 'keyboard.click_without_keyboard', element: 'span', severity: Severity::Error);
        $this->assertViolationCount($violations, 'keyboard.click_without_keyboard', 2);
    }

    public function test_accepts_non_interactive_elements_with_proper_keyboard_support(): void
    {
        $html = '<!DOCTYPE html><html><body>
            <div onclick="doSomething()" tabindex="0" onkeydown="handleKey(event)" role="button">
                Properly accessible div button
            </div>
        </body></html>';

        $this->assertNoViolation($this->analyze($html), 'keyboard.click_without_keyboard');
    }

    public function test_warns_about_mouse_only_event_handlers(): void
    {
        $html = '<!DOCTYPE html><html><body>
            <div onmouseover="showTooltip()" onmouseout="hideTooltip()">
                Hover for tooltip
            </div>
        </body></html>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'keyboard.mouse_only_handler', element: 'div', severity: Severity::Warning);
        $this->assertSame('2.1.1', $violation->rule);
        $this->assertSame(['tag' => 'div'], $violation->params);
        $this->assertViolationCount($violations, 'keyboard.mouse_only_handler', 1);
    }

    public function test_accepts_elements_with_both_mouse_and_keyboard_events(): void
    {
        $html = '<!DOCTYPE html><html><body>
            <div onmouseover="show()" onmouseout="hide()" onfocus="show()" onblur="hide()">
                Accessible hover element
            </div>
        </body></html>';

        $this->assertNoViolation($this->analyze($html), 'keyboard.mouse_only_handler');
    }
}
