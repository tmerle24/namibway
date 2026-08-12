{{--
    Our website terms. Deliberately one file with its own styles: it is linked
    from the foot of every customer site, which ships none of the travel
    platform's CSS or JavaScript, and a legal text is the last page that should
    pull a bundle to be readable.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Website terms — {{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0;
            padding: 3rem 1.25rem 5rem;
            font: 16px/1.65 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: #241c15;
            background: #fbf8f1;
        }
        main { max-width: 42rem; margin: 0 auto; }
        h1 { font-size: 1.6rem; margin: 0 0 .25rem; }
        h2 { font-size: 1.05rem; margin: 2rem 0 .5rem; }
        p { margin: 0 0 .85rem; }
        .meta { color: #6b6152; font-size: .875rem; margin-bottom: 2.5rem; }
        a { color: #9c4a21; }
        @media (prefers-color-scheme: dark) {
            body { color: #f2ece0; background: #1a1611; }
            .meta { color: #b3a692; }
            a { color: #e79552; }
        }
    </style>
</head>
<body>
    <main>
        <h1>Website terms</h1>

        <p class="meta">
            {{ config('app.name') }} · Version {{ $terms->version }}@if ($terms->effective_at)
                · In force from {{ $terms->effective_at->format('j F Y') }}@endif
        </p>

        {{-- Written and edited in the panel's rich editor, and stored as the
             markup it produced. Nobody outside the admin can write here. --}}
        {!! $terms->body !!}
    </main>
</body>
</html>
