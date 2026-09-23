<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use InvalidArgumentException;
use ItsJustVita\LaravelBfsg\AnalysisResult;
use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Reports\ReportGenerator;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Support\PackageVersion;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use ItsJustVita\LaravelBfsg\Violation;
use PHPUnit\Framework\Attributes\DataProvider;

class ReportGeneratorTest extends TestCase
{
    private function sampleResult(?string $locale = null): AnalysisResult
    {
        return new AnalysisResult([
            'images' => [new Violation('images', 'images.missing_alt', Severity::Error, '1.1.1', ['src' => 'hero.jpg'], 'img.hero', '/html[1]/body[1]/main[1]/img[1]', '<img src="hero.jpg" class="hero">')],
            'headings' => [new Violation('headings', 'headings.skipped_level', Severity::Warning, '1.3.1', ['from' => 1, 'to' => 3], 'h3', '/html[1]/body[1]/main[1]/h3[1]', '<h3>Deep | `tick`</h3>')],
            'links' => [new Violation('links', 'links.missing_noopener', Severity::Notice, null, ['href' => 'https://x.test'], 'a', '/html[1]/body[1]/a[1]', '<a href="https://x.test" target="_blank">x</a>', [], [], ['security'], true)],
        ], ['images', 'forms', 'headings', 'links'], 'https://example.com/', $locale);
    }

