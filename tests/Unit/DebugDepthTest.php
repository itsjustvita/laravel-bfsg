<?php
namespace ItsJustVita\LaravelBfsg\Tests\Unit;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
class DebugDepthTest extends TestCase
{
    public function test_debug(): void
    {
        foreach ([255, 256, 300, 1000, 2000, 2100, 5000] as $n) {
            $h = str_repeat('<div>', $n).'<img src=a.png>'.str_repeat('</div>', $n).'<img src=b.png>';
            foreach ([0, LIBXML_PARSEHUGE] as $f) {
                $d = new \DOMDocument; libxml_use_internal_errors(true);
                $d->loadHTML($h, LIBXML_HTML_NODEFDTD | $f);
                $x = new \DOMXPath($d);
                $e = libxml_get_errors(); libxml_clear_errors();
                fwrite(STDERR, sprintf("DBG %s n=%d huge=%d div=%d img=%d err=%d first=%s\n", LIBXML_DOTTED_VERSION, $n, $f ? 1 : 0, $x->query('//div')->length, $x->query('//img')->length, count($e), $e ? trim($e[0]->message) : ''));
            }
        }
        $this->assertTrue(true);
    }
}
