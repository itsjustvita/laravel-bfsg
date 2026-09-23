<?php

namespace ItsJustVita\LaravelBfsg\Components;

use Illuminate\View\Component;
use InvalidArgumentException;

/**
 * <x-bfsg-accessible-image src="…" alt="…" /> renders a bare <img> (a <figure> only with a caption). A missing
 * alt text is an error, never a silent alt="": pass :decorative="true" for purely decorative images.
 */
class AccessibleImage extends Component
{
    public function __construct(
        public string $src,
        public string $alt = '',
        public ?string $caption = null,
        public bool $decorative = false,
        public ?string $loading = null,
    ) {
        if (! $decorative && trim($alt) === '') {
            throw new InvalidArgumentException('<x-bfsg-accessible-image> needs a non-empty alt text describing the image, or :decorative="true" for a purely decorative image.');
        }
    }

    public function hasCaption(): bool
    {
        return $this->caption !== null && trim($this->caption) !== '';
    }

    public function render()
    {
        return view('bfsg::components.accessible-image');
    }
}
