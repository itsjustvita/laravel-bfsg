<?php

namespace ItsJustVita\LaravelBfsg\Support;

/**
 * Page-derived text (messages with params, suggestions, element, selector, snippet, warnings, URLs) on its way to a
 * terminal, a CI log or a Markdown report. Works on code points, never bytes: 0x80–0x9F are UTF-8 continuation bytes,
 * so a byte-wise C1 strip would destroy "Ärger über Straße 😀".
 */
final class Sanitizer
{
    /**
     * C0 controls except \t and \n, DEL, C1 controls, and the bidi embedding/override (U+202A–U+202E) and isolate
     * (U+2066–U+2069) controls. One character class: linear in the input.
     */
    private const CONTROLS = '/[\x{0000}-\x{0008}\x{000B}-\x{001F}\x{007F}-\x{009F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    /** CommonMark ASCII punctuation that can start inline markup (< > & are entities instead). */
    private const MARKDOWN_PUNCTUATION = '/[\\\\`*_\[\]()!#|~]/u';

    /** Valid UTF-8 without the control characters a terminal or log viewer acts on (escape sequences, OSC, bidi). */
    public static function terminal(string $text): string
    {
        return (string) preg_replace(self::CONTROLS, '', mb_scrub($text, 'UTF-8'));
    }

    /** terminal(), then & < > as HTML entities, then inline-markup punctuation backslash-escaped: always literal text. */
    public static function markdown(string $text): string
    {
        $text = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], self::terminal($text));

        return (string) preg_replace(self::MARKDOWN_PUNCTUATION, '\\\\$0', $text);
    }

    /** At most $max code points; a cut text ends in "…" (counted in $max). */
    public static function truncate(string $text, int $max): string
    {
        $text = mb_scrub($text, 'UTF-8');

        if (mb_strlen($text, 'UTF-8') <= $max) {
            return $text;
        }

        return $max < 1 ? '' : mb_substr($text, 0, $max - 1, 'UTF-8').'…';
    }
}
