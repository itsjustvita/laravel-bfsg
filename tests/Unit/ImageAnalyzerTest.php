<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Analyzers\ImageAnalyzer;
use ItsJustVita\LaravelBfsg\Contracts\Analyzer;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\Support\AnalyzerTestCase;

class ImageAnalyzerTest extends AnalyzerTestCase
{
    protected function analyzer(): Analyzer
    {
        return new ImageAnalyzer;
    }

    public function test_detects_images_without_alt(): void
    {
        $violations = $this->analyze('<html><body><img src="test.jpg"><img src="ok.jpg" alt="Described"></body></html>');

        $violation = $this->assertHasViolation($violations, 'images.missing_alt', element: 'img', severity: Severity::Error);
        $this->assertSame('1.1.1', $violation->rule);
        $this->assertSame(['src' => 'test.jpg'], $violation->params);
        $this->assertSame('/html[1]/body[1]/img[1]', $violation->selector);
        $this->assertFalse($violation->autoFixable);
        $this->assertViolationCount($violations, 'images.missing_alt', 1);
    }

    public function test_reports_every_image_without_alt(): void
    {
        $this->assertViolationCount($this->analyze('<html><body><img src="a.jpg"><img src="b.jpg"></body></html>'), 'images.missing_alt', 2);
    }

    public function test_aria_label_labelledby_or_title_satisfy_missing_alt(): void
    {
        $html = '<html><body><span id="cap">Team</span>'
            .'<img src="a.jpg" aria-label="Team photo"><img src="b.jpg" aria-labelledby="cap"><img src="c.jpg" title="Team">'
            .'</body></html>';

        $this->assertSame([], $this->analyze($html));
    }

    public function test_presentational_and_hidden_images_are_skipped(): void
    {
        $html = '<html><body><img src="a.jpg" role="presentation"><img src="b.jpg" role="NONE">'
            .'<img src="c.jpg" aria-hidden="TRUE"><div hidden><img src="d.jpg"></div>'
            .'<img src="e.jpg" alt="" role="none"></body></html>';

        $this->assertSame([], $this->analyze($html));
    }

    public function test_input_type_image_without_alt(): void
    {
        $violations = $this->analyze('<html><body><form><input TYPE="Image" src="go.png"><input type="image" src="ok.png" alt="Search"></form></body></html>');

        $this->assertHasViolation($violations, 'images.missing_alt', element: 'input', severity: Severity::Error);
        $this->assertViolationCount($violations, 'images.missing_alt', 1);
    }

    public function test_area_without_alt(): void
    {
        $violations = $this->analyze('<html><body><map name="m"><area href="/a" shape="rect" coords="0,0,1,1"><area href="/b" alt="Berlin"><area shape="rect"></map></body></html>');

        $violation = $this->assertHasViolation($violations, 'images.area_missing_alt', element: 'area', severity: Severity::Error);
        $this->assertSame(['href' => '/a'], $violation->params);
        $this->assertViolationCount($violations, 'images.area_missing_alt', 1);
    }

    public function test_svg_without_name(): void
    {
        $html = '<html><body>'
            .'<svg id="bare" viewBox="0 0 1 1"><path d="M0 0"/></svg>'
            .'<svg role="img" aria-label="Logo"><path d="M0 0"/></svg>'
            .'<svg><title>Chart</title><path d="M0 0"/></svg>'
            .'<svg aria-hidden="true"><path d="M0 0"/></svg>'
            .'<button><svg><path d="M0 0"/></svg> Save</button>'
            .'</body></html>';

        $violations = $this->analyze($html);

        $this->assertHasViolation($violations, 'images.svg_missing_name', element: 'svg#bare', severity: Severity::Error);
        $this->assertViolationCount($violations, 'images.svg_missing_name', 1);
    }

    public function test_empty_alt_is_a_notice_unless_context_names_the_image(): void
    {
        $html = '<html><body><img id="lone" src="deco.jpg" alt="">'
            .'<a href="/team"><img src="a.jpg" alt=""> Our team</a>'
            .'<figure><img src="b.jpg" alt=""><figcaption>Chart of sales</figcaption></figure>'
            .'</body></html>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'images.possibly_decorative', element: 'img#lone', severity: Severity::Notice);
        $this->assertSame(['src' => 'deco.jpg'], $violation->params);
        $this->assertViolationCount($violations, 'images.possibly_decorative', 1);
        $this->assertNoViolation($violations, 'images.missing_alt');
    }

    public function test_suspicious_alt_texts(): void
    {
        $html = '<html><body><img src="1.jpg" alt="IMG_2041.JPG"><img src="2.jpg" alt="Bild"><img src="3.jpg" alt="   ">'
            .'<img src="4.jpg" alt="Photo"><img src="5.jpg" alt="Mitarbeiterin am Empfang"></body></html>';

        $violations = $this->analyze($html);

        $this->assertViolationCount($violations, 'images.suspicious_alt', 4);
        $violation = $this->assertHasViolation($violations, 'images.suspicious_alt', severity: Severity::Notice);
        $this->assertSame(['alt' => 'IMG_2041.JPG', 'src' => '1.jpg'], $violation->params);
    }

    public function test_accepts_images_with_alt(): void
    {
        $this->assertSame([], $this->analyze('<html><body><img src="a.jpg" alt="A photo of the team"></body></html>'));
    }
}
