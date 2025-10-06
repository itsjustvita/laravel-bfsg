<?php

namespace ItsJustVita\LaravelBfsg\Tests\Feature;

use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class BfsgTest extends TestCase
{
    public function test_analyzes_html_for_multiple_violations(): void
    {
        $html = '<html><body>
            <img src="test.jpg">
            <h3>Skipped heading levels</h3>
            <form><h2>Form</h2><input type="text" name="email"></form>
            <a href="#">Click here</a>
        </body></html>';

        $bfsg = new Bfsg();
        $violations = $bfsg->analyze($html);

        $this->assertArrayHasKey('images', $violations);
        $this->assertArrayHasKey('headings', $violations);
        $this->assertArrayHasKey('forms', $violations);
        $this->assertArrayHasKey('links', $violations);
    }

    public function test_returns_empty_array_for_accessible_html(): void
    {
        $html = '<!DOCTYPE html><html lang="en"><body>
            <a href="#main" class="sr-only">Skip to main content</a>
            <h1>Main Heading</h1>
            <main id="main">
                <img src="test.jpg" alt="Test image">
                <form aria-label="Contact Form">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email">
                </form>
                <a href="/about">Learn more about us</a>
            </main>
        </body></html>';

        $bfsg = new Bfsg();
        $violations = $bfsg->analyze($html);

        $this->assertEmpty($violations);
    }

    public function test_correctly_identifies_accessible_content(): void
    {
        $accessibleHtml = '<!DOCTYPE html><html lang="en"><body>
            <a href="#main">Skip to main</a>
            <h1>Title</h1>
            <main id="main">
                <img src="test.jpg" alt="Description">
            </main>
        </body></html>';

        $inaccessibleHtml = '<!DOCTYPE html><html lang="en"><body><img src="test.jpg"><h3>Wrong heading level</h3></body></html>';

        $bfsg = new Bfsg();

        $this->assertTrue($bfsg->isAccessible($accessibleHtml));
        $this->assertFalse($bfsg->isAccessible($inaccessibleHtml));
    }

    public function test_can_get_violations_after_analysis(): void
    {
        $html = '<!DOCTYPE html><html><body><img src="test.jpg"></body></html>';

        $bfsg = new Bfsg();
        $bfsg->analyze($html);
        $violations = $bfsg->getViolations();

        $this->assertArrayHasKey('images', $violations);
        $this->assertNotEmpty($violations['images']);
    }
}