<?php

namespace ItsJustVita\LaravelBfsg\Tests\Feature;

use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use ItsJustVita\LaravelBfsg\Violation;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The accessible pages used by CommandsTest, MiddlewareTest, BfsgTest and IntegrationTest must stay free of
 * findings of any severity: until Phase 3, bfsg:check fails on any finding, notices included. A new notice
 * that fires here is a false positive to fix in the analyzer — never edit these pages to make it pass.
 */
class CleanPageBaselineTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function pages(): array
    {
        return [
            'commands' => ['<!DOCTYPE html><html lang="en"><head><title>Test Page - Company</title></head><body>'
                .'<header><nav><a href="#main">Skip to content</a></nav></header>'
                .'<main id="main"><h1>Welcome</h1>'
                .'<img src="photo.jpg" alt="A descriptive alt text">'
                .'<form aria-label="Contact"><label for="email">Email</label>'
                .'<input type="email" id="email" name="email" autocomplete="email"></form>'
                .'<a href="/about">Learn more about our company</a>'
                .'<div aria-live="polite"></div>'
                .'</main><footer><p>Footer content</p></footer>'
                .'</body></html>'],
            'middleware' => ['<!DOCTYPE html><html lang="en"><head><title>Accessible Test Page</title></head><body>'
                .'<a href="#main" class="skip-link">Skip to main content</a>'
                .'<header><h1>Page Title</h1></header>'
                .'<nav><a href="/about">About us</a></nav>'
                .'<main id="main">'
                .'<p>Some accessible content.</p>'
                .'<img src="photo.jpg" alt="A descriptive alt text">'
                .'</main>'
                .'<footer><p>Footer content</p></footer>'
                .'</body></html>'],
            'facade' => ['<!DOCTYPE html><html lang="en"><head><title>Contact Us - Our Company</title></head><body>
                <header><nav><a href="#main" class="sr-only">Skip to main content</a></nav></header>
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
                <footer><p>Footer content</p></footer>
            </body></html>'],
            'integration' => ['<!DOCTYPE html>
<html lang="en">
<head><title>Accessible Page - Our Company</title></head>
<body>
    <header>
        <nav aria-label="Main navigation">
            <a href="#main">Skip to main content</a>
            <a href="/about">About our company</a>
            <a href="/contact">Contact us</a>
        </nav>
    </header>
    <main id="main">
        <h1>Welcome to Our Company</h1>
        <p>This is a fully accessible page.</p>
        <img src="photo.jpg" alt="A team meeting in our office">
        <h2>Contact Us</h2>
        <form aria-label="Contact Form">
            <label for="name">Full Name</label>
            <input type="text" id="name" name="name" autocomplete="name">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" autocomplete="email">
            <button type="submit">Send Message</button>
        </form>
        <h2>Our Data</h2>
        <table>
            <caption>Quarterly Results</caption>
            <thead><tr><th scope="col">Quarter</th><th scope="col">Revenue</th></tr></thead>
            <tbody><tr><td>Q1</td><td>$100k</td></tr></tbody>
        </table>
        <div aria-live="polite"></div>
    </main>
    <footer><p>Footer content</p></footer>
</body>
</html>'],
        ];
    }

    #[DataProvider('pages')]
    public function test_accessible_page_has_no_findings(string $html): void
    {
        $keys = array_map(fn (Violation $violation) => $violation->key, (new Bfsg)->analyze($html)->all());

        $this->assertSame([], $keys);
    }
}
