<?php

namespace ItsJustVita\LaravelBfsg\Dom;

/**
 * Prepares raw HTML for libxml's HTML parser in one linear pass, without regular expressions:
 *
 * - HTML5 void elements libxml does not know get an end tag, so they no longer swallow their following siblings;
 * - attribute names starting with "@" (Alpine/Vue event shorthand) become "x-on:…", names starting with "#" (Vue slot
 *   shorthand) become "v-slot:…". libxml 2.9 cannot parse either name: it drops the attribute and, at a ">" inside
 *   its value, cuts the tag short.
 *
 * Comments, the bodies of raw-text elements and quoted attribute values are never changed. A construct that is not
 * terminated before the end of the input (comment, raw-text body, start tag, quoted value) is copied verbatim
 * together with everything after it. Other markup ("<!doctype", "<?", end tags) is scanned like text: closing a void
 * element in there at worst adds a stray end tag, which the parser ignores.
 *
 * The same pass bounds the nesting depth, so the caller can decide whether the parser may build a deep tree.
 */
final class MarkupScanner
{
    /** Elements whose content is text up to their end tag; a self-closing tag ("<title/>") has none, as libxml 2.9 parses it. */
    public const RAW_TEXT = ['script', 'style', 'textarea', 'title', 'xmp'];

    /** HTML5 void elements unknown to libxml's HTML parser: without an end tag they swallow their following siblings. */
    public const UNKNOWN_VOID = ['track', 'source', 'wbr', 'embed', 'keygen'];

    /** Framework attribute shorthands and the long forms they are rewritten to. */
    private const SHORTHANDS = ['@' => 'x-on:', '#' => 'v-slot:'];

    /** Whitespace inside tags, as the HTML tokenizer sees it (CR included, which HTML normalises to LF). */
    private const SPACE = " \t\n\r\f";

    /** Whitespace allowed around an existing end tag of a void element (PCRE's \s, as in the 3.0 regex). */
    private const END_TAG_SPACE = " \t\n\v\f\r";

    private const ASCII_LETTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /** Elements without content: they never add a nesting level below themselves. */
    private const VOID = [
        'area' => true, 'base' => true, 'basefont' => true, 'br' => true, 'col' => true, 'embed' => true,
        'frame' => true, 'hr' => true, 'img' => true, 'input' => true, 'isindex' => true, 'keygen' => true,
        'link' => true, 'meta' => true, 'param' => true, 'source' => true, 'track' => true, 'wbr' => true,
    ];

    /** The depth bound stops counting above this, which keeps the per-name counts small on adversarial input. */
    private const MAX_DEPTH_BOUND = 65536;

    /**
     * @param  ?int  $depthBound  set to an upper bound of the nesting depth of well-nested markup (saturating above
     *                            MAX_DEPTH_BOUND): every start tag of a non-void element that is not self-closing
     *                            opens its name, an end tag closes it when one is open; raw text and comments are
     *                            skipped. Misnested markup can exceed it, as the parser's error recovery differs.
     */
    public static function normalize(string $html, ?int &$depthBound = null): string
    {
        $out = '';
        $copied = 0;
        $offset = 0;
        $open = [];
        $depth = 0;
        $depthBound = 0;

        while (($lt = strpos($html, '<', $offset)) !== false) {
            if (substr_compare($html, '<!--', $lt, 4) === 0) {
                $offset = self::commentEnd($html, $lt);
            } elseif (strspn($html, self::ASCII_LETTERS, $lt + 1, 1) === 1) {
                $tag = self::startTag($html, $lt);

                if ($tag === null) {
                    break;
                }

                [$offset, $name, $selfClosing, $rewritten] = $tag;

                if ($rewritten !== null) {
                    $out .= substr($html, $copied, $lt - $copied).$rewritten;
                    $copied = $offset;
                }

                $element = strtolower($name);
                $depthBound = max($depthBound, $depth + 1);

                if (in_array($element, self::UNKNOWN_VOID, true) && ! self::endTagFollows($html, $offset, $name)) {
                    $out .= substr($html, $copied, $offset - $copied).'</'.$name.'>';
                    $copied = $offset;
                } elseif (! $selfClosing && in_array($element, self::RAW_TEXT, true)) {
                    $offset = self::rawTextEnd($html, $offset, $element);
                } elseif (! $selfClosing && ! isset(self::VOID[$element]) && $depth <= self::MAX_DEPTH_BOUND) {
                    $open[$element] = ($open[$element] ?? 0) + 1;
                    $depth++;
                }
            } elseif (($html[$lt + 1] ?? '') === '/' && strspn($html, self::ASCII_LETTERS, $lt + 2, 1) === 1) {
                $element = strtolower(substr($html, $lt + 2, strcspn($html, self::SPACE.'/><', $lt + 2)));

                if (isset($open[$element])) {
                    $depth--;

                    if (--$open[$element] === 0) {
                        unset($open[$element]);
                    }
                }

                $offset = $lt + 1;
            } else {
                $offset = $lt + 1;
            }

            if ($offset === null) {
                break;
            }
        }

        return $copied === 0 ? $html : $out.substr($html, $copied);
    }

