@extends('bfsg-live.layout', ['title' => 'Rendered page'])
@section('head')
    <link rel="stylesheet" href="/bfsg-live.css">
@endsection
@section('content')
    <h1>A page rendered by JavaScript</h1>
    <div id="app"></div>
    <script>
        document.getElementById('app').innerHTML = '<p class="faint">This paragraph only exists after JavaScript ran.</p>';
    </script>
@endsection
