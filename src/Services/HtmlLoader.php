<?php

namespace ItsJustVita\LaravelBfsg\Services;

use DOMDocument;

/**
 * Single entry point for turning an HTML string into a DOMDocument.
 *
 * libxml decodes documents without an early <meta charset> as ISO-8859-1,
 * which garbles every non-ASCII character of a UTF-8 page. When the input is
 * valid UTF-8 and declares no charset, an XML encoding hint is prepended so
 * text (and therefore every text-based heuristic) is decoded correctly.
 */
class HtmlLoader
{
    private const ENCODING_HINT = '<?xml encoding="UTF-8">';

    public static function load(string $html, int $flags = 0): DOMDocument
    {
        $dom = new DOMDocument;

        if (trim($html) === '') {
            return $dom;
        }

        $hinted = self::needsEncodingHint($html);

        if ($hinted) {
            $html = self::ENCODING_HINT.$html;
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $dom->loadHTML($html, $flags);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($hinted) {
            self::removeProcessingInstructions($dom);
        }

        return $dom;
    }

    protected static function needsEncodingHint(string $html): bool
    {
        if (str_starts_with(ltrim($html), '<?xml')) {
            return false;
        }

        if (! mb_check_encoding($html, 'UTF-8')) {
            return false;
        }

        return preg_match('/<meta[^>]+charset\s*=/i', $html) !== 1;
    }

    protected static function removeProcessingInstructions(DOMDocument $dom): void
    {
        foreach (iterator_to_array($dom->childNodes) as $node) {
            if ($node->nodeType === XML_PI_NODE) {
                $dom->removeChild($node);
            }
        }
    }
}
