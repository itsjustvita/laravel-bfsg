<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMElement;
use ItsJustVita\LaravelBfsg\Dom\Text;
use ItsJustVita\LaravelBfsg\Severity;

class TableAnalyzer extends BaseAnalyzer
{
    private const MAX_CONTENT = 30;

    private const VALID_SCOPES = ['col', 'row', 'colgroup', 'rowgroup'];

    private const LAYOUT_ROLES = ['presentation', 'none'];

    protected string $key = 'tables';

    protected string $description = 'Data table structure';

    protected array $rules = ['1.3.1'];

    protected function inspect(): void
    {
        foreach ($this->query('//table') as $table) {
            $this->checkTable($table);
        }

        $this->checkNestedTables();
    }

    protected function checkTable(DOMElement $table): void
    {
        $captions = $this->query('.//caption', $table);
        $headerCells = $this->query('.//th', $table);

        if ($captions === []) {
            $this->report('missing_caption', Severity::Warning, '1.3.1', $table);
        }

        $this->checkHeaderCells($headerCells);

        if ($headerCells === [] && $this->query('.//tr', $table) !== []) {
            $this->report('missing_headers', Severity::Error, '1.3.1', $table);
        }

        $this->checkHeaderReferences($table);

        // Layout tables must not carry table semantics.
        if (in_array($table->getAttribute('role'), self::LAYOUT_ROLES, true)) {
            if ($headerCells !== []) {
                $this->report('layout_table_with_semantics', Severity::Warning, '1.3.1', $table, ['found' => 'th']);
            }

            if ($captions !== []) {
                $this->report('layout_table_with_semantics', Severity::Warning, '1.3.1', $table, ['found' => 'caption']);
            }
        }
    }

    /** @param  list<DOMElement>  $headerCells */
    protected function checkHeaderCells(array $headerCells): void
    {
        foreach ($headerCells as $th) {
            $scope = $th->getAttribute('scope');

            if (trim($scope) === '') {
                $this->report('th_missing_scope', Severity::Error, '1.3.1', $th, [
                    'content' => Text::truncate($this->text($th), self::MAX_CONTENT),
                ]);
            } elseif (! in_array($scope, self::VALID_SCOPES, true)) {
                $this->report('invalid_scope', Severity::Error, '1.3.1', $th, ['value' => $scope]);
            }
        }
    }

    /** Every id in a headers attribute must exist in the document. */
    protected function checkHeaderReferences(DOMElement $table): void
    {
        $byId = $this->document->elementsById();

        foreach ($this->query('.//td[@headers]', $table) as $cell) {
            $headerIds = preg_split('/\s+/', $cell->getAttribute('headers'), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            foreach ($headerIds as $headerId) {
                if (! isset($byId[$headerId])) {
                    $this->report('dangling_headers_ref', Severity::Error, '1.3.1', $cell, ['id' => $headerId]);
                }
            }
        }
    }

    /** Report every table that sits inside another table. */
    protected function checkNestedTables(): void
    {
        foreach ($this->query('//table[ancestor::table]') as $nested) {
            $this->report('nested_table', Severity::Warning, '1.3.1', $nested);
        }
    }
}
