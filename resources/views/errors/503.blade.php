<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta http-equiv="refresh" content="15">
        <title>{{ config('app.name') }} — Just a moment</title>

        <style>
            :root {
                color-scheme: light dark;
                --bg: #ffffff;
                --fg: #1a1a1a;
                --muted: #6b6b6b;
            }

            @media (prefers-color-scheme: dark) {
                :root {
                    --bg: #0a0a0a;
                    --fg: #f7f7f7;
                    --muted: #a3a3a3;
                }
            }

            * {
                box-sizing: border-box;
            }

            html, body {
                height: 100%;
                margin: 0;
            }

            body {
                display: flex;
                align-items: center;
                justify-content: center;
                background: var(--bg);
                color: var(--fg);
                font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                text-align: center;
                padding: 1.5rem;
            }

            .card {
                max-width: 26rem;
            }

            .logo {
                height: 2.75rem;
                width: auto;
                margin: 0 auto 2rem;
                display: block;
            }

            .logo-dark {
                display: none;
            }

            @media (prefers-color-scheme: dark) {
                .logo-light { display: none; }
                .logo-dark { display: block; }
            }

            h1 {
                font-size: 1.25rem;
                font-weight: 600;
                margin: 0 0 0.5rem;
            }

            p {
                color: var(--muted);
                font-size: 0.9375rem;
                line-height: 1.5;
                margin: 0;
            }

            .spinner {
                width: 1.25rem;
                height: 1.25rem;
                margin: 1.75rem auto 0;
                border-radius: 50%;
                border: 2px solid currentColor;
                border-top-color: transparent;
                opacity: 0.4;
                animation: spin 0.8s linear infinite;
            }

            @keyframes spin {
                to { transform: rotate(360deg); }
            }
        </style>
    </head>
    <body>
        <div class="card">
            <img class="logo logo-light" src="{{ asset('images/namibway-logo-light.png') }}" alt="{{ config('app.name') }}">
            <img class="logo logo-dark" src="{{ asset('images/namibway-logo-dark.png') }}" alt="{{ config('app.name') }}">

            <h1>We'll be right back</h1>
            <p>We're rolling out an update. This page will refresh automatically — no need to do anything.</p>

            <div class="spinner" aria-hidden="true"></div>
        </div>
    </body>
</html>
