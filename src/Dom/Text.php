<?php

namespace ItsJustVita\LaravelBfsg\Dom;

final class Text
{
    private const TRAILING = '/[\s\.,:;!?…»›>\x{2192}\x{2794}\x{27A1}\-–—]+$/u';

    public static function normalize(string $text): string
    {
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    public static function truncate(string $text, int $max = 120, string $marker = '…'): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_strimwidth($text, 0, $max, $marker, 'UTF-8');
    }

    public static function lower(string $text): string
    {
        return mb_strtolower($text, 'UTF-8');
    }

    public static function stripTrailingPunctuation(string $text): string
    {
        return rtrim(preg_replace(self::TRAILING, '', $text) ?? $text);
    }

    /** Case-insensitive whole-word (or whole-phrase) match; letters and digits delimit words. */
    public static function containsWord(string $text, string $phrase): bool
    {
        $pattern = '/(?<![\pL\pN])'.preg_quote(self::lower(self::normalize($phrase)), '/').'(?![\pL\pN])/u';

        return preg_match($pattern, self::lower(self::normalize($text))) === 1;
    }

    /** @param  list<string>  $phrases */
    public static function containsAnyWord(string $text, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if (self::containsWord($text, $phrase)) {
                return true;
            }
        }

        return false;
    }

    public static function isBlank(?string $text): bool
    {
        return $text === null || self::normalize($text) === '';
    }

    public static function length(string $text): int
    {
        return mb_strlen($text, 'UTF-8');
    }
}
