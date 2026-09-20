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

        $bfsg = new Bfsg;
        $violations = $bfsg->analyze($html)->toArray()['violations'];

        $this->assertArrayHasKey('images', $violations);
        $this->assertArrayHasKey('headings', $violations);
        $this->assertArrayHasKey('forms', $violations);
        $this->assertArrayHasKey('links', $violations);
    }

    public function test_returns_empty_array_for_blank_html(): void
    {
        $bfsg = new Bfsg;

        $this->assertCount(0, $bfsg->analyze(''));
        $this->assertCount(0, $bfsg->analyze("  \n  "));
    }

    public function test_recognises_german_skip_link_without_meta_charset(): void
    {
        // No <meta charset>: libxml would decode "Menü" as ISO-8859-1 and the
        // German skip-link pattern from v2.2.0 would never match.
        $html = '<html lang="de"><head><title>Startseite der Firma</title></head><body>'
            .'<a href="#nav">Zum Menü</a>'
            .'<nav id="nav"><a href="/kontakt">Kontakt aufnehmen</a></nav>'
            .'<main><h1>Willkommen bei der Firma</h1></main>'
            .'</body></html>';

        $violations = (new Bfsg)->analyze($html)->toArray()['violations'];

        $skipLinkFindings = collect($violations['keyboard'] ?? [])
            ->filter(fn ($issue) => str_contains($issue['message'], 'skip link'));

        $this->assertCount(0, $skipLinkFindings, 'German skip link must be recognised');
    }

    public function test_returns_empty_array_for_accessible_html(): void
    {
        $html = '<!DOCTYPE html><html lang="en"><head><title>Contact Us - Our Company</title></head><body>
            <header>
                <nav>
                    <a href="#main" class="sr-only">Skip to main content</a>
                </nav>
            </header>
            <main id="main">
                <h1>Main Heading</h1>
                <img src="test.jpg" alt="Test image">
                <form aria-label="Contact Form">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" autocomplete="email">
                </form>
                <a href="/about">Learn more about us</a>
                <div aria-live="polite"></div>
            </main>
            <footer>
                <p>Footer content</p>
            </footer>
        </body></html>';

        $bfsg = new Bfsg;
        $violations = $bfsg->analyze($html)->toArray()['violations'];

        $this->assertEmpty($violations);
    }

    public function test_correctly_identifies_accessible_content(): void
    {
        $accessibleHtml = '<!DOCTYPE html><html lang="en"><head><title>About Our Company</title></head><body>
            <header>
                <nav><a href="#main">Skip to main</a></nav>
            </header>
            <main id="main">
                <h1>Title</h1>
                <img src="test.jpg" alt="Description">
            </main>
            <footer><p>Footer</p></footer>
        </body></html>';

        $inaccessibleHtml = '<!DOCTYPE html><html lang="en"><body><img src="test.jpg"><h3>Wrong heading level</h3></body></html>';

        $bfsg = new Bfsg;

        $this->assertTrue($bfsg->isAccessible($accessibleHtml));
        $this->assertFalse($bfsg->isAccessible($inaccessibleHtml));
    }
}
