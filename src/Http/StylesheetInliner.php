<?php

namespace ItsJustVita\LaravelBfsg\Http;

use Closure;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use ItsJustVita\LaravelBfsg\Css\CssParser;
use ItsJustVita\LaravelBfsg\Dom\HtmlDocument;
use ItsJustVita\LaravelBfsg\Support\Sanitizer;
use Throwable;

/**
 * Replaces same-origin `<link rel="stylesheet">` elements whose media applies to screen with
 * `<style data-bfsg-inlined="{href}">` (with the link's `media`, if any), in place so the cascade order is kept. At most `max_stylesheets`
 * are loaded, each at most `max_stylesheet_bytes`; failures leave the link and add a warning. Links inside comments,
 * `<script>`, `<style>`, `<template>`, `<noscript>`, `<textarea>` and `<title>` are not stylesheets and stay untouched,
 * and so do `disabled` links. Stylesheets on other origins are not loaded: one warning counts them (F3); those of a
 * web-font service (`bfsg.fetch.font_stylesheet_hosts`, CssParser::isFontStylesheet()) are neither loaded nor counted. The leading
 * `@import` rules of a loaded sheet are loaded too (expandImports(), F10). A sheet that stays out (a link, or an
 * `@import` kept in the inlined text) is counted by CssParser::missingStylesheets(), so the analysis knows its cascade
 * is incomplete.
 */
final class StylesheetInliner
{
    private int $maxStylesheets;

    private int $maxBytes;

    /** Warnings about imports shown per page; the others are counted in one more warning (a sheet may hold thousands). */
    private const MAX_IMPORT_WARNINGS = 20;

    /** Warnings about imports of the page being inlined (inline()). */
    private int $importWarnings = 0;

    /** The URL relative stylesheet links resolve against: the first `<base href>` (http/https) of the page, else the page URL. */
    private string $baseUrl = '';

    /** Whether the page's first `<base href>` was seen (only that one counts, whatever it holds). */
    private bool $baseSeen = false;

    /** @var array<string, true> hosts of web-font stylesheets (CssParser::fontStylesheetHosts()) */
    private array $fontHosts;

    public function __construct(?int $maxStylesheets = null, ?int $maxBytes = null)
    {
        $this->maxStylesheets = $maxStylesheets ?? (int) config('bfsg.fetch.max_stylesheets', 5);
        $this->maxBytes = $maxBytes ?? (int) config('bfsg.fetch.max_stylesheet_bytes', 524288);
        $this->fontHosts = CssParser::fontStylesheetHosts();
    }

    /**
     * @param  Closure(string): ?string  $load  absolute stylesheet URL => CSS, or null when it cannot be loaded
     * @return array{0: string, 1: list<string>} the HTML and the warnings
     */
    public function inline(string $html, string $pageUrl, Closure $load): array
    {
        $warnings = [];
        $loaded = $crossOrigin = $this->importWarnings = 0;
        $this->baseUrl = $pageUrl;
        $this->baseSeen = false;
        $out = '';
        $copied = 0;
        $pos = 0;
        $length = strlen($html);

        // A linear scan instead of one big regex: comments and raw-text or inert elements are skipped up to their end
        // (found with strpos/stripos, so a multi-megabyte inline script cannot exhaust the PCRE backtrack limit), and
        // only <link> tags outside them are candidates.
        while ($pos < $length && ($lt = strpos($html, '<', $pos)) !== false) {
            $next = strtolower(substr($html, $lt + 1, 9));

            if (str_starts_with($next, '!--')) {
                $end = strpos($html, '-->', $lt + 4);
                $pos = $end === false ? $length : $end + 3;

                continue;
            }

            if (preg_match('/^(script|style|template|noscript|textarea|title)(?![a-z0-9-])/', $next, $name) === 1) {
                $open = $this->match('/\G<'.$name[1].'\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>/i', $html, $lt);

                if ($open === false) {
                    $warnings[] = 'Stylesheet inlining stopped early: '.preg_last_error_msg().'.';

                    break;
                }

                $pos = $open === null ? $lt + 1 : $this->rawTextEnd($html, $name[1], $lt + strlen($open));

                continue;
            }

            if (! $this->baseSeen && preg_match('/^base(?![a-z0-9-])/', $next) === 1) {
                $tag = $this->match('/\G<base\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>/i', $html, $lt);

                if ($tag === false) {
                    $warnings[] = 'Stylesheet inlining stopped early: '.preg_last_error_msg().'.';

                    break;
                }

                if ($tag !== null) {
                    $this->readBase($tag, $pageUrl);
                    $pos = $lt + strlen($tag);

                    continue;
                }
            }

            if (str_starts_with($next, 'link')) {
                $tag = $this->match('/\G<link\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>/i', $html, $lt);

                if ($tag === false) {
                    $warnings[] = 'Stylesheet inlining stopped early: '.preg_last_error_msg().'.';

                    break;
                }

                if ($tag !== null) {
                    $out .= substr($html, $copied, $lt - $copied).$this->inlineLink($tag, $pageUrl, $load, $warnings, $loaded, $crossOrigin);
                    $copied = $pos = $lt + strlen($tag);

                    continue;
                }
            }

            $pos = $lt + 1;
        }

        if ($this->importWarnings > self::MAX_IMPORT_WARNINGS) {
            $warnings[] = ($this->importWarnings - self::MAX_IMPORT_WARNINGS).' more imported stylesheets were not inlined.';
        }

        if ($crossOrigin > 0) {
            $warnings[] = trans_choice('bfsg::report.fetch_warnings.cross_origin_stylesheets', $crossOrigin, ['count' => $crossOrigin]);
        }

        return [$out.substr($html, $copied), $warnings];
    }

