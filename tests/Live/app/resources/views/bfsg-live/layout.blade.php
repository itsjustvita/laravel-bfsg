<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} – BFSG live test</title>
    <style>
        body { font-family: sans-serif; color: #1a1a1a; background: #ffffff; }
        a:focus-visible, button:focus-visible, input:focus-visible { outline: 3px solid #1d4ed8; outline-offset: 2px; }
        .skip-link { position: absolute; left: -9999px; }
        .skip-link:focus { left: 1rem; top: 1rem; }
    </style>
</head>
<body>
    <a href="#main" class="skip-link">Skip to main content</a>
    <header>
        <nav aria-label="Main">
            <ul>
                <li><a href="/live/accessible">Home</a></li>
            </ul>
        </nav>
    </header>
    <main id="main">
        @yield('content')
    </main>
    <footer><p>&copy; 2026 BFSG live test</p></footer>
</body>
</html>
