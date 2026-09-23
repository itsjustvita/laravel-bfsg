@php
    $extra = trim(($decorative ? 'aria-hidden="true" ' : '').($loading !== null ? 'loading="'.e($loading).'" ' : '').$attributes->toHtml());
    $img = '<img src="'.e($src).'" alt="'.e($decorative ? '' : $alt).'"'.($extra === '' ? '' : ' '.$extra).'>';
@endphp
@if($hasCaption())
<figure>
    {!! $img !!}
    <figcaption>{{ $caption }}</figcaption>
</figure>
@else
{!! $img !!}
@endif
