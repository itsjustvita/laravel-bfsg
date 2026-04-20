<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use DOMDocument;
use ItsJustVita\LaravelBfsg\Analyzers\SemanticHTMLAnalyzer;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class SemanticHTMLAnalyzerTest extends TestCase
{
    protected SemanticHTMLAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new SemanticHTMLAnalyzer;
    }

    protected function analyzeHtml(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        return $this->analyzer->analyze($dom);
    }

    public function test_detects_missing_main_landmark()
    {
        $result = $this->analyzeHtml('<html><body><div>Content</div></body></html>');

        $this->assertTrue(
            collect($result['issues'])->contains(fn ($i) => str_contains($i['message'], 'No <main> landmark')),
            'Should detect missing main landmark'
        );
    }

    public function test_has_main_landmark_produces_no_main_missing_issues()
    {
        $result = $this->analyzeHtml('
            <html><body>
                <header><nav><a href="/">Home</a></nav></header>
                <main><h1>Content</h1><p>Hello</p></main>
                <footer><p>Footer</p></footer>
            </body></html>
        ');

        $mainMissing = collect($result['issues'])->filter(
            fn ($i) => str_contains($i['message'], 'No <main> landmark')
        );

        $this->assertEmpty($mainMissing, 'Page with main element should not produce main-missing issues');
    }

    public function test_detects_multiple_main_elements()
    {
        $result = $this->analyzeHtml('
            <html><body>
                <main><p>First</p></main>
                <main><p>Second</p></main>
            </body></html>
        ');

        $this->assertTrue(
            collect($result['issues'])->contains(fn ($i) => str_contains($i['message'], 'Multiple <main>')),
            'Should detect multiple main elements'
        );
    }

    public function test_detects_section_without_heading()
    {
        $result = $this->analyzeHtml('
            <html><body>
                <main>
                    <section><p>Content without heading</p></section>
                </main>
            </body></html>
        ');

        $this->assertTrue(
            collect($result['issues'])->contains(fn ($i) => str_contains($i['message'], '<section>') && str_contains($i['message'], 'without heading')),
            'Should detect section without heading or aria-label'
        );
    }

    public function test_detects_excessive_divs()
    {
        // Create HTML where divs make up more than 40% of elements
        $divs = str_repeat('<div>x</div>', 20);
        $result = $this->analyzeHtml("<html><body>{$divs}</body></html>");

        $this->assertTrue(
            collect($result['issues'])->contains(fn ($i) => str_contains($i['message'], 'Excessive use of <div>')),
            'Should detect excessive div usage'
        );
    }

    public function test_detects_button_with_href()
    {
        $result = $this->analyzeHtml('
            <html><body>
                <main><button href="/page">Go</button></main>
            </body></html>
        ');

        $this->assertTrue(
            collect($result['issues'])->contains(fn ($i) => str_contains($i['message'], '<button> with href')),
            'Should detect button element with href attribute'
        );
    }

    public function test_detects_list_without_li()
    {
        $result = $this->analyzeHtml('
            <html><body>
                <main>
                    <ul><div>Not a list item</div></ul>
                    <ol><span>Not a list item</span></ol>
                </main>
            </body></html>
        ');

        $listIssues = collect($result['issues'])->filter(
            fn ($i) => str_contains($i['message'], 'without <li>')
        );

        $this->assertGreaterThanOrEqual(1, $listIssues->count(), 'Should detect ul/ol without li children');
    }

    public function test_all_findings_use_type_field_not_severity()
    {
        // v2.2.0 Fix 1: this analyzer must emit `type` (not `severity`) to match the
        // rest of the package. Downstream consumers read `type`.
        $result = $this->analyzeHtml('<html><body><div>Content</div></body></html>');

        $this->assertNotEmpty($result['issues']);
        foreach ($result['issues'] as $issue) {
            $this->assertArrayHasKey('type', $issue, 'Every finding must expose `type`');
            $this->assertArrayNotHasKey('severity', $issue, '`severity` key must be gone');
            $this->assertContains($issue['type'], ['error', 'warning', 'notice']);
        }
    }

    public function test_empty_ul_with_carousel_class_is_not_flagged()
    {
        // v2.2.0 Fix 2: JS-populated lists must be skipped.
        $result = $this->analyzeHtml('
            <html><body>
                <main><ul class="swiper-wrapper carousel"></ul></main>
            </body></html>
        ');

        $ulIssues = collect($result['issues'])->filter(
            fn ($i) => str_contains($i['message'], '<ul> element without')
        );

        $this->assertEmpty($ulIssues, 'JS-driven carousel <ul> should be skipped');
    }

    public function test_empty_ul_with_data_attribute_is_not_flagged()
    {
        // v2.2.0 Fix 2: any data-* attribute signals likely JS population.
        $result = $this->analyzeHtml('
            <html><body>
                <main><ul data-slick="{}"></ul></main>
            </body></html>
        ');

        $ulIssues = collect($result['issues'])->filter(
            fn ($i) => str_contains($i['message'], '<ul> element without')
        );

        $this->assertEmpty($ulIssues, '<ul> with data-* attribute should be skipped');
    }

    public function test_empty_ul_with_listbox_role_is_not_flagged()
    {
        // v2.2.0 Fix 2: listbox/menu/tree roles signal JS population.
        $result = $this->analyzeHtml('
            <html><body>
                <main><ul role="listbox"></ul></main>
            </body></html>
        ');

        $ulIssues = collect($result['issues'])->filter(
            fn ($i) => str_contains($i['message'], '<ul> element without')
        );

        $this->assertEmpty($ulIssues, '<ul role="listbox"> should be skipped');
    }

    public function test_plain_empty_ul_is_still_flagged_as_notice()
    {
        // v2.2.0 Fix 2: unhinted empty <ul> → notice (not error anymore).
        $result = $this->analyzeHtml('
            <html><body>
                <main><ul></ul></main>
            </body></html>
        ');

        $ulIssues = collect($result['issues'])->filter(
            fn ($i) => str_contains($i['message'], '<ul> element without')
        )->values();

        $this->assertCount(1, $ulIssues);
        $this->assertSame('notice', $ulIssues->first()['type']);
    }

    public function test_proper_semantic_html_has_no_error_level_issues()
    {
        $result = $this->analyzeHtml('
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

        $errorIssues = collect($result['issues'])->filter(
            fn ($i) => ($i['type'] ?? '') === 'error'
        );

        $this->assertEmpty($errorIssues, 'Proper semantic HTML should not produce error-level issues');
    }
}
