@props(['title' => null])

@php
    use App\Support\UiTranslations as T;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
{{-- A guest opening a payment link is not something a search engine should
     find, and a gateway's redirect must not be indexed either. --}}
<meta name="robots" content="noindex, nofollow">
<title>{{ $title ?? T::get('payments.heading') }} · NamibWay</title>
{{--
    Deliberately a standalone page rather than an Inertia one.

    A guest arrives here from an email or from a gateway's redirect — a plain
    page load, often on a phone on a bad connection, sometimes mid-redirect
    from another domain. Booting the whole traveller-facing application to say
    "your deposit is paid" would be slower, more fragile and would tie a page
    a *payment provider* redirects to to the front-end build.

    So: inline styles, no build step, nothing to fetch. The strings still come
    from resources/js/lang/*.json (see App\Support\UiTranslations), so there is
    one home for traveller-facing copy.
--}}
<style>
    :root { color-scheme: light; }
    * { box-sizing: border-box; }
    body {
        margin: 0;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1.5rem;
        font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        background: #f6f2ec;
        color: #2c2521;
        line-height: 1.5;
    }
    .card {
        width: 100%;
        max-width: 30rem;
        background: #fff;
        border-radius: 1rem;
        box-shadow: 0 1px 2px rgba(44, 37, 33, .06), 0 12px 32px rgba(44, 37, 33, .08);
        padding: 1.75rem;
    }
    .brand { font-size: .8rem; letter-spacing: .08em; text-transform: uppercase; color: #b5651d; font-weight: 700; }
    h1 { font-size: 1.4rem; margin: .35rem 0 1rem; }
    .lede { color: #6b5f56; margin: 0 0 1.25rem; }
    dl { display: grid; grid-template-columns: auto 1fr; gap: .4rem 1rem; margin: 0 0 1.25rem; }
    dt { color: #8b7d72; font-size: .9rem; }
    dd { margin: 0; text-align: right; font-variant-numeric: tabular-nums; }
    .total { font-size: 1.75rem; font-weight: 700; margin: 0 0 .25rem; font-variant-numeric: tabular-nums; }
    .hint { font-size: .85rem; color: #8b7d72; }
    .actions { display: flex; flex-direction: column; gap: .6rem; margin-top: 1.25rem; }
    button, .btn {
        display: block;
        width: 100%;
        padding: .8rem 1rem;
        border-radius: .6rem;
        border: 1px solid transparent;
        font: inherit;
        font-weight: 600;
        text-align: center;
        text-decoration: none;
        cursor: pointer;
    }
    .btn--primary { background: #b5651d; color: #fff; }
    .btn--quiet { background: #fff; border-color: #ded3c6; color: #6b5f56; font-weight: 500; }
    .notice { background: #fdf6ec; border: 1px solid #f0dcc0; border-radius: .6rem; padding: .75rem 1rem; font-size: .85rem; color: #7a5a2c; margin-bottom: 1.25rem; }
    .result { text-align: center; }
    .result__mark { font-size: 2.5rem; line-height: 1; margin-bottom: .5rem; }
    .ok { color: #15803d; }
    .bad { color: #b91c1c; }
    .wait { color: #b45309; }
</style>
</head>
<body>
    <main class="card">
        <div class="brand">NamibWay</div>
        {{ $slot }}
    </main>
</body>
</html>
