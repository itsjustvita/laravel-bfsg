<?php

namespace ItsJustVita\LaravelBfsg\Tests\Feature;

use Illuminate\Support\Facades\Blade;
use InvalidArgumentException;
use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Components\AccessibleImage;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use ItsJustVita\LaravelBfsg\Violation;
use Throwable;

class AccessibleImageComponentTest extends TestCase
{
    private function render(string $blade): string
    {
        return trim(preg_replace('/\s+/', ' ', Blade::render($blade)) ?? '');
    }

    public function test_renders_a_bare_img_with_every_attribute_once(): void
    {
        $html = $this->render('<x-bfsg-accessible-image src="/lake.jpg" alt="Mountain lake at sunrise" class="rounded" width="300" id="hero" data-x="1" />');

        $this->assertSame('<img src="/lake.jpg" alt="Mountain lake at sunrise" class="rounded" width="300" id="hero" data-x="1">', $html);
    }

    public function test_a_caption_wraps_the_image_in_a_figure_without_copying_attributes(): void
    {
        $html = $this->render('<x-bfsg-accessible-image src="/lake.jpg" alt="Mountain lake" caption="Lake Constance, 2026" class="rounded" width="300" />');

        $this->assertSame('<figure> <img src="/lake.jpg" alt="Mountain lake" class="rounded" width="300"> <figcaption>Lake Constance, 2026</figcaption> </figure>', $html);
        $this->assertSame(1, substr_count($html, 'width="300"'));
    }

    public function test_loading_is_only_rendered_when_given(): void
    {
        $this->assertStringNotContainsString('loading=', $this->render('<x-bfsg-accessible-image src="/a.jpg" alt="A chart of sales" />'));
        $this->assertStringContainsString('loading="lazy"', $this->render('<x-bfsg-accessible-image src="/a.jpg" alt="A chart of sales" loading="lazy" />'));
    }

    public function test_decorative_images_have_an_empty_alt_and_are_hidden_without_a_redundant_role(): void
    {
        $html = $this->render('<x-bfsg-accessible-image src="/divider.svg" :decorative="true" />');

        $this->assertSame('<img src="/divider.svg" alt="" aria-hidden="true">', $html);
        $this->assertStringNotContainsString('role=', $html);
    }

    public function test_decorative_images_drop_the_callers_aria_hidden_and_role_instead_of_duplicating_them(): void
    {
        $html = $this->render('<x-bfsg-accessible-image src="/divider.svg" :decorative="true" aria-hidden="false" role="presentation" class="rule" />');

        $this->assertSame('<img src="/divider.svg" alt="" aria-hidden="true" class="rule">', $html);
    }

    public function test_a_decorative_image_with_a_caption_is_an_error(): void
    {
        $blade = '<x-bfsg-accessible-image src="/team.jpg" :decorative="true" caption="The team in 2026" />';

        try {
            Blade::render($blade);
            $this->fail("$blade rendered a caption for a hidden image");
        } catch (Throwable $e) {
            $this->assertInstanceOf(InvalidArgumentException::class, $e->getPrevious() ?? $e);
            $this->assertStringContainsString('cannot be decorative and have a caption', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        new AccessibleImage(src: '/a.jpg', caption: 'A caption', decorative: true);
    }

    public function test_a_missing_alt_text_is_an_error_not_a_silent_empty_alt(): void
    {
        foreach (['<x-bfsg-accessible-image src="/a.jpg" />', '<x-bfsg-accessible-image src="/a.jpg" alt="  " />'] as $blade) {
            try {
                Blade::render($blade);
                $this->fail("$blade rendered without an alt text");
            } catch (Throwable $e) {
                $this->assertInstanceOf(InvalidArgumentException::class, $e->getPrevious() ?? $e, $blade);
                $this->assertStringContainsString('needs a non-empty alt text', $e->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);
        new AccessibleImage(src: '/a.jpg');
    }

    public function test_the_rendered_images_pass_the_packages_own_analyzers(): void
    {
        $images = $this->render('<x-bfsg-accessible-image src="/lake.jpg" alt="Mountain lake at sunrise" width="300" />'
            .'<x-bfsg-accessible-image src="/team.jpg" alt="Our team" caption="The team in 2026" />'
            .'<x-bfsg-accessible-image src="/divider.svg" :decorative="true" />');
        $page = '<!DOCTYPE html><html lang="en"><head><title>Gallery - Company</title></head><body><main><h1>Gallery</h1>'.$images.'</main></body></html>';

        $this->assertSame([], array_map(fn (Violation $violation) => $violation->key, (new Bfsg)->analyze($page)->all()));
    }
}
