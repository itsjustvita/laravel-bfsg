<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Analyzers\FormAnalyzer;
use ItsJustVita\LaravelBfsg\Contracts\Analyzer;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\Support\AnalyzerTestCase;

class FormAnalyzerTest extends AnalyzerTestCase
{
    protected function analyzer(): Analyzer
    {
        return new FormAnalyzer;
    }

    public function test_detects_inputs_without_labels(): void
    {
        $violations = $this->analyze('<form><input type="text" name="email"></form>');

        $violation = $this->assertHasViolation($violations, 'forms.control_missing_label', element: 'input', severity: Severity::Error);
        $this->assertSame('4.1.2', $violation->rule);
        $this->assertSame(['1.3.1', '3.3.2'], $violation->related);
        $this->assertSame(['name' => 'email', 'type' => 'text'], $violation->params);
        $this->assertCount(1, $violations);
    }

    public function test_input_without_type_and_uppercase_type_are_checked(): void
    {
        $violations = $this->analyze('<form><input name="a"><input TYPE="Email" name="b"><input type="HIDDEN" name="c"></form>');

        $this->assertViolationCount($violations, 'forms.control_missing_label', 2);
        $this->assertSame([['name' => 'a', 'type' => 'text'], ['name' => 'b', 'type' => 'email']], array_map(fn ($v) => $v->params, $violations));
    }

    public function test_accepts_every_naming_technique(): void
    {
        $html = '<form>'
            .'<label for="a">Email</label><input type="text" id="a">'
            .'<label>Name <input type="text"></label>'
            .'<input type="text" aria-label="Search">'
            .'<span id="lbl">Phone</span><input type="tel" aria-labelledby="lbl">'
            .'<input type="text" title="Postcode">'
            .'<select aria-label="Country"><option>DE</option></select>'
            .'<textarea aria-label="Message"></textarea>'
            .'</form>';

        $this->assertSame([], $this->analyze($html));
    }

    public function test_empty_label_does_not_count(): void
    {
        $violations = $this->analyze('<form><label for="x"> </label><input type="text" id="x" name="x"></form>');

        $this->assertHasViolation($violations, 'forms.control_missing_label', element: 'input#x');
    }

    public function test_label_lookup_is_safe_for_ids_with_quotes(): void
    {
        $html = '<form><label for="it\'s">Item</label><input type="text" id="it\'s"><input type="text" id="a&quot;b\'c"></form>';

        $violations = $this->analyze($html);

        $this->assertViolationCount($violations, 'forms.control_missing_label', 1);
    }

    public function test_detects_textareas_and_selects_without_labels(): void
    {
        $violations = $this->analyze('<form><textarea name="message"></textarea><select name="country"><option>DE</option></select></form>');

        $this->assertSame(['name' => 'message', 'type' => 'textarea'], $this->assertHasViolation($violations, 'forms.control_missing_label', element: 'textarea')->params);
        $this->assertSame(['name' => 'country', 'type' => 'select'], $this->assertHasViolation($violations, 'forms.control_missing_label', element: 'select')->params);
    }

    public function test_name_param_falls_back_to_id_then_unnamed(): void
    {
        $violations = $this->analyze('<form><input type="text" id="phone"><input type="text"></form>');

        $this->assertSame('phone', $this->assertHasViolation($violations, 'forms.control_missing_label', element: 'input#phone')->params['name']);
        $this->assertContains('unnamed', array_map(fn ($v) => $v->params['name'], $violations));
    }

    public function test_hidden_controls_and_buttons_are_not_labelled_controls(): void
    {
        $html = '<form><input type="hidden" name="_token"><input type="submit"><input type="reset"><input type="image" src="go.png" alt="Go">'
            .'<div hidden><input type="text" name="ghost"></div></form>';

        $this->assertSame([], $this->analyze($html));
    }

    public function test_detects_buttons_without_name(): void
    {
        $html = '<form><button id="icon"><svg aria-hidden="true"></svg></button><input type="button" id="ib">'
            .'<div role="button" id="rb" tabindex="0"></div>'
            .'<button>Send</button><button aria-label="Close">×</button><input type="submit"></form>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'forms.button_missing_name', element: 'button#icon', severity: Severity::Error);
        $this->assertSame('4.1.2', $violation->rule);
        $this->assertSame(['tag' => 'button'], $violation->params);
        $this->assertHasViolation($violations, 'forms.button_missing_name', element: 'input#ib');
        $this->assertHasViolation($violations, 'forms.button_missing_name', element: 'div#rb');
        $this->assertViolationCount($violations, 'forms.button_missing_name', 3);
    }

    public function test_radio_group_needs_a_group_label(): void
    {
        $html = '<form>'
            .'<label><input type="radio" name="size" value="s"> S</label><label><input type="radio" name="size" value="m"> M</label>'
            .'<fieldset><legend>Colour</legend><label><input type="radio" name="colour"> Red</label><label><input type="radio" name="colour"> Blue</label></fieldset>'
            .'<div role="radiogroup" aria-label="Shipping"><label><input type="radio" name="ship"> Std</label><label><input type="radio" name="ship"> Exp</label></div>'
            .'<label><input type="radio" name="single"> Only one</label>'
            .'</form>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'forms.radio_group_missing_legend', element: 'input', severity: Severity::Warning);
        $this->assertSame('1.3.1', $violation->rule);
        $this->assertSame(['name' => 'size'], $violation->params);
        $this->assertViolationCount($violations, 'forms.radio_group_missing_legend', 1);
    }

    public function test_required_field_without_visible_indication(): void
    {
        $html = '<form><label for="a">Email</label><input type="email" id="a" name="email" required>'
            .'<label for="b">Name <span aria-hidden="true">*</span></label><input id="b" name="name" required>'
            .'<label for="c">Telefon (Pflichtfeld)</label><input id="c" name="tel" aria-required="TRUE">'
            .'</form>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'forms.required_not_indicated', element: 'input#a', severity: Severity::Notice);
        $this->assertSame('3.3.2', $violation->rule);
        $this->assertSame(['name' => 'email'], $violation->params);
        $this->assertViolationCount($violations, 'forms.required_not_indicated', 1);
    }

    public function test_form_level_required_hint_counts(): void
    {
        $html = '<form aria-describedby="hint"><p id="hint">Fields marked * are required.</p><label for="a">Email</label><input id="a" required></form>'
            .'<form><fieldset><legend>Alle Felder sind erforderlich</legend><label for="b">Ort</label><input id="b" required></fieldset></form>';

        $this->assertNoViolation($this->analyze($html), 'forms.required_not_indicated');
    }

    public function test_unnamed_form_and_required_without_aria_required_are_not_findings(): void
    {
        $this->assertSame([], $this->analyze('<form><label for="e">E-Mail *</label><input id="e" required></form>'));
    }
}
