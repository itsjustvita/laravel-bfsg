<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Tests\Support\Phase2Progress;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use ReflectionClass;

/**
 * Spec §17 Phase 2 exit criteria, checked on the analyzer sources: no XPath string interpolation and no
 * textContent-based checks (names come from AccessibleName, text from the Dom helpers).
 */
class AnalyzerSourcePolicyTest extends TestCase
{
    /** @return array<string, string> file => source */
    private function sources(): array
    {
        $sources = [];

        foreach (glob(__DIR__.'/../../src/Analyzers/*Analyzer.php') as $file) {
            $sources[basename($file)] = (string) file_get_contents($file);
        }

        return $sources;
    }

    public function test_xpath_expressions_are_literals_constants_or_use_xpath_literal(): void
    {
        foreach ($this->sources() as $file => $source) {
            $this->assertDoesNotMatchRegularExpression('/(query|queryVisible)\(\s*"/', $source, "$file: double-quoted XPath (interpolation)");

            preg_match_all('/(?:query|queryVisible)\((.*)$/m', $source, $calls);

            foreach ($calls[1] as $arguments) {
                $concatenations = preg_match_all("/'\s*\.\s*\\$(?!this->document->xpathLiteral\()/", $arguments);
                $this->assertSame(0, $concatenations, "$file: XPath built by concatenation without xpathLiteral(): $arguments");
            }
        }
    }

    public function test_analyzers_do_not_read_text_content_directly(): void
    {
        foreach ($this->sources() as $file => $source) {
            $this->assertStringNotContainsString('->textContent', $source, "$file reads textContent; use name(), text() or ownText()");
            $this->assertSame(
                preg_match_all('/->nodeValue/', $source),
                substr_count($source, "getNamedItem('xml:lang')?->nodeValue"),
                "$file reads nodeValue",
            );
        }
    }

    public function test_every_registered_analyzer_declares_rules_and_a_description(): void
    {
        foreach (Bfsg::ANALYZERS as $key => $class) {
            $analyzer = (new ReflectionClass($class))->newInstance();
            $meta = $analyzer->describe();

            $this->assertSame($key, $meta['key']);
            $this->assertNotSame('', $meta['description'], $class);
            $this->assertNotSame([], $meta['rules'], $class);
        }
    }

    public function test_phase2_gate_covers_every_analyzer(): void
    {
        $this->assertSame(array_keys(Bfsg::ANALYZERS), Phase2Progress::DONE);
    }
}
