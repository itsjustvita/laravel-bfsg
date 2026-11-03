<?php

namespace ItsJustVita\LaravelBfsg\Tests\Support;

/**
 * The AN4 benchmark page (spec C7, T5): a blog index of identical cards, a footer and filler rules, about 1 MB with the
 * defaults. Each card's `p.date` (#aaa, 2.32:1) and the footer's `p.note` (#bbb, 1.92:1) fail 1.4.3; all other text passes.
 */
final class BenchmarkPage
{
    public static function cards(int $cards = 3000, int $rules = 1500): string
    {
        $css = 'body{margin:0;color:#1f2937;background-color:#fff}.grid{display:grid;gap:1rem}.card{padding:1rem;border:1px solid #e5e7eb}'
            .'.card h3{font-size:1.125rem}.date{color:#aaa}.excerpt{line-height:1.5}.more{color:#1d4ed8}.note{color:#bbb}';

        for ($i = 0; $i < $rules; $i++) {
            $css .= $i % 2 === 0
                ? sprintf('.u-%d{color:#%06x;margin:%dpx}', $i, ($i * 97) % 0x555555, $i % 16)
                : sprintf('.card .v-%d{background-color:#%06x}', $i, 0xFFFFFF - ($i * 89) % 0x222222);
        }

        $card = '<div class="card"><h3>Release notes for the spring update</h3><p class="date">1.4.2026</p>'
            .'<p class="excerpt">Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. '
            .'Ut enim ad minim veniam.</p><a class="more" href="/blog/spring-update">Read more</a></div>';

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Blog | Benchmark</title><style>'.$css.'</style></head>'
            .'<body><main><h1>Blog</h1><div class="grid">'.str_repeat($card, $cards).'</div></main>'
            .'<footer><p class="note">Prices include VAT.</p></footer></body></html>';
    }
}
