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
        $this->assertViolationCount($violations, 'images.missing_alt', 1);
    }

    public function test_reports_every_image_without_alt(): void
    {
        $violations = $this->analyze('<html><body><img src="a.jpg"><img src="b.jpg"></body></html>');

        $this->assertViolationCount($violations, 'images.missing_alt', 2);
    }

    public function test_warns_about_empty_alt_that_is_not_marked_decorative(): void
    {
        $violations = $this->analyze('<html><body><img src="deco.jpg" alt=""></body></html>');

        $this->assertHasViolation($violations, 'images.possibly_decorative', element: 'img', severity: Severity::Warning);
        $this->assertNoViolation($violations, 'images.missing_alt');
    }

    public function test_accepts_decorative_images_with_presentation_role_or_aria_hidden(): void
    {
        $violations = $this->analyze('<html><body><img src="a.jpg" alt="" role="presentation"><img src="b.jpg" alt="" aria-hidden="true"></body></html>');

        $this->assertSame([], $violations);
    }

    public function test_accepts_images_with_alt(): void
    {
        $this->assertSame([], $this->analyze('<html><body><img src="a.jpg" alt="A photo of the team"></body></html>'));
    }
}
