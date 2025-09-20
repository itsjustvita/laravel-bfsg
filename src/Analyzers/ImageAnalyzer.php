<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMDocument;
use DOMXPath;

class ImageAnalyzer
{
    protected array $violations = [];

    public function analyze(DOMDocument $dom): array
    {
        $this->violations = [];
        $xpath = new DOMXPath($dom);

        // Find all images without alt attribute
        $images = $xpath->query('//img[not(@alt)]');

        foreach ($images as $img) {
            $this->violations[] = [
                'type' => 'error',
                'rule' => 'WCAG 1.1.1',
                'element' => 'img',
                'message' => 'Image without alt text found',
                'src' => $img->getAttribute('src'),
                'suggestion' => 'Add an alt attribute to describe the image',
                'auto_fixable' => true,
            ];
        }

        // Find images with empty alt text that are not decorative
        $imagesWithEmptyAlt = $xpath->query('//img[@alt=""]');

        foreach ($imagesWithEmptyAlt as $img) {
            if (! $this->isDecorative($img)) {
                $this->violations[] = [
                    'type' => 'warning',
                    'rule' => 'WCAG 1.1.1',
                    'element' => 'img',
                    'message' => 'Image with empty alt text may not be decorative',
                    'src' => $img->getAttribute('src'),
                    'suggestion' => 'Verify if the image is truly decorative or needs descriptive text',
                    'auto_fixable' => false,
                ];
            }
        }

        return ['issues' => $this->violations];
    }

    protected function isDecorative($img): bool
    {
        // Check if role="presentation" or aria-hidden="true"
        return $img->hasAttribute('role') && $img->getAttribute('role') === 'presentation'
            || $img->hasAttribute('aria-hidden') && $img->getAttribute('aria-hidden') === 'true';
    }
}
