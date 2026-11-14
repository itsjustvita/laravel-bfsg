<?php

namespace ItsJustVita\LaravelBfsg\Tests\Feature;

use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Dom\HtmlDocument;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use ItsJustVita\LaravelBfsg\Violation;
use PHPUnit\Framework\Attributes\DataProvider;

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
            ->filter(fn ($issue) => $issue['key'] === 'keyboard.missing_skip_link');

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

    public function test_very_deep_document_analyzes_without_error(): void
    {
        $html = str_repeat('<div>', 5000).'<img src=a.png>'.str_repeat('</div>', 5000);
        $result = app(Bfsg::class)->analyze($html);

        // Nesting beyond 2048 levels is parsed without LIBXML_PARSEHUGE, as in 3.0: libxml stops at 256 levels and says so.
        $this->assertContains('The document is nested too deeply for the parser; content after that point was not analyzed.', HtmlDocument::fromHtml($html)->warnings());
        $this->assertSame([], $result->forAnalyzer('images'));
    }

    /** @return array<string, array{string}> */
    public static function hundredThousandLevels(): array
    {
        return [
            'plain' => [''],
            'ignored outer element' => [' data-chatbase'],
        ];
    }

    /** Once segfaulted on libxml 2.9: ext/libxml frees a detached subtree recursively (C1). */
    #[DataProvider('hundredThousandLevels')]
    public function test_a_hundred_thousand_levels_of_nesting_analyze_without_crashing(string $outerAttribute): void
    {
        $html = '<div'.$outerAttribute.'>'.str_repeat('<div>', 100000).'<img src=a.png>';
        $result = app(Bfsg::class)->analyze($html);

        $this->assertContains('images', $result->analyzersRun());
        $this->assertContains('The document is nested too deeply for the parser; content after that point was not analyzed.', HtmlDocument::fromHtml($html)->warnings());
    }

    /** C3 repro A: a Vite build whose `.text-white` lives in a stylesheet the facade never sees. */
    private const TAGS_PAGE = '<html lang="en"><head><title>Tags</title><link rel="stylesheet" href="/build/assets/app-4ed993c7.css"></head>'
        .'<body><main><h1>Tags</h1>'."\n".'<span class="rounded px-2 text-white" style="background-color:#1e40af">Billing</span></main></body></html>';

    /** @return list<Violation> the contrast findings of $html through Bfsg::only(['contrast'])->analyze() */
    private function contrastFindings(string $html): array
    {
        return (new Bfsg)->only(['contrast'])->analyze($html, ['url' => 'https://app.test/tags'])->all();
    }

    /**
     * @param  list<Violation>  $violations
     * @return list<Violation>
     */
    private function withKey(array $violations, string $key): array
    {
        return array_values(array_filter($violations, fn (Violation $violation) => $violation->key === $key));
    }

    // C3: an unseen stylesheet leaves the cascade incomplete: a failing estimate is a Warning tagged approximate.
    public function test_linked_stylesheet_turns_a_failing_estimate_into_a_warning(): void
    {
        $violations = $this->contrastFindings(self::TAGS_PAGE);
        $insufficient = $this->withKey($violations, 'contrast.insufficient');

        $this->assertCount(1, $insufficient);
        $this->assertSame(Severity::Warning, $insufficient[0]->severity);
        $this->assertSame(['approximate'], $insufficient[0]->tags);
        $this->assertSame(['ratio' => '2.41', 'required' => 4.5, 'foreground' => '#000000', 'background' => '#1e40af'], $insufficient[0]->params);

        $missing = $this->withKey($violations, 'contrast.stylesheets_missing');
        $this->assertCount(1, $missing);
        $this->assertSame(Severity::Notice, $missing[0]->severity);
        $this->assertSame('1.4.3', $missing[0]->rule);
        $this->assertSame(['count' => 1], $missing[0]->params);
        $this->assertNull($missing[0]->selector, 'a document-level finding');
    }

    // Review Focus 3: one document notice, no per-element notice, passing estimates stay silent.
    public function test_linked_stylesheet_gives_one_document_notice_not_one_per_element(): void
    {
        $items = '';

        for ($i = 1; $i <= 50; $i++) {
            $items .= $i % 2 === 0
                ? '<p class="text-sm text-gray-500">Item '.$i.'</p>'
                : '<span class="rounded px-2 text-white" style="background-color:#1e40af">Tag '.$i.'</span>';
        }

        $violations = $this->contrastFindings(str_replace('<span class="rounded px-2 text-white" style="background-color:#1e40af">Billing</span>', $items, self::TAGS_PAGE));

        $this->assertCount(1, $this->withKey($violations, 'contrast.stylesheets_missing'));
        $this->assertSame([], $this->withKey($violations, 'contrast.unverifiable'));
        $this->assertSame([], array_values(array_filter($violations, fn (Violation $violation) => $violation->severity === Severity::Error)));
        $this->assertSame(25, $this->withKey($violations, 'contrast.insufficient')[0]->meta['occurrences']);
    }

    // Review Focus 2: a page whose CSS is all in <style> keeps its definite verdicts and gets no notice.
    public function test_page_without_linked_stylesheets_has_no_stylesheets_missing_notice(): void
    {
        $html = '<html lang="en"><head><title>Tags</title><link rel="preload" as="style" href="/build/assets/app.css"><link rel="modulepreload" href="/build/assets/app.js">'
            .'<link rel="icon" href="/favicon.ico"><style>.muted{color:#999}</style></head><body><main><h1>Tags</h1><p class="muted">Muted</p></main></body></html>';
        $violations = $this->contrastFindings($html);

        $this->assertSame([], $this->withKey($violations, 'contrast.stylesheets_missing'));
        $insufficient = $this->withKey($violations, 'contrast.insufficient');
        $this->assertCount(1, $insufficient);
        $this->assertSame(Severity::Error, $insufficient[0]->severity);
        $this->assertSame([], $insufficient[0]->tags);
    }

    // C3: only stylesheets a browser would apply to the screen count.
    public function test_alternate_disabled_and_print_links_do_not_count(): void
    {
        $links = '<link rel="alternate stylesheet" href="/alt.css"><link rel="stylesheet" href="/off.css" disabled>'
            .'<link rel="stylesheet" media="print" href="/print.css"><link rel="stylesheet" href=""><link rel="stylesheet">'
            .'<template><link rel="stylesheet" href="/t.css"></template><noscript><link rel="stylesheet" href="/n.css"></noscript>';
        $violations = $this->contrastFindings('<html lang="en"><head><title>Links</title>'.$links.'</head><body><main><h1>Links</h1><p style="color:#999">Muted</p></main></body></html>');

        $this->assertSame([], $this->withKey($violations, 'contrast.stylesheets_missing'));
        $this->assertSame(Severity::Error, $this->withKey($violations, 'contrast.insufficient')[0]->severity);

        $counted = '<link rel=" Stylesheet " href="/a.css"><link rel="stylesheet" media="screen, print" href="/b.css"><link rel="preload stylesheet" media="(min-width: 40em)" href="/c.css">';
        $missing = $this->withKey($this->contrastFindings('<html lang="en"><head><title>Links</title>'.$counted.$links.'</head><body><main><h1>Links</h1><p style="color:#999">Muted</p></main></body></html>'), 'contrast.stylesheets_missing');
        $this->assertSame(['count' => 3], $missing[0]->params);
    }

    // C3 repro C: an @import in a <style> element is a stylesheet the parser never saw.
    public function test_import_in_a_style_element_counts_as_missing(): void
    {
        $html = '<html lang="en"><head><title>Import</title><style>@import url("https://example.test/theme.css"); p{color:#fff}</style></head>'
            .'<body><main><h1>Import</h1><div class="surface-dark"><p>Dark surface text</p></div></main></body></html>';
        $violations = $this->contrastFindings($html);

        $this->assertSame([], array_values(array_filter($violations, fn (Violation $violation) => $violation->severity === Severity::Error)));
        $insufficient = $this->withKey($violations, 'contrast.insufficient');
        $this->assertSame(Severity::Warning, $insufficient[0]->severity);
        $this->assertSame('#ffffff', $insufficient[0]->params['foreground']);
        $this->assertSame(['count' => 1], $this->withKey($violations, 'contrast.stylesheets_missing')[0]->params);
    }
}
