<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Analyzers\AriaAnalyzer;
use ItsJustVita\LaravelBfsg\Contracts\Analyzer;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\Support\AnalyzerTestCase;

class AriaAnalyzerTest extends AnalyzerTestCase
{
    protected function analyzer(): Analyzer
    {
        return new AriaAnalyzer;
    }

    public function test_detects_invalid_roles(): void
    {
        $violations = $this->analyze('<div role="invalid-role">Content</div><div role="  ">Blank</div><div role="foo NAVIGATION">Fallback</div>');

        $violation = $this->assertHasViolation($violations, 'aria.invalid_role', element: 'div', severity: Severity::Error);
        $this->assertSame('4.1.2', $violation->rule);
        $this->assertSame(['role' => 'invalid-role'], $violation->params);
        $this->assertCount(1, $violations);
    }

    public function test_detects_abstract_roles(): void
    {
        $violations = $this->analyze('<div role="widget">x</div><div role="landmark region" aria-label="x">y</div>');

        $violation = $this->assertHasViolation($violations, 'aria.abstract_role', severity: Severity::Error);
        $this->assertSame(['role' => 'widget'], $violation->params);
        $this->assertViolationCount($violations, 'aria.abstract_role', 2);
        $this->assertNoViolation($violations, 'aria.invalid_role');
    }

    public function test_missing_required_state_one_finding_per_element(): void
    {
        $violations = $this->analyze('<div role="slider" tabindex="0">Slider</div><div role="checkbox" tabindex="0">x</div><div role="combobox" aria-expanded="false">c</div>');

        $slider = $this->assertHasViolation($violations, 'aria.missing_required_state', element: 'div', severity: Severity::Error);
        $this->assertSame(['role' => 'slider', 'attribute' => 'aria-valuenow'], $slider->params);
        $this->assertViolationCount($violations, 'aria.missing_required_state', 2);
    }

    public function test_native_elements_provide_their_required_state(): void
    {
        $html = '<input type="checkbox" role="switch"><input type="RANGE" role="slider"><select role="combobox"><option>a</option></select><h2 role="heading">x</h2>';

        $this->assertNoViolation($this->analyze($html), 'aria.missing_required_state');
    }

    public function test_unsupported_state_uses_effective_or_implicit_role(): void
    {
        $html = '<div id="plain" aria-selected="true">x</div>'
            .'<div role="button" aria-pressed="true">Toggle</div>'
            .'<div role="row" aria-selected="true"><div role="gridcell" aria-selected="false">c</div></div>'
            .'<a href="/" aria-expanded="false">Menu</a>'
            .'<select><option aria-selected="true">a</option></select>'
            .'<input type="checkbox" aria-checked="true">'
            .'<span id="span" aria-pressed="true">y</span>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'aria.unsupported_state', element: 'div#plain', severity: Severity::Warning);
        $this->assertSame(['attribute' => 'aria-selected', 'tag' => 'div'], $violation->params);
        $this->assertHasViolation($violations, 'aria.unsupported_state', element: 'span#span');
        $this->assertViolationCount($violations, 'aria.unsupported_state', 2);
    }

    public function test_hidden_focusable(): void
    {
        $html = '<button id="b" aria-hidden="true">Hidden Button</button>'
            .'<div aria-hidden="TRUE"><a id="inner" href="/x">Inner link</a><a href="/y" tabindex="-1">Removed</a><a>No href</a></div>'
            .'<a href="/card" aria-hidden="true" tabindex="-1"><img src="c.jpg" alt=""></a>'
            .'<input type="hidden" aria-hidden="true"><button disabled aria-hidden="true">Off</button>'
            .'<div hidden><button aria-hidden="true">Not rendered</button></div>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'aria.hidden_focusable', element: 'button#b', severity: Severity::Error);
        $this->assertSame(['tag' => 'button'], $violation->params);
        $this->assertHasViolation($violations, 'aria.hidden_focusable', element: 'a#inner');
        $this->assertViolationCount($violations, 'aria.hidden_focusable', 2);
    }