    /**
     * Takes the `href` of the first `<base>` that has one as the base of the page's relative stylesheet links, when it
     * resolves against the page URL to an http(s) URL with a host; the same-origin test still compares with the page URL.
     */
    private function readBase(string $tag, string $pageUrl): void
    {
        $href = trim($this->attributes($tag)['href'] ?? '');

        if ($href === '') {
            return;
        }

        $this->baseSeen = true;

        try {
            $base = UriResolver::resolve(Utils::uriFor($pageUrl), Utils::uriFor($href));
        } catch (Throwable) {
            return;
        }

        if (in_array(strtolower($base->getScheme()), ['http', 'https'], true) && $base->getHost() !== '') {
            $this->baseUrl = (string) $base;
        }
    }

    /** @return string|null|false the match at $offset, null for none, false on a PCRE error */
    private function match(string $pattern, string $subject, int $offset): string|null|false
    {
        $result = preg_match($pattern, $subject, $m, 0, $offset);

        return $result === false ? false : ($result === 1 ? $m[0] : null);
    }

    /** Offset after the end tag of a raw-text element opened before $from, or the end of the document when it is not closed. */
    private function rawTextEnd(string $html, string $name, int $from): int
    {
        $needle = '</'.$name;

        while (($close = stripos($html, $needle, $from)) !== false) {
            $after = $html[$close + strlen($needle)] ?? '>';

            if ($after === '>' || $after === '/' || ctype_space($after)) {
                $end = strpos($html, '>', $close);

                return $end === false ? strlen($html) : $end + 1;
            }

            $from = $close + strlen($needle);
        }

        return strlen($html);
    }

