@php
    /** @var \App\Models\Site $site */
    $done = $justConfirmed || $site->terms_accepted_at !== null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $site->name }} — confirm your website</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0; padding: 3rem 1.25rem 5rem; background: #fbf8f1; color: #241c15;
            font: 16px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        main { max-width: 40rem; margin: 0 auto; }
        h1 { font-size: 1.5rem; margin: 0 0 .5rem; }
        h2 { font-size: 1rem; margin: 2rem 0 .5rem; }
        p { margin: 0 0 .85rem; }
        .lead { color: #6b6152; }
        .doc {
            white-space: pre-wrap; background: #ffffff; border: 1px solid rgba(36,28,21,.12);
            border-radius: .6rem; padding: 1rem; font-size: .9rem; max-height: 16rem; overflow: auto;
        }
        label { display: block; font-weight: 600; margin: 1.5rem 0 .4rem; }
        input[type=text] {
            width: 100%; padding: .75rem; font: inherit; border-radius: .5rem;
            border: 1px solid rgba(36,28,21,.25); background: #fff; color: inherit;
        }
        button {
            margin-top: 1.25rem; width: 100%; padding: .9rem 1rem; border: 0; border-radius: .5rem;
            background: #4f6b3a; color: #fff; font: inherit; font-weight: 700; cursor: pointer;
        }
        .done { background: #dce4d6; border-radius: .6rem; padding: 1rem 1.25rem; }
        a { color: #9c4a21; }
        @media (prefers-color-scheme: dark) {
            body { background: #1a1611; color: #f2ece0; }
            .lead { color: #b3a692; }
            .doc, input[type=text] { background: #241f18; border-color: rgba(242,236,224,.15); color: inherit; }
            .done { background: #263429; }
            a { color: #e79552; }
        }
    </style>
</head>
<body>
<main>
    <h1>{{ $site->name }}</h1>

    @if ($done)
        <div class="done">
            <p><strong>Confirmed{{ $site->terms_accepted_by ? ' by '.$site->terms_accepted_by : '' }}.</strong></p>
            <p style="margin:0">
                Your website is at <a href="{{ $site->publicUrl() }}">{{ $site->publicUrl() }}</a>.
                Anything on it can still be changed — write to us and we will change it.
            </p>
        </div>
    @else
        <p class="lead">
            Have a look at <a href="{{ $site->publicUrl() }}">your website</a> first. Confirming puts it
            online under your name.
        </p>

        <h2>What you are confirming</h2>
        {{-- Never open a directive straight after a word: Blade's own regex
             requires a non-word character before the @, so `below@if` is left
             as text while the matching `@endif` is compiled — which is an
             unbalanced if and a view that will not compile. --}}
        <p>
            That the content is right, that the pictures are yours or yours to publish, and that you
            accept the two pages below.
        </p>

        @if ($termsUrl)
            <p>
                You also accept the
                <a href="{{ $termsUrl }}">{{ config('app.name') }} website terms</a>.
            </p>
        @endif

        <h2>Privacy</h2>
        <div class="doc">{{ $privacy }}</div>

        <h2>Legal notice</h2>
        <div class="doc">{{ $imprint }}</div>

        <p style="margin-top:1rem"><small>{{ $copyright }}</small></p>

        {{-- Posting back to the address this page was reached at keeps the
             signature, which is what authorises the whole thing. --}}
        <form method="post" action="{{ request()->fullUrl() }}">
            @csrf
            <label for="confirmed_by">Your name</label>
            <input type="text" id="confirmed_by" name="confirmed_by" maxlength="255" required
                   autocomplete="name" placeholder="Who is confirming this">

            @error('confirmed_by')
                <p style="color:#a13d2f;margin:.4rem 0 0">{{ $message }}</p>
            @enderror

            <button type="submit">Confirm and publish</button>
        </form>
    @endif
</main>
</body>
</html>
