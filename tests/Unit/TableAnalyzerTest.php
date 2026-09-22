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

    public function test_layout_table_with_semantics_is_one_finding_and_nothing_else(): void
    {
        $html = '<table role="presentation" summary="x"><caption>Layout</caption><tr><th>A</th><td>B</td></tr><tr><td>C</td><td>D</td></tr></table>'
            .'<table role="NONE"><tr><td>A</td></tr><tr><td>B</td></tr></table>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'tables.layout_table_with_semantics', element: 'table', severity: Severity::Warning);
        $this->assertSame(['found' => 'th, caption, summary'], $violation->params);
        $this->assertCount(1, $violations);
    }

    public function test_missing_headers_needs_two_rows_and_no_header_association(): void
    {
        $html = '<table id="bad"><caption>c</caption><tr><td>1</td><td>2</td></tr><tr><td>3</td><td>4</td></tr></table>'
            .'<table><caption>c</caption><tr><td>single row</td></tr></table>'
            .'<table><caption>c</caption><tr><td scope="col">h</td></tr><tr><td>1</td></tr></table>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'tables.missing_headers', element: 'table#bad', severity: Severity::Error);
        $this->assertSame('1.3.1', $violation->rule);
        $this->assertViolationCount($violations, 'tables.missing_headers', 1);
    }

    public function test_missing_caption_is_a_notice_with_alternatives(): void
    {
        $html = '<table id="none"><tr><th>A</th></tr></table>'
            .'<table aria-label="Prices"><tr><th>A</th></tr></table>'
            .'<h2 id="t">Opening hours</h2><table aria-labelledby="t"><tr><th>A</th></tr></table>'
            .'<figure><figcaption>Results</figcaption><table><tr><th>A</th></tr></table></figure>'
            .'<table><caption>Quarterly results</caption><tr><th>A</th></tr></table>';

        $violations = $this->analyze($html);

        $this->assertHasViolation($violations, 'tables.missing_caption', element: 'table#none', severity: Severity::Notice);
        $this->assertViolationCount($violations, 'tables.missing_caption', 1);
    }

    public function test_simple_tables_do_not_need_scope(): void
    {
        $html = '<table><caption>Simple</caption><tr><th>Name</th><th>Price</th></tr><tr><td>A</td><td>1</td></tr></table>';

        $this->assertSame([], $this->analyze($html));
    }

    public function test_complex_tables_need_scope_unless_ids_and_headers_are_used(): void
    {
        $complex = '<table><caption>Complex</caption><tr><th>Region</th><th>Q1</th></tr><tr><th>North</th><td>1</td></tr></table>';
        $violations = $this->analyze($complex);

        $violation = $this->assertHasViolation($violations, 'tables.th_missing_scope', element: 'th', severity: Severity::Warning);
        $this->assertSame(['content' => 'Region'], $violation->params);
        $this->assertViolationCount($violations, 'tables.th_missing_scope', 3);

        $twoHeaderRows = '<table><caption>c</caption><tr><th>A</th><th>B</th></tr><tr><th>a</th><th>b</th></tr><tr><td>1</td><td>2</td></tr></table>';
        $this->assertViolationCount($this->analyze($twoHeaderRows), 'tables.th_missing_scope', 4);

        $associated = '<table><caption>c</caption><tr><th id="r">Region</th><th id="q">Q1</th></tr><tr><th id="n">North</th><td headers="n q">1</td></tr></table>';
        $this->assertSame([], $this->analyze($associated));
    }

    public function test_invalid_scope_is_compared_lowercase(): void
    {
        $html = '<table><caption>c</caption><tr><th scope="COL">A</th><th scope="column">B</th></tr><tr><td>1</td><td>2</td></tr></table>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'tables.invalid_scope', element: 'th', severity: Severity::Error);
        $this->assertSame(['value' => 'column'], $violation->params);
        $this->assertViolationCount($violations, 'tables.invalid_scope', 1);
    }

    public function test_dangling_headers_one_finding_per_cell(): void
    {
        $html = '<table><caption>c</caption><tr><th id="h1">A</th><th id="h2">B</th></tr>'
            .'<tr><td headers="h1  missing gone">1</td><th headers="nope" scope="row">2</th></tr></table>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'tables.dangling_headers_ref', element: 'td', severity: Severity::Error);
        $this->assertSame(['id' => 'missing'], $violation->params);
        $this->assertSame(['ids' => ['missing', 'gone']], $violation->meta);
        $this->assertHasViolation($violations, 'tables.dangling_headers_ref', element: 'th');
        $this->assertViolationCount($violations, 'tables.dangling_headers_ref', 2);
    }

    public function test_nested_table_once_per_nested_table_and_cells_belong_to_their_own_table(): void
    {
        $html = '<table><caption>Outer</caption><tr><th scope="col">A</th></tr><tr><td>'
            .'<table id="inner"><caption>Inner</caption><tr><td>1</td></tr><tr><td>2</td></tr></table>'
            .'</td></tr></table>';

        $violations = $this->analyze($html);

        $this->assertHasViolation($violations, 'tables.nested_table', element: 'table#inner', severity: Severity::Notice);
        $this->assertViolationCount($violations, 'tables.nested_table', 1);
        $this->assertHasViolation($violations, 'tables.missing_headers', element: 'table#inner');
        $this->assertViolationCount($violations, 'tables.missing_headers', 1);
    }

    public function test_hidden_tables_are_skipped(): void
    {
        $this->assertSame([], $this->analyze('<div hidden><table><tr><td>1</td></tr><tr><td>2</td></tr></table></div>'));
    }
}
