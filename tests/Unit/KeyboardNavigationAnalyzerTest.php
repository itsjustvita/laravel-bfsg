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

    private function page(string $body): string
    {
        return '<!DOCTYPE html><html><body>'.$body.'</body></html>';
    }

    public function test_missing_skip_link_only_without_main_landmark(): void
    {
        $violations = $this->analyze($this->page('<nav><a href="/a">A</a></nav><div>Content</div>'));

        $violation = $this->assertHasViolation($violations, 'keyboard.missing_skip_link', severity: Severity::Notice);
        $this->assertNull($violation->element);
        $this->assertSame('2.4.1', $violation->rule);
        $this->assertCount(1, $violations);

        $this->assertSame([], $this->analyze($this->page('<nav><a href="/a">A</a></nav><main>Content</main>')));
        $this->assertSame([], $this->analyze($this->page('<div role="main">Content</div>')));
    }

    public function test_missing_skip_link_is_skipped_on_fragments(): void
    {
        $this->assertSame([], $this->analyze('<div><a href="/a">A</a></div>'));
    }

    public function test_skip_link_among_the_first_three_links_in_document_order(): void
    {
        $html = '<!DOCTYPE html><html><body><header><a href="/">Logo</a></header><a href="#content">Zum Inhalt springen</a>'
            .'<div id="content">Text</div></body></html>';

        $this->assertSame([], $this->analyze($html));

        $late = $this->page('<a href="/1">1</a><a href="/2">2</a><a href="/3">3</a><a href="#c">Skip to content</a><div id="c">x</div>');
        $this->assertHasViolation($this->analyze($late), 'keyboard.missing_skip_link');
    }

    public function test_skip_link_patterns_are_whole_words_en_and_de(): void
    {
        foreach (['Skip to main content', 'Zum Inhalt', 'Inhalt überspringen', 'Zur Navigation', 'Zum Menü', 'Direkt zum Hauptinhalt', 'Jump to content', 'ZUM HAUPTINHALT'] as $text) {
            $this->assertSame([], $this->analyze($this->page('<a href="#t">'.$text.'</a><div id="t">x</div>')), $text);
        }

        $this->assertHasViolation($this->analyze($this->page('<a href="#t">Skipper boats</a><div id="t">x</div>')), 'keyboard.missing_skip_link');
        $this->assertHasViolation($this->analyze($this->page('<a href="#t">Mainstream news</a><div id="t">x</div>')), 'keyboard.missing_skip_link');
    }

    public function test_skip_link_target_must_exist(): void
    {
        $violations = $this->analyze($this->page('<a href="#nowhere">Skip to content</a><main id="main">x</main>'));

        $violation = $this->assertHasViolation($violations, 'keyboard.skip_link_target_missing', element: 'a', severity: Severity::Error);
        $this->assertSame('2.4.1', $violation->rule);
        $this->assertSame(['href' => '#nowhere'], $violation->params);
        $this->assertNoViolation($violations, 'keyboard.missing_skip_link');

        $this->assertSame([], $this->analyze($this->page('<a href="#top%20part">Skip navigation</a><a name="top part"></a><main>x</main>')));
    }

    public function test_positive_tabindex_one_finding_per_element(): void
    {
        $violations = $this->analyze('<button tabindex="1">First</button><button tabindex="2">Second</button><button tabindex="0">OK</button>');

        $violation = $this->assertHasViolation($violations, 'keyboard.positive_tabindex', element: 'button', severity: Severity::Warning);
        $this->assertSame('2.4.3', $violation->rule);
        $this->assertSame(['value' => 1], $violation->params);
        $this->assertViolationCount($violations, 'keyboard.positive_tabindex', 2);
    }

    public function test_negative_tabindex_on_interactive_is_a_notice_with_exemptions(): void
    {
        $html = '<button id="b" tabindex="-1">Hidden from tab</button>'
            .'<div role="tablist"><button role="tab" tabindex="-1">Inactive tab</button></div>'
            .'<div role="radiogroup" aria-label="x"><input type="radio" name="r" tabindex="-1"></div>'
            .'<iframe title="Map" tabindex="-1"></iframe>'
            .'<a href="/card" aria-hidden="true" tabindex="-1">Card</a>'
            .'<button disabled tabindex="-1">Off</button>'
            .'<div tabindex="-1">Programmatic focus target</div>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'keyboard.negative_tabindex_on_interactive', element: 'button#b', severity: Severity::Notice);
        $this->assertSame('2.1.1', $violation->rule);
        $this->assertSame(['tag' => 'button'], $violation->params);
        $this->assertFalse($violation->autoFixable);
        $this->assertViolationCount($violations, 'keyboard.negative_tabindex_on_interactive', 1);
    }

    public function test_dialogs_need_a_name_and_aria_modal(): void
    {
        $html = '<div role="dialog" id="d1"><p>Unnamed</p></div>'
            .'<div role="alertdialog" aria-labelledby="t" aria-modal="true" id="d2"><h2 id="t">Confirm</h2></div>'
            .'<dialog id="d3"><p>Native, unnamed</p></dialog>'
            .'<dialog aria-label="Settings" id="d4"></dialog>'
            .'<div class="modal" id="d5">Class only</div>'
            .'<div role="dialog" aria-label="Hidden" hidden id="d6"></div>';

        $violations = $this->analyze($html);

        $this->assertHasViolation($violations, 'keyboard.dialog_missing_name', element: 'div#d1', severity: Severity::Error);
        $this->assertHasViolation($violations, 'keyboard.dialog_missing_name', element: 'dialog#d3');
        $this->assertViolationCount($violations, 'keyboard.dialog_missing_name', 2);

        $modal = $this->assertHasViolation($violations, 'keyboard.dialog_missing_aria_modal', element: 'div#d1', severity: Severity::Warning);
        $this->assertSame('4.1.2', $modal->rule);
        $this->assertViolationCount($violations, 'keyboard.dialog_missing_aria_modal', 1);
    }

    public function test_click_without_keyboard(): void
    {
        $html = '<div id="plain" onclick="go()">Click</div>'
            .'<span id="focusable" tabindex="0" onclick="go()">No key handler</span>'
            .'<div id="ok1" tabindex="0" onclick="go()" onkeydown="go()">OK</div>'
            .'<div id="ok2" role="button" tabindex="0" onclick="go()">OK</div>'
            .'<div id="delegate" onclick="route(event)"><button>Inner</button></div>'
            .'<button onclick="go()">Native</button><label onclick="x()">L</label><a onclick="x()">A</a>';

        $violations = $this->analyze($this->page($html));

        $violation = $this->assertHasViolation($violations, 'keyboard.click_without_keyboard', element: 'div#plain', severity: Severity::Error);
        $this->assertSame(['tag' => 'div'], $violation->params);
        $this->assertHasViolation($violations, 'keyboard.click_without_keyboard', element: 'span#focusable', severity: Severity::Error);
        $this->assertHasViolation($violations, 'keyboard.click_without_keyboard', element: 'div#delegate', severity: Severity::Warning);
        $this->assertViolationCount($violations, 'keyboard.click_without_keyboard', 3);
    }

    public function test_widget_role_without_tabindex(): void
    {
        $html = '<div id="w" role="button">Fake button</div><div role="checkbox" aria-checked="false" tabindex="0">OK</div>'
            .'<button role="switch" aria-checked="false">Native</button>'
            .'<div role="listbox" aria-activedescendant="o1" tabindex="0"><div role="option" id="o1">A</div></div>'
            .'<div role="tab" aria-disabled="true">Disabled</div>'
            .'<div role="grid"><div role="row"><div role="gridcell" tabindex="0"><span role="checkbox" aria-checked="true" aria-label="Paid"></span></div></div></div>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'keyboard.role_without_tabindex', element: 'div#w', severity: Severity::Warning);
        $this->assertSame('2.1.1', $violation->rule);
        $this->assertSame(['role' => 'button', 'tag' => 'div'], $violation->params);
        $this->assertViolationCount($violations, 'keyboard.role_without_tabindex', 1);
    }

    public function test_anchor_not_focusable_only_when_used_as_a_control(): void
    {
        $html = '<a id="click" onclick="go()">Go</a><a id="role" role="button">Menu</a>'
            .'<a name="top"></a><a>Placeholder</a>'
            .'<a tabindex="0" onclick="go()" onkeydown="go()">Accessible</a><a role="button" tabindex="0">Accessible too</a>';

        $violations = $this->analyze($html);

        $this->assertHasViolation($violations, 'keyboard.anchor_not_focusable', element: 'a#click', severity: Severity::Warning);
        $this->assertHasViolation($violations, 'keyboard.anchor_not_focusable', element: 'a#role');
        $this->assertViolationCount($violations, 'keyboard.anchor_not_focusable', 2);
        $this->assertNoViolation($violations, 'keyboard.click_without_keyboard');
    }

    public function test_mouse_only_handler_is_a_notice_on_non_native_elements(): void
    {
        $html = '<div onmouseover="show()">Hover</div><div onmouseover="show()" onfocus="show()">Both</div><a href="/x" onmouseover="show()">Native</a>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'keyboard.mouse_only_handler', element: 'div', severity: Severity::Notice);
        $this->assertSame(['tag' => 'div'], $violation->params);
        $this->assertViolationCount($violations, 'keyboard.mouse_only_handler', 1);
    }

    public function test_hidden_elements_are_skipped(): void
    {
        $this->assertSame([], $this->analyze('<div hidden><div onclick="x()" tabindex="3" role="button">x</div></div>'));
    }
}
