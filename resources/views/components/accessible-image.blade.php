<figure {{ $attributes->class('bfsg-image') }}>
    <img 
        src="{{ $src }}"
        @if($decorative)
            alt=""
            role="presentation"
        @else
            alt="{{ $alt }}"
        @endif
        @if($loading)
            loading="{{ $loading }}"
        @endif
        {{ $attributes->except('class') }}
    >
    @if($caption)
        <figcaption>{{ $caption }}</figcaption>
    @endif
</figure>