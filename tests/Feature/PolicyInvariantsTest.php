<?php

namespace ItsJustVita\LaravelBfsg\Tests\Feature;

use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Tests\Support\Phase2Progress;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use ItsJustVita\LaravelBfsg\Violation;

/**
 * Global analyzer policy (spec §13) exercised across all analyzers at once: hidden subtrees, fragments,
 * case-insensitive enumerated attributes. Gated by Phase2Progress::DONE until the closing task.
 */
class PolicyInvariantsTest extends TestCase
{
    /** Defects of every analyzer; the markup is placed inside different hiding containers. */
    private const DEFECTS = '<img src="a.jpg"><h3></h3><h6>Deep</h6><a href="/x">here</a><a href="/y"></a>'
        .'<input type="text" name="email"><button></button>'
        .'<table><tr><td>1</td></tr><tr><td>2</td></tr></table><video src="v.mp4"></video><audio src="a.mp3"></audio><iframe src="/f"></iframe>'
        .'<div role="bogus" aria-labelledby="none">x</div><div onclick="go()">c</div><div role="button">r</div><section><p>s</p></section><ul></ul>'
        .'<p style="color:#ccc">low contrast</p><div class="toast">Saved</div><div aria-live="rude">x</div><p lang="xx_YY">x</p>'
        .'<button style="outline:none">b</button><input name="email" autocomplete="e-mail" aria-label="E-Mail">'
        .'<form novalidate><input type="email" aria-label="Mail"></form><span tabindex="3">t</span>';

    /** Defects without focusable elements, for aria-hidden containers (focusables there are aria.hidden_focusable). */
    private const INERT_DEFECTS = '<img src="b.jpg"><h4></h4><table><tr><td>1</td></tr><tr><td>2</td></tr></table>'
        .'<p style="color:#ccc">low contrast</p><div class="toast">Saved</div><section><p>s</p></section><ul></ul><p lang="xx_YY">x</p>';

    private function shell(string $body): string
    {
        return '<!DOCTYPE html><html lang="en"><head><title>Policy invariants | Test suite</title></head><body><main><h1>Policy</h1>'.$body.'</main></body></html>';
    }

    /** @return list<string> keys of finished analyzers, sorted */
    private function keys(string $html): array
    {
        $keys = array_map(fn (Violation $violation) => $violation->key, Phase2Progress::violations((new Bfsg)->analyze($html)->all()));
        sort($keys);

        return $keys;
    }

    public function test_hidden_subtrees_produce_no_findings(): void
    {
        $html = $this->shell(
            '<div hidden>'.self::DEFECTS.'</div>'
            .'<div style="display: none">'.self::DEFECTS.'</div>'
            .'<div style="color: red; visibility:hidden">'.self::DEFECTS.'</div>'
            .'<template>'.self::DEFECTS.'</template>'
            .'<noscript>'.self::INERT_DEFECTS.'</noscript>'
            .'<div aria-hidden="true">'.self::INERT_DEFECTS.'</div>'
        );

        $this->assertSame([], $this->keys($html));
    }

    public function test_fragments_skip_document_level_checks(): void
    {
        $fragment = '<section aria-label="News"><h2>News</h2><p>We moved to a new office.</p>'
            .'<a href="/about">About our company</a><img src="team.jpg" alt="Our team in the new office"></section>';

        $this->assertSame([], $this->keys($fragment));
        $this->assertSame([], $this->keys('<p>Just a sentence.</p>'));
    }

    public function test_enumerated_attribute_values_and_tag_names_are_case_insensitive(): void
    {
        $lower = $this->shell(
            '<input type="email" name="email"><input type="image" src="go.png"><img src="d.jpg" alt="" role="none">'
            .'<div role="slider" tabindex="0">0</div><button aria-hidden="true">X</button>'
            .'<a href="https://ext.example/" target="_blank">Partner site</a>'
            .'<a href="https://ext.example/b" target="_blank" rel="noopener">Other partner (new window)</a>'
            .'<table><tr><th scope="col">A</th></tr><tr><td>1</td></tr></table>'
            .'<video controls src="v.mp4"><track kind="captions" src="c.vtt"></video>'
            .'<div aria-live="polite" class="toast">Saved</div>'
            .'<input name="vorname" autocomplete="given-name" aria-label="Vorname">'
            .'<div role="dialog" aria-modal="true" aria-label="Settings">x</div>'
            .'<form novalidate><input type="email" aria-label="E-Mail" autocomplete="email" name="mail"></form>'
        );

        $upper = preg_replace_callback(
            '/\b(type|role|aria-hidden|target|rel|scope|kind|aria-live|autocomplete|aria-modal|lang)="([^"]*)"/',
            fn (array $m) => $m[1].'="'.strtoupper($m[2]).'"',
            $lower,
        );
        $upper = preg_replace_callback('/<(\/?)(img|input|table|tr|th|td|video|track|a|div|button|form)\b/', fn (array $m) => '<'.$m[1].strtoupper($m[2]), $upper);

        $this->assertNotSame($lower, $upper);
        $this->assertSame($this->keys($lower), $this->keys($upper));
    }
}
