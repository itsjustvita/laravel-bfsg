<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\AnalysisResult;
use ItsJustVita\LaravelBfsg\Analyzers\BaseAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\ImageAnalyzer;
use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Facades\Bfsg as BfsgFacade;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class BfsgRegistryTest extends TestCase
{
    public function test_default_registry_matches_the_constant_and_config_checks(): void
    {
        $bfsg = new Bfsg;

        $this->assertSame(array_keys(Bfsg::ANALYZERS), array_keys($bfsg->analyzers()));
        $this->assertInstanceOf(ImageAnalyzer::class, $bfsg->analyzers()['images']);

        $reduced = new Bfsg(app(), ['contrast' => false, 'media' => false]);
        $this->assertArrayNotHasKey('contrast', $reduced->analyzers());
        $this->assertArrayNotHasKey('media', $reduced->analyzers());
        $this->assertCount(14, $reduced->analyzers());
    }

    public function test_register_forget_only_except(): void
    {
        $custom = new class extends BaseAnalyzer
        {
            protected string $key = 'custom';

            protected function inspect(): void
            {
                $this->report('always', Severity::Notice, '1.3.1');
            }
        };

        $bfsg = (new Bfsg)->register('custom', $custom)->forget('contrast');
        $this->assertArrayHasKey('custom', $bfsg->analyzers());
        $this->assertArrayNotHasKey('contrast', $bfsg->analyzers());

        $only = $bfsg->only(['images', 'custom']);
        $this->assertSame(['images', 'custom'], array_keys($only->analyzers()));
        $this->assertArrayHasKey('headings', $bfsg->analyzers(), 'only() must clone');

        $except = $bfsg->except(['images']);
        $this->assertArrayNotHasKey('images', $except->analyzers());

        $result = $only->analyze('<html><body><h1>x</h1></body></html>');
        $this->assertSame(['images', 'custom'], $result->analyzersRun());
        $this->assertSame(['custom.always'], array_map(fn ($v) => $v->key, $result->all()));
    }

    public function test_analyze_returns_analysis_result_and_wraps_legacy_analyzers(): void
    {
        $result = (new Bfsg)->analyze('<html><body><img src="x.jpg"></body></html>', ['url' => 'https://example.com/']);

        $this->assertInstanceOf(AnalysisResult::class, $result);
        $this->assertSame('https://example.com/', $result->url());
        $this->assertNotEmpty($result->forAnalyzer('images'));
        $first = $result->forAnalyzer('images')[0];
        $this->assertSame('images', $first->analyzer);
        $this->assertSame(Severity::Error, $first->severity);
        $this->assertSame('1.1.1', $first->rule);
        $this->assertNotSame('', $first->message());
    }

    public function test_blank_html_and_ignored_selectors(): void
    {
        $this->assertCount(0, (new Bfsg)->analyze(''));

        config()->set('bfsg.ignored_selectors', ['#chat']);
        $result = (new Bfsg)->analyze('<html><body><h1>t</h1><div id="chat"><img src="x.jpg"></div></body></html>');
        $this->assertSame([], $result->forAnalyzer('images'), 'ignored selectors are removed before analysis');

        $kept = (new Bfsg)->analyze('<html><body><h1>t</h1><div id="chat"><img src="x.jpg"></div></body></html>', ['ignoredSelectors' => []]);
        $this->assertNotEmpty($kept->forAnalyzer('images'));
    }

    public function test_is_accessible_ignores_notices(): void
    {
        $bfsg = (new Bfsg)->only([])->register('n', new class extends BaseAnalyzer
        {
            protected string $key = 'n';

            protected function inspect(): void
            {
                $this->report('info', Severity::Notice, '1.3.1');
            }
        });

        $this->assertTrue($bfsg->isAccessible('<html><body></body></html>'));
    }

    public function test_container_bindings_and_facade(): void
    {
        $this->assertSame(app(Bfsg::class), app('bfsg'));
        $this->assertSame(app(Bfsg::class), app(Bfsg::class));
        $this->assertInstanceOf(AnalysisResult::class, BfsgFacade::analyze('<html><body><h1>x</h1></body></html>'));
        $this->assertArrayHasKey('bfsg', app('router')->getMiddleware());
    }
}
