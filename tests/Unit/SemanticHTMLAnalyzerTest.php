<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Analyzers\SemanticHTMLAnalyzer;
use ItsJustVita\LaravelBfsg\Contracts\Analyzer;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\Support\AnalyzerTestCase;

class SemanticHTMLAnalyzerTest extends AnalyzerTestCase
{
    protected function analyzer(): Analyzer
    {
        return new SemanticHTMLAnalyzer;
    }

    public function test_missing_main_cites_2_4_1(): void
    {
        $violations = $this->analyze('<!DOCTYPE html><html><body><div>Content</div></body></html>');

        $violation = $this->assertHasViolation($violations, 'semantic.missing_main', severity: Severity::Warning);
        $this->assertSame('2.4.1', $violation->rule);
        $this->assertNull($violation->element);
        $this->assertCount(1, $violations);
    }

    public function test_missing_main_is_skipped_on_fragments_and_satisfied_by_role_main(): void
    {
        $this->assertSame([], $this->analyze('<div>Partial</div>'));
        $this->assertSame([], $this->analyze('<html><body><div role="MAIN">Content</div></body></html>'));
    }

    public function test_landmark_and_div_heuristics_are_gone(): void
    {
        $html = '<html><body><main>'.str_repeat('<div>x</div>', 20).'<a role="button" href="#">Act</a></main></body></html>';

        $this->assertSame([], $this->analyze($html));
    }

    public function test_multiple_visible_mains(): void
    {
        $violations = $this->analyze('<html><body><main>One</main><main hidden>Hidden</main><div role="main">Two</div></body></html>');

        $violation = $this->assertHasViolation($violations, 'semantic.multiple_main', element: 'div', severity: Severity::Error);
        $this->assertSame('1.3.1', $violation->rule);
        $this->assertSame(['index' => 2], $violation->params);
        $this->assertViolationCount($violations, 'semantic.multiple_main', 1);
    }

    public function test_section_without_heading_is_a_notice(): void
    {
        $html = '<html><body><main><section id="bare"><p>x</p></section><section aria-label="News"><p>x</p></section>'
            .'<section><h2>Title</h2></section><section><div role="HEADING" aria-level="2">T</div></section></main></body></html>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'semantic.section_without_heading', element: 'section#bare', severity: Severity::Notice);
        $this->assertSame('1.3.1', $violation->rule);
        $this->assertViolationCount($violations, 'semantic.section_without_heading', 1);
    }

    public function test_empty_lists_use_class_tokens_data_attributes_and_roles(): void
    {
        $html = '<html><body><main><ul id="plain"></ul><ol></ol>'
            .'<ul class="swiper-wrapper"></ul><ul class="js-menu menu"></ul><ul data-items></ul><ul role="listbox"></ul>'
            .'<ul class="navigation-free"></ul><ul class="snavigation"></ul>'
            .'</main></body></html>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'semantic.empty_list', element: 'ul#plain', severity: Severity::Notice);
        $this->assertSame(['tag' => 'ul'], $violation->params);
        $this->assertViolationCount($violations, 'semantic.empty_list', 4);
    }

    public function test_button_with_href_is_a_best_practice_notice(): void
    {
        $violations = $this->analyze('<html><body><main><button href="/x">Go</button><button href="">Empty</button></main></body></html>');

        $violation = $this->assertHasViolation($violations, 'semantic.button_with_href', element: 'button', severity: Severity::Notice);
        $this->assertSame('4.1.2', $violation->rule);
        $this->assertSame(['best-practice'], $violation->tags);
        $this->assertViolationCount($violations, 'semantic.button_with_href', 1);
    }

    public function test_proper_semantic_html_has_no_findings(): void
    {
        $html = '<!DOCTYPE html><html lang="en"><body><header><nav><ul><li><a href="/">Home</a></li></ul></nav></header>'
            .'<main><article><h1>Title</h1><section><h2>Part</h2><p>x</p></section></article></main><footer>f</footer></body></html>';

        $this->assertSame([], $this->analyze($html));
    }
}