    /**
     * The replacement for one <link> tag: a <style data-bfsg-inlined> for a same-origin screen stylesheet that could
     * be loaded within the limits, else the tag itself (with a warning when loading was attempted; a cross-origin one is
     * counted in $crossOrigin).
     *
     * @param  list<string>  $warnings
     */
    private function inlineLink(string $tag, string $pageUrl, Closure $load, array &$warnings, int &$loaded, int &$crossOrigin): string
    {
        $attributes = $this->attributes($tag);
        $rel = preg_split('/\s+/', strtolower($attributes['rel'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $href = trim($attributes['href'] ?? '');

        if (! in_array('stylesheet', $rel, true) || in_array('alternate', $rel, true) || $href === '' || isset($attributes['disabled'])
            || ! HtmlDocument::mediaAppliesToScreen($attributes['media'] ?? '')) {
            return $tag;
        }

        try {
            $url = (string) UriResolver::resolve(Utils::uriFor($this->baseUrl), Utils::uriFor($href));
        } catch (Throwable) {
            $warnings[] = 'Stylesheet '.Sanitizer::truncate(Sanitizer::terminal(UrlFetcher::redact($href)), 200).' was not inlined: not a valid URL.';

            return $tag;
        }

        if (CssParser::isFontStylesheet($url, $this->fontHosts)) {
            return $tag; // @font-face rules only: not loaded, not counted (CssParser::missingStylesheets() skips it too)
        }

        if (! $this->sameOrigin($url, $pageUrl)) {
            // Not counted when it holds only for another user preference (a dark theme): not in the base cascade anyway.
            $crossOrigin += HtmlDocument::mediaCondition($attributes['media'] ?? '') === 'preference' ? 0 : 1;

            return $tag;
        }

        $css = $this->load($url, $href, $load, $warnings, $loaded);

        if ($css === null) {
            return $tag;
        }

        $importWarnings = [];
        [$css, $unresolved] = $this->expandImports($css, $url, $href, $pageUrl, $load, $importWarnings, $loaded, $crossOrigin, [$url => true]);

        foreach ($importWarnings as $warning) {
            if (++$this->importWarnings <= self::MAX_IMPORT_WARNINGS) {
                $warnings[] = $warning;
            }
        }

        // The media query list goes along, so the cascade sees it as it sees <style media> (CssParser conditions).
        $media = trim($attributes['media'] ?? '');
        $media = $media === '' ? '' : ' media="'.htmlspecialchars($media, ENT_QUOTES | ENT_SUBSTITUTE).'"';

        return '<style data-bfsg-inlined="'.htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE).'"'.$media.'>'.str_ireplace('</style', '<\/style', implode('', $unresolved).$css).'</style>';
    }

    /**
     * The CSS at $url (named $name in warnings) through $load, counted against max_stylesheets, without a leading byte
     * order mark; null with a warning when it is over the count, cannot be loaded or is larger than max_stylesheet_bytes.
     *
     * @param  list<string>  $warnings
     */
    private function load(string $url, string $name, Closure $load, array &$warnings, int &$loaded): ?string
    {
        if ($loaded >= $this->maxStylesheets) {
            $warnings[] = "Stylesheet {$name} was not inlined: more than {$this->maxStylesheets} stylesheets.";

            return null;
        }

        $loaded++;

        try {
            $css = $load($url);
        } catch (ResponseTooLarge) {
            $warnings[] = "Stylesheet {$name} was not inlined: larger than {$this->maxBytes} bytes.";

            return null;
        } catch (Throwable $e) {
            $warnings[] = "Stylesheet {$name} could not be loaded: {$e->getMessage()}";

            return null;
        }

        if ($css === null) {
            $warnings[] = "Stylesheet {$name} could not be loaded.";

            return null;
        }

        if (strlen($css) > $this->maxBytes) {
            $warnings[] = "Stylesheet {$name} was not inlined: larger than {$this->maxBytes} bytes.";

            return null;
        }

        // A byte order mark is not CSS: before an @import it would hide the import from leadingStatements().
        return str_starts_with($css, "\xEF\xBB\xBF") ? substr($css, 3) : $css;
    }

    /**
     * $css (loaded from $url, named $name) with its leading @import rules (CssParser::leadingStatements()) replaced by
     * the text they import: same-origin sheets loaded through load(), counted against max_stylesheets like links, with
     * their own imports expanded up to CssParser::MAX_IMPORT_DEPTH levels and wrapped in the import's conditions and
     * layer (`@media <media>{@supports (<condition>){@layer <name>{…}}}`). An import of a sheet on the current chain
     * (a cycle) is dropped, as browsers do, and so is one whose conditions rule out the screen, an invalid one and one
     * of a web-font service (CssParser::isFontStylesheet()).
     * Every other import that is not loaded (another origin: counted in $crossOrigin; over the count, the
     * depth or the size; failed: a warning) is returned in the second list as `@import url("…") <media>;` (its URL as written)
     * for the caller to put
     * first in the sheet, where CssParser::missingStylesheets() counts it. The depth is bounded, so is the recursion.
     *
     * @param  list<string>  $warnings
     * @param  array<string, true>  $chain  the URLs of the sheets from the link down to this one
     * @return array{0: string, 1: list<string>}
     */
    private function expandImports(string $css, string $url, string $name, string $pageUrl, Closure $load, array &$warnings, int &$loaded, int &$crossOrigin, array $chain): array
    {
        if (stripos($css, '@import') === false) {
            return [$css, []];
        }

        $out = '';
        $copied = 0;
        $unresolved = [];

        foreach (CssParser::leadingStatements($css) as [$statement, $prelude, $start, $end]) {
            if ($statement !== 'import') {
                continue;
            }

            $out .= substr($css, $copied, $start - $copied);
            $copied = $end;
            $import = CssParser::importRule($prelude);

            if ($import === null || ! CssParser::importApplies($import)) {
                continue;
            }

            try {
                $target = (string) UriResolver::resolve(Utils::uriFor($url), Utils::uriFor(trim($import['url'])));
            } catch (Throwable) {
                continue; // not a URL: a browser loads nothing either
            }

            $imported = "{$import['url']} (imported by {$name})";

            if (isset($chain[$target]) || CssParser::isFontStylesheet($target, $this->fontHosts)) {
                continue; // a cycle, or @font-face rules only: neither loaded nor counted
            }

            $text = null;

            if (! $this->sameOrigin($target, $pageUrl)) {
                $crossOrigin += HtmlDocument::mediaCondition($import['media']) === 'preference' ? 0 : 1;
            } elseif (count($chain) > CssParser::MAX_IMPORT_DEPTH) {
                $warnings[] = "Stylesheet {$imported} was not inlined: imports nested more than ".CssParser::MAX_IMPORT_DEPTH.' levels deep.';
            } else {
                $text = $this->load($target, $imported, $load, $warnings, $loaded);
            }

            if ($text === null) {
                // As written (the output stays linear in the input), with its media so the count skips a preference-only one.
                $unresolved[] = '@import url("'.addcslashes($import['url'], "\"\\\n\r\f").'")'.($import['media'] === '' ? '' : ' '.$import['media']).';';

                continue;
            }

            [$text, $nested] = $this->expandImports($text, $target, $import['url'], $pageUrl, $load, $warnings, $loaded, $crossOrigin, $chain + [$target => true]);
            array_push($unresolved, ...$nested);
            $out .= $this->wrap($this->terminated($text), $import);
        }

        return [$out.substr($css, $copied), $unresolved];
    }

    /**
     * $css with a comment, string or blocks left open at its end closed, as the end of a sheet closes them, and every `}`
     * outside a block dropped (a browser ignores it there): imported text is followed by the rest of the importing sheet
     * and sits inside the import's wrapper, neither of which it may reach into. One linear pass.
     */
    private function terminated(string $css): string
    {
        $length = strlen($css);
        $depth = 0;
        $out = '';
        $copied = 0;

        for ($position = 0; ($position += strcspn($css, "/{}()\"'\\", $position)) < $length; $position++) {
            $char = $css[$position];

            if ($char === '/' && ($css[$position + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $position + 2);

                if ($end === false) {
                    return $out.substr($css, $copied).'*/'.str_repeat('}', $depth);
                }

                $position = $end + 1;
            } elseif ($char === '"' || $char === "'") {
                // A string ends at its quote or, unclosed, at a newline (CSS Syntax: a bad string).
                $position++;

                while (($position += strcspn($css, $char."\\\n", $position)) < $length && $css[$position] === '\\') {
                    $position += 2;
                }

                if ($position >= $length) {
                    return $out.substr($css, $copied).$char.str_repeat('}', $depth);
                }
            } elseif ($char === '\\') {
                $position++;
            } elseif ($char === '(') {
                // An unquoted url() is one token: braces and slashes inside it are part of the URL.
                $position = CssParser::unquotedUrlEnd($css, $position) ?? $position;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                if ($depth > 0) {
                    $depth--;
                } else {
                    $out .= substr($css, $copied, $position - $copied); // a stray `}` at the top level
                    $copied = $position + 1;
                }
            }
            // A `/` that does not open a comment and a lone `)` are ordinary characters.
        }

        return $out.substr($css, $copied).str_repeat('}', $depth);
    }

    /**
     * The imported text inside the import's layer, supports() condition and media query list.
     *
     * @param  array{url: string, layer: ?string, supports: ?string, media: string}  $import
     */
    private function wrap(string $css, array $import): string
    {
        if ($import['layer'] !== null) {
            $css = '@layer'.($import['layer'] === '' ? '' : ' '.$import['layer']).'{'.$css.'}';
        }

        if ($import['supports'] !== null) {
            $css = '@supports ('.$import['supports'].'){'.$css.'}';
        }

        return $import['media'] === '' ? $css : '@media '.$import['media'].'{'.$css.'}';
    }

    /** @return array<string, string> lowercased attribute name => decoded value */
    private function attributes(string $tag): array
    {
        preg_match_all('/([^\s=<>"\'\/]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/', substr($tag, 5), $matches, PREG_SET_ORDER);
        $attributes = [];

        foreach ($matches as $match) {
            $attributes[strtolower($match[1])] ??= html_entity_decode(($match[2] ?? '').($match[3] ?? '').($match[4] ?? ''), ENT_QUOTES);
        }

        return $attributes;
    }

    private function sameOrigin(string $a, string $b): bool
    {
        $origin = function (string $url): string {
            $parts = parse_url($url);
            $scheme = strtolower($parts['scheme'] ?? '');

            return $scheme.'://'.strtolower($parts['host'] ?? '').':'.($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        };

        return $origin($a) === $origin($b);
    }
}
