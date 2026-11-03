<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit\Dom;

use ItsJustVita\LaravelBfsg\Dom\HtmlDocument;
use ItsJustVita\LaravelBfsg\Dom\MarkupScanner;
use ItsJustVita\LaravelBfsg\Tests\Support\PerformanceBudget;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

class MarkupScannerTest extends TestCase
{
    public function test_element_lists(): void
    {
        $this->assertSame(['script', 'style', 'textarea', 'title', 'xmp'], MarkupScanner::RAW_TEXT);
        $this->assertSame(['track', 'source', 'wbr', 'embed', 'keygen'], MarkupScanner::UNKNOWN_VOID);
    }

    public function test_void_elements_after_a_large_script_are_closed(): void
    {
        $html = '<script>'.str_repeat('x', 2_000_000).'</script><video controls><source src=a.mp4><track kind=captions src=c.vtt></video>';

        $this->assertCount(1, HtmlDocument::fromHtml($html)->query('//video/track'));
    }

    public function test_at_shorthands_become_x_on(): void
    {
        $li = HtmlDocument::fromHtml('<li @click="open = !open" @keyup.enter="go()">a</li>')->query('//li')[0];

        $this->assertSame('open = !open', $li->getAttribute('x-on:click'));
        $this->assertSame('go()', $li->getAttribute('x-on:keyup.enter'));
        $this->assertSame('a', $li->textContent);
    }

    public function test_hash_slot_shorthand_becomes_v_slot(): void
    {
        $template = HtmlDocument::fromHtml('<template #default="{ item }"><b>x</b></template>')->query('//template')[0];

        $this->assertTrue($template->hasAttribute('v-slot:default'));
        $this->assertSame('{ item }', $template->getAttribute('v-slot:default'));
    }

    public function test_rewrite_leaves_values_comments_and_raw_text_alone(): void
    {
        $html = <<<'HTML'
            <a href="mailto:a@b.de" title="@home">m</a>
            <!-- <div @click="x"> -->
            <SCRIPT>if (a > b) { el.setAttribute("@click", 1) }</SCRIPT>
            <div x-on:click="a > b" @click.outside="x">d</div>
            HTML;
        $expected = <<<'HTML'
            <a href="mailto:a@b.de" title="@home">m</a>
            <!-- <div @click="x"> -->
            <SCRIPT>if (a > b) { el.setAttribute("@click", 1) }</SCRIPT>
            <div x-on:click="a > b" x-on:click.outside="x">d</div>
            HTML;

        $this->assertSame($expected, MarkupScanner::normalize($html));

        $div = HtmlDocument::fromHtml($html)->query('//div')[0];
        $this->assertSame('a > b', $div->getAttribute('x-on:click'));
        $this->assertSame('x', $div->getAttribute('x-on:click.outside'));
        $this->assertSame('d', $div->textContent);
    }

    public function test_a_quoted_greater_than_sign_keeps_the_shorthand_tag_intact(): void
    {
        $li = HtmlDocument::fromHtml('<ul><li @click="a > b" class="x">y</li></ul>')->query('//li')[0];

        $this->assertSame('a > b', $li->getAttribute('x-on:click'));
        $this->assertSame('x', $li->getAttribute('class'));
        $this->assertSame('y', $li->textContent);
    }

    /** @return array<string, array{string, string}> */
    public static function normalisations(): array
    {
        return [
            'void element' => ['<video><source src=a.mp4><track kind=captions></video>', '<video><source src=a.mp4></source><track kind=captions></track></video>'],
            'original case' => ['<SOURCE src=a><Wbr>', '<SOURCE src=a></SOURCE><Wbr></Wbr>'],
            'already closed' => ["<source src=a> \n </SOURCE ><embed></embed>", "<source src=a> \n </SOURCE ><embed></embed>"],
            'self-closing void' => ['<wbr/><keygen />', '<wbr/></wbr><keygen /></keygen>'],
            'quoted greater-than' => ['<embed src="a>b" title=\'c>d\'>x', '<embed src="a>b" title=\'c>d\'></embed>x'],
            'custom elements' => ['<source-set><wbr-x><tracker>', '<source-set><wbr-x><tracker>'],
            'inside a quoted value' => ['<a title="<source>">x</a>', '<a title="<source>">x</a>'],
            'comment' => ['<!-- <source> --><track>', '<!-- <source> --><track></track>'],
            'empty comments' => ['<!--><source><!---><track>', '<!--><source></source><!---><track></track>'],
            'raw text, any case' => ['<Script>"<source>"</SCRIPT ><source>', '<Script>"<source>"</SCRIPT ><source></source>'],
            'raw text end tag needs a delimiter' => ['<style>a</styles><wbr></style><wbr>', '<style>a</styles><wbr></style><wbr></wbr>'],
            'every raw text element' => ['<textarea><wbr></textarea><title><wbr></title><xmp><wbr></xmp><wbr>', '<textarea><wbr></textarea><title><wbr></title><xmp><wbr></xmp><wbr></wbr>'],
            'self-closing raw text tag' => ['<svg><title/></svg><source>', '<svg><title/></svg><source></source>'],
            'unquoted slash value is not self-closing' => ['<script src=/a/><source></script><wbr>', '<script src=/a/><source></script><wbr></wbr>'],
            'attribute after a quoted value' => ['<a x="1"@click="y" #s>', '<a x="1"x-on:click="y" v-slot:s>'],
            'unquoted values' => ['<a title=@home href=#top data-x=a@b>', '<a title=@home href=#top data-x=a@b>'],
            'text and end tags' => ['<p>@click #default a < @b <@x></p @click>', '<p>@click #default a < @b <@x></p @click>'],
            'void element with shorthands' => ['<source @error="x > y" src=a><track #t kind=c>', '<source x-on:error="x > y" src=a></source><track v-slot:t kind=c></track>'],
            'raw text start tag' => ['<script @load="x > 1">var a = "@b";</script>', '<script x-on:load="x > 1">var a = "@b";</script>'],
            'spaced attribute value' => ["<a @click = \"x\"\n\t#item\f=y>", "<a x-on:click = \"x\"\n\tv-slot:item\f=y>"],
            'doctype and processing instruction' => ['<!DOCTYPE html><?php echo 1 ?><source>', '<!DOCTYPE html><?php echo 1 ?><source></source>'],
        ];
    }

