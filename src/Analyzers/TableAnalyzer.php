<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMElement;
use ItsJustVita\LaravelBfsg\Dom\Element;
use ItsJustVita\LaravelBfsg\Dom\Roles;
use ItsJustVita\LaravelBfsg\Dom\Text;
use ItsJustVita\LaravelBfsg\Severity;

class TableAnalyzer extends BaseAnalyzer
{
    private const MAX_CONTENT = 30;

    private const VALID_SCOPES = ['col', 'row', 'colgroup', 'rowgroup'];

    protected string $key = 'tables';

    protected string $description = 'Data table structure';

    protected array $rules = ['1.3.1'];

    protected function inspect(): void
    {
        foreach ($this->queryVisible('//table') as $table) {
            if ($this->query('ancestor::table', $table) !== []) {
                $this->report('nested_table', Severity::Notice, '1.3.1', $table);
            }

            if (in_array(Roles::effective($table), ['presentation', 'none'], true)) {
                $this->checkLayoutTable($table);

                continue;
            }

            $this->checkDataTable($table);
        }
    }

    /** Layout tables get exactly one finding when they carry data-table semantics, and nothing else. */
    protected function checkLayoutTable(DOMElement $table): void
    {
        $found = [];

        if ($this->own($table, './/th') !== []) {
            $found[] = 'th';
        }

        if ($this->own($table, './caption') !== []) {
            $found[] = 'caption';
        }

        if ($table->hasAttribute('summary')) {
            $found[] = 'summary';
        }

        if ($found !== []) {
            $this->report('layout_table_with_semantics', Severity::Warning, '1.3.1', $table, ['found' => implode(', ', $found)]);
        }
    }

    protected function checkDataTable(DOMElement $table): void
    {
        $rows = $this->own($table, './/tr');
        $headers = $this->own($table, './/th');
        $cells = [...$headers, ...$this->own($table, './/td')];
        $hasScope = array_filter($cells, fn (DOMElement $cell) => $cell->hasAttribute('scope')) !== [];
        $hasHeadersAttr = array_filter($cells, fn (DOMElement $cell) => $cell->hasAttribute('headers')) !== [];

        if (count($rows) >= 2 && $headers === [] && ! $hasScope && ! $hasHeadersAttr) {
            $this->report('missing_headers', Severity::Error, '1.3.1', $table);
        }

        if (! $this->hasCaption($table)) {
            $this->report('missing_caption', Severity::Notice, '1.3.1', $table);
        }

        $complex = $this->isComplex($rows);

        foreach ($cells as $cell) {
            $scope = Element::enumAttr($cell, 'scope');

            if ($cell->hasAttribute('scope') && ! in_array($scope, self::VALID_SCOPES, true)) {
                $this->report('invalid_scope', Severity::Error, '1.3.1', $cell, ['value' => $cell->getAttribute('scope')]);
            } elseif ($complex && ! $hasHeadersAttr && Element::tag($cell) === 'th' && ! $cell->hasAttribute('scope') && trim($cell->getAttribute('id')) === '') {
                $this->report('th_missing_scope', Severity::Warning, '1.3.1', $cell, ['content' => Text::truncate($this->name($cell), self::MAX_CONTENT)]);
            }

            $this->checkHeaderReferences($cell);
        }
    }

    protected function checkHeaderReferences(DOMElement $cell): void
    {
        $byId = $this->document->elementsById();
        $missing = array_values(array_filter(Element::idrefs($cell, 'headers'), fn (string $id) => ! isset($byId[$id])));

        if ($missing !== []) {
            $this->report('dangling_headers_ref', Severity::Error, '1.3.1', $cell, ['id' => $missing[0]], ['ids' => $missing]);
        }
    }

    protected function hasCaption(DOMElement $table): bool
    {
        if ($this->own($table, './caption') !== [] || $this->authoredName($table) !== '') {
            return true;
        }

        $figure = Element::closest($table, 'figure');

        return $figure !== null && $this->query('./figcaption', $figure) !== [];
    }

    /**
     * Complex: header cells in the first row and in the first column of a later row, or more than one header row.
     *
     * @param  list<DOMElement>  $rows
     */
    protected function isComplex(array $rows): bool
    {
        $headerRows = 0;
        $firstRowHasHeader = false;
        $firstColumnHasHeader = false;

        foreach ($rows as $index => $row) {
            $cells = $this->query('./th|./td', $row);
            $headerCells = array_filter($cells, fn (DOMElement $cell) => Element::tag($cell) === 'th');

            if ($cells !== [] && count($headerCells) === count($cells)) {
                $headerRows++;
            }

            if ($index === 0) {
                $firstRowHasHeader = $headerCells !== [];
            } elseif ($cells !== [] && Element::tag($cells[0]) === 'th') {
                $firstColumnHasHeader = true;
            }
        }

        return $headerRows > 1 || ($firstRowHasHeader && $firstColumnHasHeader);
    }

    /**
     * Elements matching $expression inside $table whose nearest table ancestor is $table (not a nested table).
     *
     * @return list<DOMElement>
     */
    protected function own(DOMElement $table, string $expression): array
    {
        return array_values(array_filter(
            $this->query($expression, $table),
            fn (DOMElement $element) => Element::closest($element, 'table') === $table,
        ));
    }
}