    /** Offset after the comment starting at $lt, or null when it is not closed. "<!-->" and "<!--->" are empty comments. */
    private static function commentEnd(string $html, int $lt): ?int
    {
        if (($html[$lt + 4] ?? '') === '>') {
            return $lt + 5;
        }

        if (substr($html, $lt + 4, 2) === '->') {
            return $lt + 6;
        }

        $end = strpos($html, '-->', $lt + 4);

        return $end === false ? null : $end + 3;
    }

    /**
     * Tokenizes the start tag at $lt like the HTML tokenizer: attribute names end at whitespace, "/", ">" or "=",
     * quoted values at their quote, unquoted values at whitespace or ">". Returns null when the tag is not closed, else
     * the offset after the tag, its name, whether it is self-closing, and the tag text with its shorthands rewritten
     * (null when it has none).
     *
     * @return array{int, string, bool, ?string}|null
     */
    private static function startTag(string $html, int $lt): ?array
    {
        $at = $lt + 1;
        $nameLength = strcspn($html, self::SPACE.'/>', $at);
        $name = substr($html, $at, $nameLength);
        $at += $nameLength;
        $rewritten = '';
        $from = $lt;

        while (true) {
            $gap = strspn($html, self::SPACE.'/', $at);
            $at += $gap;

            if (! isset($html[$at])) {
                return null;
            }

            if ($html[$at] === '>') {
                $selfClosing = $gap > 0 && $html[$at - 1] === '/';
                $rewritten = $from === $lt ? null : $rewritten.substr($html, $from, $at + 1 - $from);

                return [$at + 1, $name, $selfClosing, $rewritten];
            }

            if (isset(self::SHORTHANDS[$html[$at]])) {
                $rewritten .= substr($html, $from, $at - $from).self::SHORTHANDS[$html[$at]];
                $from = $at + 1;
            }

            $at += 1 + strcspn($html, self::SPACE.'/>=', $at + 1);
            $at += strspn($html, self::SPACE, $at);

            if (($html[$at] ?? '') !== '=') {
                continue;
            }

            $at += 1 + strspn($html, self::SPACE, $at + 1);
            $quote = $html[$at] ?? '';

            if ($quote === '"' || $quote === "'") {
                $close = strpos($html, $quote, $at + 1);

                if ($close === false) {
                    return null;
                }

                $at = $close + 1;
            } else {
                $at += strcspn($html, self::SPACE.'>', $at);
            }
        }
    }

    /** Whether "</name>" (any case, optional whitespace) follows at $offset. */
    private static function endTagFollows(string $html, int $offset, string $name): bool
    {
        $offset += strspn($html, self::END_TAG_SPACE, $offset);

        if (strcasecmp(substr($html, $offset, strlen($name) + 2), '</'.$name) !== 0) {
            return false;
        }

        $offset += strlen($name) + 2;
        $offset += strspn($html, self::END_TAG_SPACE, $offset);

        return ($html[$offset] ?? '') === '>';
    }

    /** Offset of the end tag closing a raw-text body that starts at $offset, or null when there is none. */
    private static function rawTextEnd(string $html, int $offset, string $element): ?int
    {
        $length = strlen($element);

        while (($close = strpos($html, '</', $offset)) !== false) {
            if (strcasecmp(substr($html, $close + 2, $length), $element) === 0
                && strspn($html, self::SPACE.'/>', $close + 2 + $length, 1) === 1) {
                return $close;
            }

            $offset = $close + 2;
        }

        return null;
    }
}
