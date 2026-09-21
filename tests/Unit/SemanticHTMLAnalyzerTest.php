<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Analyzers\SemanticHTMLAnalyzer;
use ItsJustVita\LaravelBfsg\Contracts\Analyzer;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\Support\AnalyzerTestCase;
use ItsJustVita\LaravelBfsg\Violation;

class SemanticHTMLAnalyzerTest extends AnalyzerTestCase
{
    protected function analyzer(): Analyzer
    {
        return new SemanticHTMLAnalyzer;
    }

    public function test_detects_missing_main_landmark(): void
    {
        $violations = $this->analyze('<html><body><div>Content</div></body></html>');

        $violation = $this->assertHasViolation($violations, 'semantic.missing_main', severity: Severity::Warning);
        $this->assertSame('1.3.1', $violation->rule);
        $this->assertNull($violation->element);
        $this->assertSame([], $violation->params);
    }

    public function test_detects_missing_nav_header_and_footer_landmarks(): void
    {
        $violations = $this->analyze('<html><body><div>Content</div></body></html>');

        foreach (['semantic.missing_nav', 'semantic.missing_header', 'semantic.missing_footer'] as $key) {
            $violation = $this->assertHasViolation($violations, $key, severity: Severity::Notice);
            $this->assertSame('1.3.1', $violation->rule);
            $this->assertNull($violation->element);
        }
    }

