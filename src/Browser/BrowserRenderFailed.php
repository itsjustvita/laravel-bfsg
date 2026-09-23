<?php

namespace ItsJustVita\LaravelBfsg\Browser;

use RuntimeException;

/** Browser rendering could not produce HTML (Playwright missing, invalid engine, navigation or process failure). */
final class BrowserRenderFailed extends RuntimeException
{
    public static function playwrightMissing(string $directory): self
    {
        return new self("Playwright is not installed in {$directory}. Run: npm install playwright && npx playwright install");
    }

    public static function invalidEngine(string $engine): self
    {
        return new self("Unknown browser engine [{$engine}]. Use one of: ".implode(', ', BrowserAnalyzer::ENGINES).'.');
    }

    public static function process(string $detail): self
    {
        return new self('Browser rendering failed: '.($detail === '' ? 'no output' : $detail));
    }
}
