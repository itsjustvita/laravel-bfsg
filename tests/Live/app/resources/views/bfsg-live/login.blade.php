@extends('bfsg-live.layout', ['title' => 'Log in'])
@section('content')
    <h1>Log in</h1>
    <form method="post" action="/live/basic/login">
        @csrf
        <div>
            <label for="email">Email address</label>
            <input type="email" id="email" name="email" autocomplete="email">
        </div>
        <div>
            <label for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="current-password">
        </div>
        <button type="submit">Log in</button>
    </form>
@endsection
