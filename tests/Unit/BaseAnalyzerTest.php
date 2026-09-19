<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Analyzers\BaseAnalyzer;
use ItsJustVita\LaravelBfsg\Contracts\Analyzer;
use ItsJustVita\LaravelBfsg\Dom\HtmlDocument;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\Support\AnalyzerTestCase;
use ItsJustVita\LaravelBfsg\Violation;

class BaseAnalyzerTest extends AnalyzerTestCase
{
    protected function analyzer(): Analyzer
    {
        return new class extends BaseAnalyzer
        {
            protected string $key = 'dummy';

            protected function inspect(): void
            {
                foreach ($this->query('//img[not(@alt)]') as $img) {
                    if ($this->isHidden($img)) {
                        continue;
                    }

                    $this->report('missing_alt', Severity::Error, '1.1.1', $img, ['src' => $img->getAttribute('src')], ['decorative' => false]);
                }

                if (! $this->isFragment() && $this->query('//h1') === []) {
                    $this->report('missing_h1', Severity::Notice, '1.3.1', related: ['2.4.6'], tags: ['best-practice']);
                }

                foreach ($this->query('//a[@href]') as $link) {
                    if ($this->name($link) === '') {
                        $this->report('missing_name', Severity::Error, '2.4.4', $link);
                    }
                }
            }
        };
    }

    public function test_reports_carry_element_selector_snippet_and_full_key(): void
    {
        $violations = $this->analyze('<html><body><main><img src="a.jpg" class="hero big x"><img src="b.jpg" alt="ok"></main></body></html>');

        $violation = $this->assertHasViolation($violations, 'dummy.missing_alt', element: 'img.hero.big', severity: Severity::Error);
        $this->assertSame('dummy', $violation->analyzer);
        $this->assertSame('/html[1]/body[1]/main[1]/img[1]', $violation->selector);
        $this->assertSame('<img src="a.jpg" class="hero big x">', $violation->snippet);
        $this->assertSame(['src' => 'a.jpg'], $violation->params);
        $this->assertSame(['decorative' => false], $violation->meta);
        $this->assertSame('1.1.1', $violation->rule);
        $this->assertViolationCount($violations, 'dummy.missing_alt', 1);
    }

    public function test_document_level_reports_have_no_element(): void
    {
        $violations = $this->analyze('<html><body><p>no heading</p></body></html>');

        $violation = $this->assertHasViolation($violations, 'dummy.missing_h1');
        $this->assertNull($violation->element);
        $this->assertNull($violation->selector);
        $this->assertSame(['2.4.6'], $violation->related);
        $this->assertSame(['best-practice'], $violation->tags);
    }

    public function test_fragment_skips_document_level_checks_and_hidden_helper_works(): void
    {
        $violations = $this->analyze('<div aria-hidden="true"><img src="x.png"></div><a href="/"><i class="icon"></i></a>');

        $this->assertNoViolation($violations, 'dummy.missing_h1');
        $this->assertNoViolation($violations, 'dummy.missing_alt');
        $this->assertHasViolation($violations, 'dummy.missing_name', element: 'a');
        $this->assertSame(['dummy.missing_name'], $this->keys($violations));
    }

    public function test_analyze_resets_state_between_runs(): void
    {
        $analyzer = $this->analyzer();

        $first = $analyzer->analyze(HtmlDocument::fromHtml('<html><body><h1>x</h1><img src="a"></body></html>'));
        $second = $analyzer->analyze(HtmlDocument::fromHtml('<html><body><h1>x</h1></body></html>'));

        $this->assertCount(1, $first);
        $this->assertSame([], $second);
        $this->assertSame('dummy', $analyzer->key());
        $this->assertContainsOnlyInstancesOf(Violation::class, $first);
    }

    public function test_describe_metadata(): void
    {
        $meta = $this->analyzer()->describe();

        $this->assertSame('dummy', $meta['key']);
        $this->assertIsString($meta['description']);
        $this->assertIsArray($meta['rules']);
    }
}
