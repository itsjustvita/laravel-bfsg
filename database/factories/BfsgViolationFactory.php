<?php

namespace ItsJustVita\LaravelBfsg\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use ItsJustVita\LaravelBfsg\Models\BfsgReport;
use ItsJustVita\LaravelBfsg\Models\BfsgViolation;

/** @extends Factory<BfsgViolation> */
class BfsgViolationFactory extends Factory
{
    protected $model = BfsgViolation::class;

    public function definition(): array
    {
        return [
            'report_id' => BfsgReport::factory(),
            'analyzer' => 'images',
            'key' => 'images.missing_alt',
            'severity' => 'error',
            'message' => 'Image without a text alternative (hero.jpg)',
            'element' => 'img.hero',
            'wcag_rule' => '1.1.1',
            'suggestion' => 'Add an alt attribute that describes the image',
            'fingerprint' => sha1('images|images.missing_alt|1.1.1|/html[1]/body[1]/img[1]'),
            'context' => ['selector' => '/html[1]/body[1]/img[1]', 'snippet' => '<img src="hero.jpg" class="hero">', 'params' => ['src' => 'hero.jpg'], 'meta' => [], 'related' => [], 'tags' => [], 'auto_fixable' => false],
        ];
    }
}