    public function test_redundant_role_is_an_auto_fixable_notice(): void
    {
        $violations = $this->analyze('<html><body><header role="banner">x</header><article><header role="banner">y</header></article>'
            .'<input type="checkbox" role="checkbox"><a href="/" role="link">l</a><a role="link" tabindex="0">m</a></body></html>');

        $violation = $this->assertHasViolation($violations, 'aria.redundant_role', element: 'header', severity: Severity::Notice);
        $this->assertTrue($violation->autoFixable);
        $this->assertSame(['role' => 'banner', 'tag' => 'header'], $violation->params);
        $this->assertHasViolation($violations, 'aria.redundant_role', element: 'input');
        $this->assertViolationCount($violations, 'aria.redundant_role', 3);
    }

    public function test_list_roles_are_kept_for_the_voiceover_list_style_workaround(): void
    {
        $violations = $this->analyze('<html><body><ul role="list" class="list-none"><li role="listitem">a</li></ul>'
            .'<ol role="list"><li>b</li></ol><menu role="list"><li role="listitem">c</li></menu><nav role="navigation">n</nav></body></html>');

        $this->assertHasViolation($violations, 'aria.redundant_role', element: 'nav');
        $this->assertViolationCount($violations, 'aria.redundant_role', 1);
    }

    public function test_dangling_idrefs_one_finding_per_element(): void
    {
        $html = '<div aria-labelledby="missing">a</div>'
            .'<div id="multi" aria-describedby="help gone" aria-controls="nowhere">b</div><span id="help">Help</span>'
            .'<input aria-errormessage="err" aria-activedescendant="opt1"><div aria-details="det" aria-owns="own" aria-flowto="next">c</div>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'aria.dangling_idref', element: 'div#multi', severity: Severity::Error);
        $this->assertSame('1.3.1', $violation->rule);
        $this->assertSame(['4.1.2'], $violation->related);
        $this->assertSame(['attribute' => 'aria-describedby', 'id' => 'gone'], $violation->params);
        $this->assertSame([['attribute' => 'aria-describedby', 'id' => 'gone'], ['attribute' => 'aria-controls', 'id' => 'nowhere']], $violation->meta['references']);
        $this->assertViolationCount($violations, 'aria.dangling_idref', 4);
    }

    public function test_duplicate_ids_only_when_referenced(): void
    {
        $html = '<label for="email">Email</label><input id="email"><input id="email">'
            .'<p id="dup">a</p><p id="dup">b</p>'
            .'<div aria-describedby="note">x</div><span id="note">n</span><span id="note">m</span>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'aria.duplicate_id', element: 'input#email', severity: Severity::Warning);
        $this->assertSame('4.1.1', $violation->rule);
        $this->assertSame(['id' => 'email'], $violation->params);
        $this->assertHasViolation($violations, 'aria.duplicate_id', element: 'span#note');
        $this->assertViolationCount($violations, 'aria.duplicate_id', 2);
    }

    public function test_aria_label_with_labelledby_is_not_a_conflict(): void
    {
        $this->assertSame([], $this->analyze('<div role="region" aria-label="Label" aria-labelledby="named">Content</div><span id="named">Named</span>'));
    }

    public function test_hidden_elements_are_skipped_except_for_hidden_focusable(): void
    {
        $this->assertSame([], $this->analyze('<div hidden><div role="bogus" aria-labelledby="none">x</div></div>'));
    }

    public function test_valid_aria_passes(): void
    {
        $html = '<button aria-label="Save document">Save</button>'
            .'<div role="navigation" aria-label="Main navigation">Nav</div>'
            .'<input type="text" aria-describedby="help-text"><span id="help-text">Enter your name</span>';

        $this->assertSame([], $this->analyze($html));
    }

    public function test_containers_hidden_by_the_stylesheet_are_not_rendered(): void
    {
        $html = '<html><head><style>.modal { display: none } .modal.show { display: block }</style></head><body>'
            .'<div class="modal fade" id="closed" tabindex="-1" aria-hidden="true"><button id="x" type="button" aria-label="Close"></button></div>'
            .'<div class="modal show" aria-hidden="true"><button id="open" type="button">Visible but hidden from AT</button></div>'
            .'</body></html>';

        $violations = $this->analyze($html);

        $this->assertHasViolation($violations, 'aria.hidden_focusable', element: 'button#open');
        $this->assertViolationCount($violations, 'aria.hidden_focusable', 1);
    }
}
