<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use DOMDocument;
use ItsJustVita\LaravelBfsg\Analyzers\TableAnalyzer;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class TableAnalyzerTest extends TestCase
{
    protected TableAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new TableAnalyzer;
    }

    protected function analyzeHtml(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        return $this->analyzer->analyze($dom);
    }

    public function test_detects_missing_caption_on_table()
    {
        $result = $this->analyzeHtml('
            <table>
                <tr><th scope="col">Name</th></tr>
                <tr><td>Alice</td></tr>
            </table>
        ');

        $this->assertTrue(
            collect($result['issues'])->contains(fn ($i) => str_contains($i['message'], 'without caption')),
            'Should detect table without caption'
        );
    }

    public function test_table_with_caption_has_no_caption_issues()
    {
        $result = $this->analyzeHtml('
            <table>
                <caption>User list</caption>
                <tr><th scope="col">Name</th></tr>
                <tr><td>Alice</td></tr>
            </table>
        ');

        $captionIssues = collect($result['issues'])->filter(
            fn ($i) => str_contains($i['message'], 'caption')
        );

        $this->assertEmpty($captionIssues, 'Table with caption should not produce caption issues');
    }

    public function test_detects_missing_scope_on_th()
    {
        $result = $this->analyzeHtml('
            <table>
                <caption>Test</caption>
                <tr><th>Name</th></tr>
                <tr><td>Alice</td></tr>
            </table>
        ');

        $this->assertTrue(
            collect($result['issues'])->contains(fn ($i) => str_contains($i['message'], 'without scope')),
            'Should detect th without scope attribute'
        );
    }

    public function test_detects_table_without_header_cells()
    {
        $result = $this->analyzeHtml('
            <table>
                <caption>Test</caption>
                <tr><td>Name</td><td>Age</td></tr>
                <tr><td>Alice</td><td>30</td></tr>
            </table>
        ');

        $this->assertTrue(
            collect($result['issues'])->contains(fn ($i) => str_contains($i['message'], 'without header cells')),
            'Should detect data table without th elements'
        );
    }

    public function test_detects_layout_table_with_semantic_elements()
    {
        $result = $this->analyzeHtml('
            <table role="presentation">
                <caption>Layout caption</caption>
                <tr><th>Header</th></tr>
                <tr><td>Content</td></tr>
            </table>
        ');

        $layoutIssues = collect($result['issues'])->filter(
            fn ($i) => str_contains($i['message'], 'Layout table') && str_contains($i['message'], 'role="presentation"')
        );

        $this->assertNotEmpty($layoutIssues, 'Should detect semantic elements in layout table');
    }

    public function test_detects_nested_tables()
    {
        $result = $this->analyzeHtml('
            <table>
                <caption>Outer</caption>
                <tr><th scope="col">Data</th></tr>
                <tr><td>
                    <table>
                        <tr><td>Nested</td></tr>
                    </table>
                </td></tr>
            </table>
        ');

        $this->assertTrue(
            collect($result['issues'])->contains(fn ($i) => str_contains($i['message'], 'Nested tables')),
            'Should detect nested tables'
        );
    }

    public function test_all_findings_use_type_field_not_severity()
    {
        // v2.2.0 Fix 1: TableAnalyzer must emit `type` (not `severity`).
        $result = $this->analyzeHtml('
            <table>
                <tr><th>Name</th></tr>
                <tr><td>Alice</td></tr>
            </table>
        ');

        $this->assertNotEmpty($result['issues']);
        foreach ($result['issues'] as $issue) {
            $this->assertArrayHasKey('type', $issue);
            $this->assertArrayNotHasKey('severity', $issue);
            $this->assertContains($issue['type'], ['error', 'warning', 'notice']);
        }
    }

    public function test_stats_critical_count_uses_type_field()
    {
        // v2.2.0 Fix 1: `stats.critical_issues` counter must read `type` key.
        $result = $this->analyzeHtml('
            <table>
                <tr><th>Name</th></tr>
                <tr><td>Alice</td></tr>
            </table>
        ');

        // th without scope + data table without th-scope → at least 1 error-type finding.
        $this->assertGreaterThan(0, $result['stats']['critical_issues']);
    }

    public function test_html_without_tables_returns_empty_issues()
    {
        $result = $this->analyzeHtml('<html><body><p>No tables here</p></body></html>');

        $this->assertEmpty($result['issues']);
    }
}
