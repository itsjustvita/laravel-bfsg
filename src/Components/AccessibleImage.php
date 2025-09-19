<?php

namespace ItsJustVita\LaravelBfsg\Components;

use Illuminate\View\Component;

class AccessibleImage extends Component
{
    public function __construct(
        public string $src,
        public string $alt = '',
        public ?string $caption = null,
        public bool $decorative = false,
        public ?string $loading = 'lazy',
    ) {}
    
    public function render()
    {
        return view('bfsg::components.accessible-image');
    }
}