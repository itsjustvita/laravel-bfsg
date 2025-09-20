<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMDocument;
use DOMXPath;

class HeadingAnalyzer
{
    protected array $violations = [];

    public function analyze(DOMDocument $dom): array
    {
        $this->violations = [];
        $xpath = new DOMXPath($dom);

        // Check for proper heading hierarchy
        $this->checkHeadingHierarchy($xpath);

        // Check for missing h1
        $this->checkForMainHeading($xpath);

        // Check for empty headings
        $this->checkEmptyHeadings($xpath);

        // Check for multiple h1 tags
        $this->checkMultipleH1Tags($xpath);

        return ['issues' => $this->violations];
    }

    protected function checkHeadingHierarchy(DOMXPath $xpath): void
    {
        $headings = $xpath->query('//h1|//h2|//h3|//h4|//h5|//h6');
        $previousLevel = 0;
        $headingLevels = [];

        foreach ($headings as $heading) {
            $currentLevel = (int) substr($heading->nodeName, 1);
            $headingLevels[] = $currentLevel;

            // Check if heading level skips (e.g., h1 -> h3)
            if ($previousLevel > 0 && $currentLevel > $previousLevel + 1) {
                $this->violations[] = [
                    'type' => 'error',
                    'rule' => 'WCAG 1.3.1',
                    'element' => $heading->nodeName,
                    'message' => "Heading hierarchy broken: {$heading->nodeName} follows h{$previousLevel}",
                    'content' => substr(trim($heading->textContent), 0, 50),
                    'suggestion' => 'Use h'.($previousLevel + 1)." instead of {$heading->nodeName}",
                    'auto_fixable' => false,
                ];
            }

            $previousLevel = $currentLevel;
        }
    }

    protected function checkForMainHeading(DOMXPath $xpath): void
    {
        $h1Tags = $xpath->query('//h1');

        if ($h1Tags->length === 0) {
            $this->violations[] = [
                'type' => 'warning',
                'rule' => 'WCAG 1.3.1, 2.4.6',
                'element' => 'h1',
                'message' => 'No h1 heading found on the page',
                'suggestion' => 'Add a main h1 heading to describe the page content',
                'auto_fixable' => false,
            ];
        }
    }

    protected function checkEmptyHeadings(DOMXPath $xpath): void
    {
        $headings = $xpath->query('//h1|//h2|//h3|//h4|//h5|//h6');

        foreach ($headings as $heading) {
            $textContent = trim($heading->textContent);

            if (empty($textContent)) {
                $this->violations[] = [
                    'type' => 'error',
                    'rule' => 'WCAG 1.3.1, 2.4.6',
                    'element' => $heading->nodeName,
                    'message' => "Empty {$heading->nodeName} heading found",
                    'suggestion' => 'Remove empty heading or add descriptive text',
                    'auto_fixable' => false,
                ];
            } elseif (strlen($textContent) < 3) {
                // Check for very short headings that might not be descriptive
                $this->violations[] = [
                    'type' => 'warning',
                    'rule' => 'WCAG 2.4.6',
                    'element' => $heading->nodeName,
                    'message' => "Very short heading text: '{$textContent}'",
                    'suggestion' => 'Use more descriptive heading text',
                    'auto_fixable' => false,
                ];
            }
        }
    }

    protected function checkMultipleH1Tags(DOMXPath $xpath): void
    {
        $h1Tags = $xpath->query('//h1');

        if ($h1Tags->length > 1) {
            $this->violations[] = [
                'type' => 'warning',
                'rule' => 'WCAG 1.3.1',
                'element' => 'h1',
                'message' => "Multiple h1 headings found ({$h1Tags->length} total)",
                'suggestion' => 'Use only one h1 per page for the main heading',
                'auto_fixable' => false,
            ];

            // List all h1 contents for reference
            foreach ($h1Tags as $index => $h1) {
                $content = substr(trim($h1->textContent), 0, 50);
                if (! empty($content)) {
                    $this->violations[] = [
                        'type' => 'notice',
                        'rule' => 'WCAG 1.3.1',
                        'element' => 'h1',
                        'message' => 'h1 #'.($index + 1).": '{$content}'",
                        'suggestion' => 'Consider using h2 or restructuring the content',
                        'auto_fixable' => false,
                    ];
                }
            }
        }
    }
}
