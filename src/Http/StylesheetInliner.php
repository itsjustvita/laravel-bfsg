<?php

namespace ItsJustVita\LaravelBfsg\Http;

use Closure;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use ItsJustVita\LaravelBfsg\Dom\HtmlDocument;
use Throwable;

/**
 * Replaces same-origin `<link rel="stylesheet">` elements whose media applies to screen with
 * `<style data-bfsg-inlined="{href}">`, in place so the cascade order is kept. At most `max_stylesheets`
 * are loaded, each at most `max_stylesheet_bytes`; failures leave the link and add a warning. Links inside comments,
 * `<script>`, `<style>`, `<template>`, `<noscript>`, `<textarea>` and `<title>` are not stylesheets and stay untouched.
 */
final class StylesheetInliner
{
    private int $maxStylesheets;

    private int $maxBytes;

    public function __construct(?int $maxStylesheets = null, ?int $maxBytes = null)
    {
        $this->maxStylesheets = $maxStylesheets ?? (int) config('bfsg.fetch.max_stylesheets', 5);
        $this->maxBytes = $maxBytes ?? (int) config('bfsg.fetch.max_stylesheet_bytes', 524288);
    }

    /**
     * @param  Closure(string): ?string  $load  absolute stylesheet URL => CSS, or null when it cannot be loaded
     * @return array{0: string, 1: list<string>} the HTML and the warnings
     */
    public function inline(string $html, string $pageUrl, Closure $load): array
    {
        $warnings = [];
        $loaded = 0;
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

            if (str_starts_with($next, 'link')) {
                $tag = $this->match('/\G<link\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>/i', $html, $lt);

                if ($tag === false) {
                    $warnings[] = 'Stylesheet inlining stopped early: '.preg_last_error_msg().'.';

                    break;
                }

                if ($tag !== null) {
                    $out .= substr($html, $copied, $lt - $copied).$this->inlineLink($tag, $pageUrl, $load, $warnings, $loaded);
                    $copied = $pos = $lt + strlen($tag);

                    continue;
                }
            }

            $pos = $lt + 1;
        }

        return [$out.substr($html, $copied), $warnings];
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
     * be loaded within the limits, else the tag itself (with a warning when loading was attempted).
     *
     * @param  list<string>  $warnings
     */
    private function inlineLink(string $tag, string $pageUrl, Closure $load, array &$warnings, int &$loaded): string
    {
        $attributes = $this->attributes($tag);
        $rel = preg_split('/\s+/', strtolower($attributes['rel'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $href = trim($attributes['href'] ?? '');

        if (! in_array('stylesheet', $rel, true) || in_array('alternate', $rel, true) || $href === '' || ! HtmlDocument::mediaAppliesToScreen($attributes['media'] ?? '')) {
            return $tag;
        }

        $url = (string) UriResolver::resolve(Utils::uriFor($pageUrl), Utils::uriFor($href));

        if (! $this->sameOrigin($url, $pageUrl)) {
            return $tag;
        }

        if ($loaded >= $this->maxStylesheets) {
            $warnings[] = "Stylesheet {$href} was not inlined: more than {$this->maxStylesheets} stylesheets.";

            return $tag;
        }

        $loaded++;

        try {
            $css = $load($url);
        } catch (ResponseTooLarge) {
            $warnings[] = "Stylesheet {$href} was not inlined: larger than {$this->maxBytes} bytes.";

            return $tag;
        } catch (Throwable $e) {
            $warnings[] = "Stylesheet {$href} could not be loaded: {$e->getMessage()}";

            return $tag;
        }

        if ($css === null) {
            $warnings[] = "Stylesheet {$href} could not be loaded.";

            return $tag;
        }

        if (strlen($css) > $this->maxBytes) {
            $warnings[] = "Stylesheet {$href} was not inlined: larger than {$this->maxBytes} bytes.";

            return $tag;
        }

        return '<style data-bfsg-inlined="'.htmlspecialchars($href, ENT_QUOTES).'">'.str_ireplace('</style', '<\/style', $css).'</style>';
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
