<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\AnalysisResult;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use ItsJustVita\LaravelBfsg\Violation;

class AnalysisResultTest extends TestCase
{
    private function v(string $analyzer, string $key, Severity $severity): Violation
    {
        return new Violation($analyzer, $analyzer.'.'.$key, $severity, '1.1.1', element: 'img', selector: '/html[1]/body[1]/img[1]');
    }

    private function sampleResult(): AnalysisResult
    {
        return new AnalysisResult([
            'images' => [$this->v('images', 'missing_alt', Severity::Error), $this->v('images', 'possibly_decorative', Severity::Warning)],
            'headings' => [$this->v('headings', 'missing_h1', Severity::Notice)],
        ], ['images', 'forms', 'headings'], 'https://example.com/', 'de');
    }

    public function test_exposes_violations_grouped_and_flat(): void
    {
        $result = $this->sampleResult();

        $this->assertSame(['images', 'headings'], array_keys($result->byAnalyzer()));
        $this->assertCount(3, $result->all());
        $this->assertCount(2, $result->forAnalyzer('images'));
        $this->assertSame([], $result->forAnalyzer('forms'));
        $this->assertCount(3, $result);
    }

    public function test_counts_by_severity_and_accessibility(): void
    {
        $result = $this->sampleResult();

        $this->assertSame(['error' => 1, 'warning' => 1, 'notice' => 1], $result->countBySeverity());
        $this->assertTrue($result->hasErrors());
        $this->assertFalse($result->isAccessible());

        $onlyNotices = new AnalysisResult(['headings' => [$this->v('headings', 'missing_h1', Severity::Notice)]], ['headings']);
        $this->assertFalse($onlyNotices->hasErrors());
        $this->assertTrue($onlyNotices->isAccessible(), 'notices must not make a page inaccessible');

        $warningOnly = new AnalysisResult(['images' => [$this->v('images', 'x', Severity::Warning)]], ['images']);
        $this->assertFalse($warningOnly->isAccessible(), 'warnings count against accessibility');
    }

    public function test_empty_result(): void
    {
        $result = new AnalysisResult([], ['images']);

        $this->assertCount(0, $result);
        $this->assertTrue($result->isAccessible());
        $this->assertSame(['error' => 0, 'warning' => 0, 'notice' => 0], $result->countBySeverity());
    }

    public function test_metadata_and_with_url(): void
    {
        $result = $this->sampleResult();

        $this->assertSame(['images', 'forms', 'headings'], $result->analyzersRun());
        $this->assertSame('https://example.com/', $result->url());
        $this->assertSame('de', $result->locale());

        $moved = $result->withUrl('https://example.org/');
        $this->assertSame('https://example.org/', $moved->url());
        $this->assertSame('https://example.com/', $result->url(), 'withUrl must not mutate');
    }

    public function test_to_array_shape(): void
    {
        $array = $this->sampleResult()->toArray();

        $this->assertSame(['analyzers', 'summary', 'violations'], array_keys($array));
        $this->assertSame(['images', 'forms', 'headings'], $array['analyzers']);
        $this->assertSame(['total' => 3, 'errors' => 1, 'warnings' => 1, 'notices' => 1, 'accessible' => false], $array['summary']);
        $this->assertSame('images.missing_alt', $array['violations']['images'][0]['key']);
        $this->assertSame('error', $array['violations']['images'][0]['severity']);
        $this->assertSame($array, json_decode(json_encode($this->sampleResult()), true));
    }

    public function test_analyzers_that_ran_without_findings_are_listed_but_have_no_group(): void
    {
        $result = $this->sampleResult();

        $this->assertSame(['images', 'forms', 'headings'], $result->analyzersRun());
        $this->assertArrayNotHasKey('forms', $result->byAnalyzer());
        $this->assertArrayNotHasKey('forms', $result->toArray()['violations']);
        $this->assertSame([], $result->forAnalyzer('forms'));
        $this->assertSame(['images', 'forms', 'headings'], $result->toArray()['analyzers']);
    }

    public function test_json_keeps_violations_an_object_also_when_empty(): void
    {
        $this->assertSame('{}', json_encode((new AnalysisResult([], ['images']))->jsonSerialize()['violations']));
        $this->assertStringContainsString('"violations":{}', (string) json_encode(new AnalysisResult([], ['images'])));
        $this->assertSame([], (new AnalysisResult([], ['images']))->toArray()['violations'], 'toArray() stays a PHP array');
        $this->assertStringContainsString('"violations":{"images":[{', (string) json_encode($this->sampleResult()));
    }
}