    #[DataProvider('normalisations')]
    public function test_normalisation(string $html, string $expected): void
    {
        $this->assertSame($expected, MarkupScanner::normalize($html));
    }

    /** @return array<string, array{string, int}> */
    public static function depthBounds(): array
    {
        return [
            'no markup' => ['text', 0],
            'nested' => ['<div><p>x</p></div>', 2],
            'siblings' => ['<div></div><div></div><p>x</p>', 1],
            'void and self-closing elements' => ['<div><img src=a><br><input><span/><source></div>', 2],
            'end tags in any case' => ['<DIV><div></Div></DIV><div>', 2],
            'unmatched end tags' => ['</div></p></div><div>', 1],
            'end tag closes only its name' => ['<a><b></a><i>', 2],
            'implied end tags still count' => ['<ul><li><li><li></ul>', 4],
            'raw text and comments' => ['<p><script><div><div></script><style><div></style><!-- <div><div> --></p>', 2],
            'deep' => [str_repeat('<div>', 3000).str_repeat('</div>', 3000), 3000],
        ];
    }

    #[DataProvider('depthBounds')]
    public function test_depth_bound(string $html, int $expected): void
    {
        MarkupScanner::normalize($html, $bound);

        $this->assertSame($expected, $bound);
    }

    /** @return array<string, array{string, string}> */
    public static function unterminated(): array
    {
        return [
            'comment' => ['<source><!-- <track> @a', '<source></source><!-- <track> @a'],
            'raw text' => ['<source><script><track></scrip', '<source></source><script><track></scrip'],
            'raw text end tag at the end' => ['<source><style><track></style', '<source></source><style><track></style'],
            'start tag' => ['<source><a @click=x', '<source></source><a @click=x'],
            'quoted value' => ['<source><a @click="x > <track>', '<source></source><a @click="x > <track>'],
            'single-quoted value' => ["<wbr><a @click='x", "<wbr></wbr><a @click='x"],
            'void tag' => ['<wbr><source src=a', '<wbr></wbr><source src=a'],
            'lone less-than' => ['<wbr><', '<wbr></wbr><'],
        ];
    }

    #[DataProvider('unterminated')]
    public function test_unterminated_constructs_are_copied_verbatim(string $html, string $expected): void
    {
        $this->assertSame($expected, MarkupScanner::normalize($html));
    }

    /** @return array<string, array{string}> */
    public static function adversarialInputs(): array
    {
        $size = 5 * 1024 * 1024;

        return [
            'unclosed tag with attributes' => ['<a '.str_repeat('x=1 ', $size / 4)],
            'unclosed tags' => [str_repeat('<a ', (int) ($size / 3))],
            'unclosed shorthand attributes' => ['<a '.str_repeat('@b ', (int) ($size / 3))],
            'one tag with many shorthands' => ['<a '.str_repeat('#b ', (int) ($size / 3)).'>'],
            'long attribute values' => [str_repeat('<a title="'.str_repeat('>', 4096).'" @click=\''.str_repeat('<', 4096).'\'>', 640)],
            'unterminated quoted value' => ['<a title="'.str_repeat('>', $size)],
            'many void elements' => [str_repeat('<source src="a.mp4">', (int) ($size / 20))],
            'many rewritten attributes' => [str_repeat('<b @c #d>', (int) ($size / 9))],
            'void elements followed by spaces' => [str_repeat('<wbr>'.str_repeat(' ', 4096), 1280)],
            'less-than signs' => [str_repeat('<', $size)],
            'spaces' => [str_repeat(' ', $size)],
            'unterminated script' => ['<script>'.str_repeat('</', (int) ($size / 2))],
            'unterminated comments' => [str_repeat('<!--', (int) ($size / 4))],
            'many raw text elements' => [str_repeat('<title>x</title>', (int) ($size / 16))],
        ];
    }

    #[Group('performance')]
    #[DataProvider('adversarialInputs')]
    public function test_normalisation_is_linear(string $html): void
    {
        $start = microtime(true);
        MarkupScanner::normalize($html);

        $this->assertLessThan(PerformanceBudget::seconds(1.0), microtime(true) - $start);
    }
}
