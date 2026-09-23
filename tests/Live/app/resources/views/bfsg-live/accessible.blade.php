@extends('bfsg-live.layout', ['title' => 'Accessible page'])
@section('content')
    <h1>Welcome to the accessible page</h1>
    <p>This page is built to follow WCAG 2.1 AA.</p>
    <h2>Newsletter</h2>
    <form method="post" action="/live/accessible">
        @csrf
        <div>
            <label for="name">Full name (required)</label>
            <input type="text" id="name" name="name" autocomplete="name" required>
        </div>
        <div>
            <label for="email">Email address (required)</label>
            <input type="email" id="email" name="email" autocomplete="email" required>
        </div>
        <button type="submit">Subscribe</button>
    </form>
    <h2>Our team</h2>
    <img src="/team.jpg" alt="Our team of five standing in front of the office" width="400" height="300">
    <p>Read more <a href="/live/accessible">about our accessibility work</a>.</p>
@endsection
