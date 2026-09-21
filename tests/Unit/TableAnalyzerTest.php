<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Analyzers\TableAnalyzer;
use ItsJustVita\LaravelBfsg\Contracts\Analyzer;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\Support\AnalyzerTestCase;

class TableAnalyzerTest extends AnalyzerTestCase
{
    protected function analyzer(): Analyzer
    {
        return new TableAnalyzer;
    }

    public function test_detects_missing_caption_on_table(): void
    {
        $violations = $this->analyze('
            <table>
                <tr><th scope="col">Name</th></tr>
                <tr><td>Alice</td></tr>
            </table>
        ');

        $violation = $this->assertHasViolation($violations, 'tables.missing_caption', element: 'table', severity: Severity::Warning);
        $this->assertSame('1.3.1', $violation->rule);
        $this->assertSame([], $violation->params);
    }

    public function test_table_with_caption_has_no_caption_issues(): void
    {
        $violations = $this->analyze('
            <table>
                <caption>User list</caption>
                <tr><th scope="col">Name</th></tr>
                <tr><td>Alice</td></tr>
            </table>
        ');

        $this->assertNoViolation($violations, 'tables.missing_caption');
    }

    public function test_detects_missing_scope_on_th(): void
    {
        $violations = $this->analyze('
            <table>
                <caption>Test</caption>
                <tr><th>Name</th></tr>
                <tr><td>Alice</td></tr>
            </table>
        ');

        $violation = $this->assertHasViolation($violations, 'tables.th_missing_scope', element: 'th', severity: Severity::Error);
        $this->assertSame('1.3.1', $violation->rule);
        $this->assertSame(['content' => 'Name'], $violation->params);
    }

    public function test_detects_invalid_scope_value(): void
    {
        $violations = $this->analyze('
            <table>
                <caption>Test</caption>
                <tr><th scope="all">Name</th></tr>
                <tr><td>Alice</td></tr>
            </table>
        ');

        $violation = $this->assertHasViolation($violations, 'tables.invalid_scope', element: 'th', severity: Severity::Error);
        $this->assertSame('1.3.1', $violation->rule);
        $this->assertSame(['value' => 'all'], $violation->params);
        $this->assertNoViolation($violations, 'tables.th_missing_scope');
    }

    public function test_detects_table_without_header_cells(): void
    {
        $violations = $this->analyze('
            <table>
                <caption>Test</caption>
                <tr><td>Name</td><td>Age</td></tr>
                <tr><td>Alice</td><td>30</td></tr>
            </table>
        ');

        $violation = $this->assertHasViolation($violations, 'tables.missing_headers', element: 'table', severity: Severity::Error);
        $this->assertSame('1.3.1', $violation->rule);
        $this->assertSame([], $violation->params);
    }

    public function test_detects_dangling_headers_reference(): void
    {
        $violations = $this->analyze('
            <table>
                <caption>Test</caption>
                <tr><th id="name" scope="col">Name</th></tr>
                <tr><td headers="name missing">Alice</td></tr>
            </table>
        ');

        $violation = $this->assertHasViolation($violations, 'tables.dangling_headers_ref', element: 'td', severity: Severity::Error);
        $this->assertSame('1.3.1', $violation->rule);
        $this->assertSame(['id' => 'missing'], $violation->params);
        $this->assertViolationCount($violations, 'tables.dangling_headers_ref', 1);
    }

    public function test_detects_layout_table_with_semantic_elements(): void
    {
        $violations = $this->analyze('
            <table role="presentation">
                <caption>Layout caption</caption>
                <tr><th>Header</th></tr>
                <tr><td>Content</td></tr>
            </table>
        ');

        $violation = $this->assertHasViolation($violations, 'tables.layout_table_with_semantics', element: 'table', severity: Severity::Warning);
        $this->assertSame('1.3.1', $violation->rule);
        $this->assertSame('th', $violation->params['found']);
        $this->assertViolationCount($violations, 'tables.layout_table_with_semantics', 2);
    }

    public function test_detects_nested_tables(): void
    {
        $violations = $this->analyze('
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

        $violation = $this->assertHasViolation($violations, 'tables.nested_table', element: 'table', severity: Severity::Warning);
        $this->assertSame('1.3.1', $violation->rule);
        $this->assertViolationCount($violations, 'tables.nested_table', 1);
    }

    public function test_html_without_tables_produces_no_violations(): void
    {
        $violations = $this->analyze('<html><body><p>No tables here</p></body></html>');

        $this->assertSame([], $violations);
    }
}
