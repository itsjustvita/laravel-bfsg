<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use ItsJustVita\LaravelBfsg\Violation;

class ViolationTest extends TestCase
{
    private function violation(array $overrides = []): Violation
    {
        return new Violation(...array_merge([
            'analyzer' => 'images',
            'key' => 'images.missing_alt',
            'severity' => Severity::Error,
            'rule' => '1.1.1',
            'params' => ['src' => 'hero.jpg'],
            'element' => 'img.hero',
            'selector' => '/html[1]/body[1]/img[1]',
            'snippet' => '<img src="hero.jpg" class="hero">',
        ], $overrides));
    }

    public function test_message_and_suggestion_resolve_through_the_translator(): void
    {
        app('translator')->addLines([
            'violations.images.missing_alt.message' => 'Image without alt text (:src)',
            'violations.images.missing_alt.suggestion' => 'Add an alt attribute',
        ], 'en', 'bfsg');
        app('translator')->addLines([
            'violations.images.missing_alt.message' => 'Bild ohne Alternativtext (:src)',
        ], 'de', 'bfsg');

        $violation = $this->violation();

        $this->assertSame('Image without alt text (hero.jpg)', $violation->message());
        $this->assertSame('Add an alt attribute', $violation->suggestion());
        $this->assertSame('Bild ohne Alternativtext (hero.jpg)', $violation->message('de'));
    }

    public function test_missing_translation_returns_the_key(): void
    {
        $violation = $this->violation(['key' => 'images.unknown_key']);

        $this->assertSame('bfsg::violations.images.unknown_key.message', $violation->message());
    }

    public function test_fingerprint_is_stable_and_depends_on_analyzer_key_rule_and_selector(): void
    {
        $a = $this->violation();
        $b = $this->violation(['params' => ['src' => 'other.jpg'], 'snippet' => '<img>']);
        $c = $this->violation(['selector' => '/html[1]/body[1]/img[2]']);

        $this->assertSame($a->fingerprint(), $b->fingerprint());
        $this->assertNotSame($a->fingerprint(), $c->fingerprint());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $a->fingerprint());
    }

    public function test_to_array_contains_every_field_and_resolved_texts(): void
    {
        app('translator')->addLines([
            'violations.images.missing_alt.message' => 'Image without alt text',
            'violations.images.missing_alt.suggestion' => 'Add alt',
        ], 'en', 'bfsg');

        $array = $this->violation(['related' => ['4.1.2'], 'tags' => ['best-practice'], 'meta' => ['approximate' => true]])->toArray();

        $this->assertSame([
            'id', 'analyzer', 'key', 'severity', 'rule', 'related', 'tags', 'message', 'suggestion',
            'element', 'selector', 'snippet', 'params', 'meta', 'auto_fixable',
        ], array_keys($array));
        $this->assertSame('error', $array['severity']);
        $this->assertSame('1.1.1', $array['rule']);
        $this->assertSame(['4.1.2'], $array['related']);
        $this->assertSame('Image without alt text', $array['message']);
        $this->assertSame(['approximate' => true], $array['meta']);
        $this->assertFalse($array['auto_fixable']);
        $this->assertSame($array, json_decode(json_encode($this->violation(['related' => ['4.1.2'], 'tags' => ['best-practice'], 'meta' => ['approximate' => true]])), true));
    }
}
