<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMElement;
use ItsJustVita\LaravelBfsg\Severity;

class ImageAnalyzer extends BaseAnalyzer
{
    protected string $key = 'images';

    protected string $description = 'Text alternatives for images';

    protected array $rules = ['1.1.1'];

    protected function inspect(): void
    {
        foreach ($this->query('//img[not(@alt)]') as $img) {
            $this->report('missing_alt', Severity::Error, '1.1.1', $img, ['src' => $img->getAttribute('src')]);
        }

        foreach ($this->query('//img[@alt=""]') as $img) {
            if (! $this->isDecorative($img)) {
                $this->report('possibly_decorative', Severity::Warning, '1.1.1', $img, ['src' => $img->getAttribute('src')]);
            }
        }
    }

    protected function isDecorative(DOMElement $img): bool
    {
        return in_array('presentation', $this->roles($img), true)
            || in_array('none', $this->roles($img), true)
            || strtolower($img->getAttribute('aria-hidden')) === 'true';
    }
}
