<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use DOMDocument;
use ItsJustVita\LaravelBfsg\Analyzers\KeyboardNavigationAnalyzer;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class KeyboardNavigationAnalyzerTest extends TestCase
{
    public function test_detects_missing_skip_links(): void
    {
        $html = '<!DOCTYPE html><html><body>
            <nav>Navigation</nav>
            <main>Main content</main>
        </body></html>';

        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $analyzer = new KeyboardNavigationAnalyzer();
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('No skip link found', $violations[0]['message']);
    }

    public function test_accepts_pages_with_skip_links(): void
    {
        $html = '<!DOCTYPE html><html><body>
            <a href="#main" class="skip-link">Skip to main content</a>
            <nav>Navigation</nav>
            <main id="main">Main content</main>
        </body></html>';

        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $analyzer = new KeyboardNavigationAnalyzer();
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        // Should not have skip link violation
        $skipLinkViolations = array_filter($violations, fn($v) => str_contains($v['message'], 'skip link'));
        $this->assertEmpty($skipLinkViolations);
    }

    public function test_warns_about_positive_tabindex_values(): void
    {
        $html = '<!DOCTYPE html><html><body>
            <button tabindex="1">First</button>
            <button tabindex="2">Second</button>
            <button tabindex="3">Third</button>
        </body></html>';

        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $analyzer = new KeyboardNavigationAnalyzer();
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $tabindexViolations = array_filter($violations, fn($v) => str_contains($v['message'], 'positive tabindex'));
        $this->assertNotEmpty($tabindexViolations);
    }

    public function test_detects_modals_without_proper_focus_management(): void
    {
        $html = '<!DOCTYPE html><html><body>
            <div role="dialog">
                <h2>Modal Title</h2>
                <p>Modal content</p>
            </div>
        </body></html>';

        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $analyzer = new KeyboardNavigationAnalyzer();
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        // Should have violations for missing aria-modal and aria-label
        $modalViolations = array_filter($violations, fn($v) => str_contains($v['message'], 'Modal'));
        $this->assertCount(2, $modalViolations);
    }

    public function test_accepts_properly_configured_modals(): void
    {
        $html = '<!DOCTYPE html><html><body>
            <div role="dialog" aria-modal="true" aria-labelledby="modal-title">
                <h2 id="modal-title">Modal Title</h2>
                <p>Modal content</p>
            </div>
        </body></html>';

        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $analyzer = new KeyboardNavigationAnalyzer();
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        // Should not have modal violations
        $modalViolations = array_filter($violations, fn($v) => str_contains($v['message'], 'Modal') || str_contains($v['message'], 'modal'));
        $this->assertEmpty($modalViolations);
    }

    public function test_detects_links_without_href(): void
    {
        $html = '<!DOCTYPE html><html><body>
            <a>Click me</a>
            <a href="#">Valid link</a>
        </body></html>';

        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $analyzer = new KeyboardNavigationAnalyzer();
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $linkViolations = array_filter($violations, fn($v) => str_contains($v['message'], 'Link without href'));
        $this->assertCount(1, $linkViolations);
    }

    public function test_detects_click_handlers_on_non_interactive_elements(): void
    {
        $html = '<!DOCTYPE html><html><body>
            <div onclick="doSomething()">Clickable div</div>
            <span onclick="handleClick()">Clickable span</span>
            <button onclick="valid()">Valid button</button>
        </body></html>';

        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $analyzer = new KeyboardNavigationAnalyzer();
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $clickViolations = array_filter($violations, fn($v) => str_contains($v['message'], 'click handler'));
        $this->assertCount(2, $clickViolations);
    }

    public function test_accepts_non_interactive_elements_with_proper_keyboard_support(): void
    {
        $html = '<!DOCTYPE html><html><body>
            <div onclick="doSomething()" tabindex="0" onkeydown="handleKey(event)" role="button">
                Properly accessible div button
            </div>
        </body></html>';

        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $analyzer = new KeyboardNavigationAnalyzer();
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        // Should not have violations for this properly configured element
        $clickViolations = array_filter($violations, fn($v) => str_contains($v['message'], 'click handler'));
        $this->assertEmpty($clickViolations);
    }

    public function test_warns_about_mouse_only_event_handlers(): void
    {
        $html = '<!DOCTYPE html><html><body>
            <div onmouseover="showTooltip()" onmouseout="hideTooltip()">
                Hover for tooltip
            </div>
        </body></html>';

        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $analyzer = new KeyboardNavigationAnalyzer();
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $mouseViolations = array_filter($violations, fn($v) => str_contains($v['message'], 'mouse events'));
        $this->assertCount(1, $mouseViolations);
    }

    public function test_accepts_elements_with_both_mouse_and_keyboard_events(): void
    {
        $html = '<!DOCTYPE html><html><body>
            <div onmouseover="show()" onmouseout="hide()" onfocus="show()" onblur="hide()">
                Accessible hover element
            </div>
        </body></html>';

        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $analyzer = new KeyboardNavigationAnalyzer();
        $result = $analyzer->analyze($dom);
        $violations = $result['issues'] ?? [];

        $mouseViolations = array_filter($violations, fn($v) => str_contains($v['message'], 'mouse events'));
        $this->assertEmpty($mouseViolations);
    }
}