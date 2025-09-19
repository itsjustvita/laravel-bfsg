<?php

use ItsJustVita\LaravelBfsg\Analyzers\KeyboardNavigationAnalyzer;

it('detects missing skip links', function () {
    $html = '<!DOCTYPE html><html><body>
        <nav>Navigation</nav>
        <main>Main content</main>
    </body></html>';

    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    $analyzer = new KeyboardNavigationAnalyzer();
    $violations = $analyzer->analyze($dom);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]['message'])->toContain('No skip link found');
});

it('accepts pages with skip links', function () {
    $html = '<!DOCTYPE html><html><body>
        <a href="#main" class="skip-link">Skip to main content</a>
        <nav>Navigation</nav>
        <main id="main">Main content</main>
    </body></html>';

    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    $analyzer = new KeyboardNavigationAnalyzer();
    $violations = $analyzer->analyze($dom);

    // Should not have skip link violation
    $skipLinkViolations = array_filter($violations, fn($v) => str_contains($v['message'], 'skip link'));
    expect($skipLinkViolations)->toBeEmpty();
});

it('warns about positive tabindex values', function () {
    $html = '<!DOCTYPE html><html><body>
        <button tabindex="1">First</button>
        <button tabindex="2">Second</button>
        <button tabindex="3">Third</button>
    </body></html>';

    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    $analyzer = new KeyboardNavigationAnalyzer();
    $violations = $analyzer->analyze($dom);

    $tabindexViolations = array_filter($violations, fn($v) => str_contains($v['message'], 'positive tabindex'));
    expect($tabindexViolations)->not->toBeEmpty();
});

it('detects modals without proper focus management', function () {
    $html = '<!DOCTYPE html><html><body>
        <div role="dialog">
            <h2>Modal Title</h2>
            <p>Modal content</p>
        </div>
    </body></html>';

    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    $analyzer = new KeyboardNavigationAnalyzer();
    $violations = $analyzer->analyze($dom);

    // Should have violations for missing aria-modal and aria-label
    $modalViolations = array_filter($violations, fn($v) => str_contains($v['message'], 'Modal'));
    expect($modalViolations)->toHaveCount(2);
});

it('accepts properly configured modals', function () {
    $html = '<!DOCTYPE html><html><body>
        <div role="dialog" aria-modal="true" aria-labelledby="modal-title">
            <h2 id="modal-title">Modal Title</h2>
            <p>Modal content</p>
        </div>
    </body></html>';

    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    $analyzer = new KeyboardNavigationAnalyzer();
    $violations = $analyzer->analyze($dom);

    // Should not have modal violations
    $modalViolations = array_filter($violations, fn($v) => str_contains($v['message'], 'Modal') || str_contains($v['message'], 'modal'));
    expect($modalViolations)->toBeEmpty();
});

it('detects links without href', function () {
    $html = '<!DOCTYPE html><html><body>
        <a>Click me</a>
        <a href="#">Valid link</a>
    </body></html>';

    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    $analyzer = new KeyboardNavigationAnalyzer();
    $violations = $analyzer->analyze($dom);

    $linkViolations = array_filter($violations, fn($v) => str_contains($v['message'], 'Link without href'));
    expect($linkViolations)->toHaveCount(1);
});

it('detects click handlers on non-interactive elements', function () {
    $html = '<!DOCTYPE html><html><body>
        <div onclick="doSomething()">Clickable div</div>
        <span onclick="handleClick()">Clickable span</span>
        <button onclick="valid()">Valid button</button>
    </body></html>';

    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    $analyzer = new KeyboardNavigationAnalyzer();
    $violations = $analyzer->analyze($dom);

    $clickViolations = array_filter($violations, fn($v) => str_contains($v['message'], 'click handler'));
    expect($clickViolations)->toHaveCount(2);
});

it('accepts non-interactive elements with proper keyboard support', function () {
    $html = '<!DOCTYPE html><html><body>
        <div onclick="doSomething()" tabindex="0" onkeydown="handleKey(event)" role="button">
            Properly accessible div button
        </div>
    </body></html>';

    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    $analyzer = new KeyboardNavigationAnalyzer();
    $violations = $analyzer->analyze($dom);

    // Should not have violations for this properly configured element
    $clickViolations = array_filter($violations, fn($v) => str_contains($v['message'], 'click handler'));
    expect($clickViolations)->toBeEmpty();
});

it('warns about mouse-only event handlers', function () {
    $html = '<!DOCTYPE html><html><body>
        <div onmouseover="showTooltip()" onmouseout="hideTooltip()">
            Hover for tooltip
        </div>
    </body></html>';

    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    $analyzer = new KeyboardNavigationAnalyzer();
    $violations = $analyzer->analyze($dom);

    $mouseViolations = array_filter($violations, fn($v) => str_contains($v['message'], 'mouse events'));
    expect($mouseViolations)->toHaveCount(1);
});

it('accepts elements with both mouse and keyboard events', function () {
    $html = '<!DOCTYPE html><html><body>
        <div onmouseover="show()" onmouseout="hide()" onfocus="show()" onblur="hide()">
            Accessible hover element
        </div>
    </body></html>';

    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    $analyzer = new KeyboardNavigationAnalyzer();
    $violations = $analyzer->analyze($dom);

    $mouseViolations = array_filter($violations, fn($v) => str_contains($v['message'], 'mouse events'));
    expect($mouseViolations)->toBeEmpty();
});