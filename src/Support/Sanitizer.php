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

    /**
     * Inline text: terminal(), one line (line breaks with their surrounding whitespace become one space, so page text
     * never starts a line, e.g. "::notice::" in a CI log or step summary), & < > as HTML entities, then inline-markup
     * punctuation backslash-escaped: always literal text.
     */
    public static function markdown(string $text): string
    {
        $text = (string) preg_replace('/\s*\R\s*/u', ' ', self::terminal($text));
        $text = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);

        return (string) preg_replace(self::MARKDOWN_PUNCTUATION, '\\\\$0', $text);
    }

    /**
     * json_encode() output with C1 controls (U+009B is a CSI) and bidi overrides/isolates as \uXXXX escapes:
     * JSON_UNESCAPED_UNICODE keeps text readable but would pass them raw to a terminal, CI log or client. They only
     * occur inside string literals, so the escape is lossless.
     */
    public static function escapeJsonControls(string $json): string
    {
        return (string) preg_replace_callback(
            '/[\x{0080}-\x{009F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u',
            fn (array $match): string => sprintf('\\u%04x', mb_ord($match[0], 'UTF-8')),
            $json,
        );
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