    public function test_has_main_landmark_produces_no_main_missing_issues(): void
    {
        $violations = $this->analyze('
            <html><body>
                <header><nav><a href="/">Home</a></nav></header>
                <main><h1>Content</h1><p>Hello</p></main>
                <footer><p>Footer</p></footer>
            </body></html>
        ');

        $this->assertNoViolation($violations, 'semantic.missing_main');
        $this->assertNoViolation($violations, 'semantic.missing_nav');
        $this->assertNoViolation($violations, 'semantic.missing_header');
        $this->assertNoViolation($violations, 'semantic.missing_footer');
    }

    public function test_detects_multiple_main_elements(): void
    {
        $violations = $this->analyze('
            <html><body>
                <main><p>First</p></main>
                <main><p>Second</p></main>
            </body></html>
        ');

        $violation = $this->assertHasViolation($violations, 'semantic.multiple_main', element: 'main', severity: Severity::Error);
        $this->assertSame('1.3.1', $violation->rule);
        $this->assertSame(['index' => 2], $violation->params);
        $this->assertViolationCount($violations, 'semantic.multiple_main', 1);
    }

    public function test_detects_section_without_heading(): void
    {
        $violations = $this->analyze('
            <html><body>
                <main>
                    <section><p>Content without heading</p></section>
                </main>
            </body></html>
        ');

        $violation = $this->assertHasViolation($violations, 'semantic.section_without_heading', element: 'section', severity: Severity::Warning);
        $this->assertSame('2.4.6', $violation->rule);
        $this->assertSame([], $violation->params);
    }

    public function test_section_with_aria_label_is_not_flagged(): void
    {
        $violations = $this->analyze('
            <html><body>
                <main>
                    <section aria-label="Latest news"><p>Content</p></section>
                </main>
            </body></html>
        ');

        $this->assertNoViolation($violations, 'semantic.section_without_heading');
    }

    public function test_detects_excessive_divs(): void
    {
        // Create HTML where divs make up more than 40% of elements
        $divs = str_repeat('<div>x</div>', 20);
        $violations = $this->analyze("<html><body>{$divs}</body></html>");

        $violation = $this->assertHasViolation($violations, 'semantic.div_ratio', severity: Severity::Notice);
        $this->assertSame('1.3.1', $violation->rule);
        $this->assertNull($violation->element);
        $this->assertIsInt($violation->params['ratio']);
        $this->assertGreaterThan(40, $violation->params['ratio']);
    }

    public function test_detects_button_with_href(): void
    {
        $violations = $this->analyze('
            <html><body>
                <main><button href="/page">Go</button></main>
            </body></html>
        ');

        $violation = $this->assertHasViolation($violations, 'semantic.button_with_href', element: 'button', severity: Severity::Error);
        $this->assertSame('1.3.1', $violation->rule);
        $this->assertSame([], $violation->params);
    }

    public function test_detects_anchor_used_as_button(): void
    {
        $violations = $this->analyze('
            <html><body>
                <main><a href="#" role="button">Open dialog</a></main>
            </body></html>
        ');

        $violation = $this->assertHasViolation($violations, 'semantic.anchor_as_button', element: 'a', severity: Severity::Warning);
        $this->assertSame('1.3.1', $violation->rule);
        $this->assertSame(['4.1.2'], $violation->related);
    }

    public function test_anchor_with_role_button_and_real_href_is_not_flagged(): void
    {
        $violations = $this->analyze('
            <html><body>
                <main><a href="/page" role="button">Go</a></main>
            </body></html>
        ');

        $this->assertNoViolation($violations, 'semantic.anchor_as_button');
    }

    public function test_detects_list_without_li(): void
    {
        $violations = $this->analyze('
            <html><body>
                <main>
                    <ul><div>Not a list item</div></ul>
                    <ol><span>Not a list item</span></ol>
                </main>
            </body></html>
        ');

        $this->assertViolationCount($violations, 'semantic.empty_list', 2);
        $this->assertSame(['tag' => 'ul'], $this->assertHasViolation($violations, 'semantic.empty_list', element: 'ul')->params);
        $this->assertSame(['tag' => 'ol'], $this->assertHasViolation($violations, 'semantic.empty_list', element: 'ol')->params);
    }

    public function test_empty_ul_with_carousel_class_is_not_flagged(): void
    {
        // JS-populated lists must be skipped.
        $violations = $this->analyze('
            <html><body>
                <main><ul class="swiper-wrapper carousel"></ul></main>
            </body></html>
        ');

        $this->assertNoViolation($violations, 'semantic.empty_list');
    }

    public function test_empty_ul_with_data_attribute_is_not_flagged(): void
    {
        // Any data-* attribute signals likely JS population.
        $violations = $this->analyze('
            <html><body>
                <main><ul data-slick="{}"></ul></main>
            </body></html>
        ');

        $this->assertNoViolation($violations, 'semantic.empty_list');
    }

    public function test_empty_ul_with_listbox_role_is_not_flagged(): void
    {
        // listbox/menu/tree roles signal JS population.
        $violations = $this->analyze('
            <html><body>
                <main><ul role="listbox"></ul></main>
            </body></html>
        ');

        $this->assertNoViolation($violations, 'semantic.empty_list');
    }

    public function test_plain_empty_ul_is_still_flagged_as_notice(): void
    {
        $violations = $this->analyze('
            <html><body>
                <main><ul></ul></main>
            </body></html>
        ');

        $this->assertViolationCount($violations, 'semantic.empty_list', 1);
        $violation = $this->assertHasViolation($violations, 'semantic.empty_list', element: 'ul', severity: Severity::Notice);
        $this->assertSame('1.3.1', $violation->rule);
        $this->assertSame(['tag' => 'ul'], $violation->params);
    }

    public function test_proper_semantic_html_has_no_error_level_issues(): void
    {
        $violations = $this->analyze('
            <html lang="en"><body>
                <header>
                    <nav aria-label="Main"><ul><li><a href="/">Home</a></li></ul></nav>
                </header>
                <main>
                    <article>
                        <h1>Article Title</h1>
                        <section>
                            <h2>Section</h2>
                            <p>Content here</p>
                        </section>
                    </article>
                </main>
                <footer><p>Footer content</p></footer>
            </body></html>
        ');

        $this->assertSame([], array_filter($violations, fn (Violation $v) => $v->severity === Severity::Error));
    }
}