    public function test_json_follows_the_contract(): void
    {
        $json = json_decode((new ReportGenerator($this->sampleResult(), 'de'))->format('json')->render(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['url', 'package_version', 'locale', 'analyzed_at', 'analyzers', 'summary', 'violations'], array_keys($json));
        $this->assertSame('https://example.com/', $json['url']);
        $this->assertSame(PackageVersion::get(), $json['package_version']);
        $this->assertSame('de', $json['locale']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $json['analyzed_at']);
        $this->assertSame(['images', 'forms', 'headings', 'links'], $json['analyzers']);
        $this->assertSame(['total' => 3, 'errors' => 1, 'warnings' => 1, 'notices' => 1, 'score' => 93, 'grade' => 'B', 'accessible' => false], $json['summary']);
        $this->assertSame(['images', 'headings', 'links'], array_keys($json['violations']));

        $image = $json['violations']['images'][0];
        $this->assertSame(['id', 'analyzer', 'key', 'severity', 'rule', 'related', 'tags', 'message', 'suggestion', 'element', 'selector', 'snippet', 'params', 'meta', 'auto_fixable'], array_keys($image));
        $this->assertSame('images.missing_alt', $image['key']);
        $this->assertSame('Bild ohne Textalternative (hero.jpg)', $image['message']);
        $this->assertSame(['src' => 'hero.jpg'], $image['params']);
    }

    public function test_empty_maps_are_json_objects(): void
    {
        $clean = (new ReportGenerator(new AnalysisResult([], ['images'], 'https://example.com/')))->format('json')->render();
        $this->assertStringContainsString('"violations": {}', $clean);

        $json = (new ReportGenerator($this->sampleResult()))->toJson();
        $this->assertStringContainsString('"meta": {}', $json);
        $this->assertStringNotContainsString('"meta": []', $json);
        $this->assertStringNotContainsString('"params": []', $json);
    }

    public function test_score_grade_and_summary(): void
    {
        $report = new ReportGenerator($this->sampleResult());

        $this->assertSame(93, $report->score(), '100 - 5 - 2 - 0.5 = 92.5, rounded');
        $this->assertSame('B', $report->grade(), 'an error caps the grade at B');
        $this->assertFalse($report->summary()['accessible']);
        $this->assertSame('A+', (new ReportGenerator(new AnalysisResult([], [])))->grade());
    }

    public function test_locale_resolution(): void
    {
        $this->assertSame('fr', (new ReportGenerator($this->sampleResult('de'), 'fr'))->locale(), 'explicit locale wins');
        $this->assertSame('de', (new ReportGenerator($this->sampleResult('de')))->locale(), 'then the result locale');
        config()->set('bfsg.locale', 'de');
        $this->assertSame('de', (new ReportGenerator($this->sampleResult()))->locale(), 'then bfsg.locale');
        config()->set('bfsg.locale', null);
        $this->assertSame('en', (new ReportGenerator($this->sampleResult()))->locale(), 'then app.locale');
    }

    public function test_html_report_uses_the_locale_labels_and_version(): void
    {
        $html = (new ReportGenerator($this->sampleResult(), 'de'))->format('html')->render();

        $this->assertStringContainsString('<html lang="de">', $html);
        $this->assertStringContainsString('<h1>Barrierefreiheitsbericht</h1>', $html);
        $this->assertStringContainsString('Bild ohne Textalternative (hero.jpg)', $html);
        $this->assertStringContainsString('>Fehler</span>', $html);
        $this->assertStringContainsString('WCAG 1.1.1', $html);
        $this->assertStringContainsString('Kein WCAG-Kriterium', $html);
        $this->assertStringContainsString('&lt;img src=&quot;hero.jpg&quot; class=&quot;hero&quot;&gt;', $html);
        $this->assertStringContainsString('Erstellt mit laravel-bfsg '.PackageVersion::get(), $html);
        $this->assertStringContainsString('#b45309', $html, 'warning colour with 4.5:1 on white');
        $this->assertStringNotContainsString('1.5.0', $html);
        $this->assertStringContainsString('<html lang="en">', (new ReportGenerator($this->sampleResult(), 'en'))->format('html')->render());
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function ownAnalyzerCases(): array
    {
        return ['en with findings' => ['en', true], 'de with findings' => ['de', true], 'en clean' => ['en', false], 'de clean' => ['de', false]];
    }

    #[DataProvider('ownAnalyzerCases')]
    public function test_the_html_report_passes_the_packages_own_analyzers(string $locale, bool $findings): void
    {
        $result = $findings ? $this->sampleResult() : new AnalysisResult([], ['images'], 'https://example.com/a/very/long/path/that/keeps/going/and/going/until/the/title/would/be/too/long');
        $html = (new ReportGenerator($result, $locale))->format('html')->render();

        $own = (new Bfsg)->analyze($html, ['url' => 'report.html']);

        $this->assertCount(count(Bfsg::ANALYZERS), $own->analyzersRun(), 'every analyzer ran, so a clean result is not vacuous');
        $this->assertSame([], array_map(fn (Violation $violation) => $violation->key.' '.$violation->element.' '.$violation->snippet, $own->all()));
    }

    public function test_markdown_report(): void
    {
        $markdown = (new ReportGenerator($this->sampleResult(), 'en'))->format('markdown')->render();

        $this->assertStringStartsWith("# Accessibility Report\n", $markdown);
        $this->assertStringContainsString('| URL | https://example.com/ |', $markdown);
        $this->assertStringContainsString('| Compliance score | 93 of 100 |', $markdown);
        $this->assertStringContainsString('### images (1)', $markdown);
        $this->assertStringContainsString('- **Error** · WCAG 1.1.1 · `img.hero`', $markdown);
        $this->assertStringContainsString('Image without text alternative (hero.jpg)', $markdown);
        $this->assertStringContainsString('`<img src="hero.jpg" class="hero">`', $markdown);
        $this->assertStringContainsString('`` <h3>Deep | `tick`</h3> ``', $markdown);
        $this->assertStringContainsString('No WCAG criterion', $markdown);
        $this->assertStringNotContainsString("\n\n\n", $markdown);

        $clean = (new ReportGenerator(new AnalysisResult([], [], 'https://example.com/'), 'de'))->format('markdown')->render();
        $this->assertStringContainsString('Keine Barrierefreiheitsprobleme gefunden.', $clean);
    }

    public function test_markdown_escapes_page_controlled_text(): void
    {
        $result = new AnalysisResult([
            'images' => [new Violation('images', 'images.missing_alt', Severity::Error, '1.1.1', ['src' => "x.jpg\"><img src=x onerror=alert(1)>\n\n# injected"], 'img', '/html[1]/body[1]/img[1]', "<img\n\nsrc=\"a``b\">")],
        ], ['images'], 'https://example.com/<script>alert(1)</script>');

        $markdown = (new ReportGenerator($result, 'en'))->format('markdown')->render();

        $this->assertStringNotContainsString('<script>', $markdown);
        $this->assertStringNotContainsString('<img src=x', $markdown);
        $this->assertStringContainsString('| URL | https://example.com/&lt;script&gt;alert(1)&lt;/script&gt; |', $markdown);
        $this->assertStringContainsString('(x.jpg"&gt;&lt;img src=x onerror=alert(1)&gt; # injected)', $markdown, 'newlines collapsed, no heading injected');
        $this->assertStringContainsString('``` <img src="a``b"> ```', $markdown, 'the fence is longer than any backtick run in the snippet');
        $this->assertStringNotContainsString("\n# injected", $markdown);
    }

    public function test_pdf_report(): void
    {
        $this->assertStringStartsWith('%PDF', (new ReportGenerator($this->sampleResult()))->format('pdf')->render());
        $this->assertStringStartsWith('%PDF', (new ReportGenerator(new AnalysisResult([], [])))->format('pdf')->render());
    }

    public function test_pdf_without_dompdf_fails_with_the_install_hint_and_leaves_no_directory(): void
    {
        $report = new class(new AnalysisResult([], [])) extends ReportGenerator
        {
            protected function pdfAvailable(): bool
            {
                return false;
            }
        };
        $directory = sys_get_temp_dir().'/bfsg-no-pdf-'.uniqid();

        try {
            $report->format('pdf')->saveTo($directory.'/report.pdf');
            $this->fail('the PDF was written without dompdf');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('composer require barryvdh/laravel-dompdf', $e->getMessage());
        }

        $this->assertDirectoryDoesNotExist($directory, 'rendering happens before the directory is created');
    }

    public function test_default_paths_are_unique_per_report(): void
    {
        $first = (new ReportGenerator($this->sampleResult()))->format('html');
        $second = (new ReportGenerator($this->sampleResult()))->format('html');

        $this->assertNotSame($first->defaultPath(), $second->defaultPath(), 'two reports in the same second do not overwrite each other');
        $this->assertSame($first->defaultPath(), $first->defaultPath(), 'stable for one report');
    }

    public function test_locales_that_are_not_well_formed_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid locale [../../tmp/zz]');

        new ReportGenerator($this->sampleResult('../../tmp/zz'));
    }

    public function test_save_to_writes_the_rendered_report_and_default_paths_use_the_config(): void
    {
        $directory = sys_get_temp_dir().'/bfsg-reports-'.uniqid();
        config()->set('bfsg.reporting.output_path', $directory);
        $report = (new ReportGenerator($this->sampleResult()))->format('markdown');

        $this->assertMatchesRegularExpression('#^'.preg_quote($directory, '#').'/report_\d{4}-\d{2}-\d{2}_\d{6}_[0-9a-f]{6}\.md$#', $report->defaultPath());
        $this->assertSame('pdf', (clone $report)->format('pdf')->extension());

        $path = $report->saveTo($directory.'/nested/r.md');

        try {
            $this->assertSame($directory.'/nested/r.md', $path);
            $this->assertSame($report->render(), file_get_contents($path));
        } finally {
            unlink($path);
            rmdir($directory.'/nested');
            rmdir($directory);
        }
    }

    public function test_unknown_formats_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown report format [xml]');

        (new ReportGenerator($this->sampleResult()))->format('xml');
    }
}
