<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'FIMS') }}</title>
        <link rel="icon" type="image/png" href="{{ asset('image/LNULogo.png') }}">
        <style>
            body {
                margin: 0;
                font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                background: #f8fafc;
                color: #0f172a;
            }
            .page {
                max-width: 720px;
                width: 100%;
                padding: 2rem;
                text-align: center;
            }
            h1 {
                margin: 0;
                font-size: 2.25rem;
                letter-spacing: -0.04em;
            }
            p {
                color: #475569;
                line-height: 1.75;
                margin: 1rem 0 1.5rem;
            }
            .links a {
                color: #2563eb;
                text-decoration: none;
                margin: 0 0.5rem;
            }
        </style>
    </head>
    <body>
        <div class="page">
            <h1>{{ config('app.name', 'FIMS') }}</h1>
            <p>Welcome to the application. This page has been cleaned and the default Laravel welcome content has been removed.</p>
            @if (Route::has('login'))
                <div class="links">
                    @auth
                        <a href="{{ url('/dashboard') }}">Dashboard</a>
                    @else
                        <a href="{{ route('login') }}">Log in</a>
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}">Register</a>
                        @endif
                    @endauth
                </div>
            @endif
        </div>
    </body>
</html>
