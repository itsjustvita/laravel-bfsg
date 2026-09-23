@extends('bfsg-live.layout', ['title' => 'Component page'])
@section('content')
    <h1>Images rendered by the package component</h1>
    <x-bfsg-accessible-image src="/lake.jpg" alt="Mountain lake at sunrise" class="rounded" width="300" id="bfsg-plain" />
    <x-bfsg-accessible-image src="/team.jpg" alt="Our team of five" caption="The team in 2026" width="200" />
    <x-bfsg-accessible-image src="/divider.svg" :decorative="true" />
@endsection
