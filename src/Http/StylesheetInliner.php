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

        // Comments and raw-text or inert elements are matched as a whole and left alone: a <link> inside them is not applied
        $html = preg_replace_callback('/<!--.*?-->|<(script|style|template|noscript|textarea|title)\b[^>]*>.*?<\/\1\s*>|<link\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/is', function (array $m) use ($pageUrl, $load, &$warnings, &$loaded) {
            if (strncasecmp($m[0], '<link', 5) !== 0) {
                return $m[0];
            }

            $attributes = $this->attributes($m[0]);
            $rel = preg_split('/\s+/', strtolower($attributes['rel'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $href = trim($attributes['href'] ?? '');

            if (! in_array('stylesheet', $rel, true) || in_array('alternate', $rel, true) || $href === '' || ! HtmlDocument::mediaAppliesToScreen($attributes['media'] ?? '')) {
                return $m[0];
            }

            $url = (string) UriResolver::resolve(Utils::uriFor($pageUrl), Utils::uriFor($href));

            if (! $this->sameOrigin($url, $pageUrl)) {
                return $m[0];
            }

            if ($loaded >= $this->maxStylesheets) {
                $warnings[] = "Stylesheet {$href} was not inlined: more than {$this->maxStylesheets} stylesheets.";

                return $m[0];
            }

            $loaded++;

            try {
                $css = $load($url);
            } catch (ResponseTooLarge) {
                $warnings[] = "Stylesheet {$href} was not inlined: larger than {$this->maxBytes} bytes.";

                return $m[0];
            } catch (Throwable $e) {
                $warnings[] = "Stylesheet {$href} could not be loaded: {$e->getMessage()}";

                return $m[0];
            }

            if ($css === null) {
                $warnings[] = "Stylesheet {$href} could not be loaded.";

                return $m[0];
            }

            if (strlen($css) > $this->maxBytes) {
                $warnings[] = "Stylesheet {$href} was not inlined: larger than {$this->maxBytes} bytes.";

                return $m[0];
            }

            return '<style data-bfsg-inlined="'.htmlspecialchars($href, ENT_QUOTES).'">'.str_ireplace('</style', '<\/style', $css).'</style>';
        }, $html) ?? $html;

        return [$html, $warnings];
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
