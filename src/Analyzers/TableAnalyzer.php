<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

class TableAnalyzer
{
    /**
     * Analyze tables for accessibility issues
     */
    public function analyze(\DOMDocument $dom): array
    {
        $issues = [];
        $tables = $dom->getElementsByTagName('table');

        if ($tables->length === 0) {
            return [
                'issues' => [],
                'stats' => [
                    'total_issues' => 0,
                    'tables_found' => 0,
                ],
            ];
        }

        foreach ($tables as $table) {
            // Check for caption
            $captions = $table->getElementsByTagName('caption');
            if ($captions->length === 0) {
                $issues[] = [
                    'rule' => 'WCAG 1.3.1',
                    'message' => 'Table without caption found',
                    'element' => 'table',
                    'suggestion' => 'Add a <caption> element to describe the table content',
                    'type' => 'warning',
                ];
            }

            // Check for header cells with scope
            $headerCells = $table->getElementsByTagName('th');
            foreach ($headerCells as $th) {
                $scope = $th->getAttribute('scope');

                if (empty($scope)) {
                    $issues[] = [
                        'rule' => 'WCAG 1.3.1',
                        'message' => 'Table header cell without scope attribute',
                        'element' => 'th',
                        'suggestion' => 'Add scope="col" or scope="row" to <th> elements',
                        'type' => 'error',
                    ];
                } elseif (! in_array($scope, ['col', 'row', 'colgroup', 'rowgroup'])) {
                    $issues[] = [
                        'rule' => 'WCAG 1.3.1',
                        'message' => "Invalid scope attribute value: {$scope}",
                        'element' => 'th',
                        'suggestion' => 'Use scope="col", scope="row", scope="colgroup", or scope="rowgroup"',
                        'type' => 'error',
                    ];
                }
            }

            // Check for tables without any header cells
            if ($headerCells->length === 0) {
                $rows = $table->getElementsByTagName('tr');
                if ($rows->length > 0) {
                    $issues[] = [
                        'rule' => 'WCAG 1.3.1',
                        'message' => 'Data table without header cells (<th>)',
                        'element' => 'table',
                        'suggestion' => 'Use <th> elements to mark header cells in data tables',
                        'type' => 'error',
                    ];
                }
            }

            // Check for complex tables with headers attribute
            $cellsWithHeaders = [];
            $xpath = new \DOMXPath($dom);
            $cellsWithHeadersAttr = $xpath->query('.//td[@headers]', $table);

            foreach ($cellsWithHeadersAttr as $cell) {
                $headersAttr = $cell->getAttribute('headers');
                $headerIds = array_filter(explode(' ', $headersAttr));

                // Verify that referenced headers exist
                foreach ($headerIds as $headerId) {
                    $referencedHeader = $xpath->query("//*[@id='{$headerId}']", $table);
                    if ($referencedHeader->length === 0) {
                        $issues[] = [
                            'rule' => 'WCAG 1.3.1',
                            'message' => "Table cell references non-existent header id: {$headerId}",
                            'element' => 'td',
                            'suggestion' => 'Ensure all header IDs referenced in headers attribute exist',
                            'type' => 'error',
                        ];
                    }
                }
            }

            // Check for layout tables (should not have accessibility markup)
            $hasRole = $table->getAttribute('role');
            if ($hasRole === 'presentation' || $hasRole === 'none') {
                // Layout table - should not have th, caption, summary
                if ($headerCells->length > 0) {
                    $issues[] = [
                        'rule' => 'WCAG 1.3.1',
                        'message' => 'Layout table (role="presentation") should not contain <th> elements',
                        'element' => 'table',
                        'suggestion' => 'Remove <th> elements from layout tables or remove role="presentation"',
                        'type' => 'warning',
                    ];
                }

                if ($captions->length > 0) {
                    $issues[] = [
                        'rule' => 'WCAG 1.3.1',
                        'message' => 'Layout table (role="presentation") should not contain <caption>',
                        'element' => 'table',
                        'suggestion' => 'Remove <caption> from layout tables or remove role="presentation"',
                        'type' => 'warning',
                    ];
                }
            }

            // Check for nested tables (generally not recommended)
            $nestedTables = $xpath->query('.//table', $table);
            if ($nestedTables->length > 0) {
                $issues[] = [
                    'rule' => 'WCAG 1.3.1',
                    'message' => 'Nested tables found (not recommended)',
                    'element' => 'table',
                    'suggestion' => 'Avoid nesting tables; consider restructuring the data',
                    'type' => 'warning',
                ];
            }
        }

        return [
            'issues' => $issues,
            'stats' => [
                'total_issues' => count($issues),
                'tables_found' => $tables->length,
                'critical_issues' => count(array_filter($issues, fn ($i) => ($i['type'] ?? '') === 'error')),
            ],
        ];
    }
}
