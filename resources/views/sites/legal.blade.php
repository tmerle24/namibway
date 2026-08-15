@php
    /** @var \App\Models\Site $site */
    /** @var string $title */
    /** @var string $body */
@endphp
<!DOCTYPE html>
<html lang="{{ $site->default_locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title }} — {{ $site->name }}</title>

    {{-- Not because it is secret, but because these pages have no business
         competing with the site itself in a search result. --}}
    <meta name="robots" content="noindex, follow">

    @include('sites.partials.styles')
</head>
<body>
    <script>document.documentElement.classList.add('js');</script>

    <header class="nav nav--solid" id="nav">
        <div class="nav__inner">
            @include('sites.partials.brand', ['href' => $site->pageUrl()])
            <nav class="nav__links">
                <a href="{{ $site->pageUrl() }}">Back to the site</a>
            </nav>
        </div>
    </header>

    <main>
        <section class="section section--legal">
            <div class="wrap wrap--narrow">
                <h1>{{ $title }}</h1>

                {{-- Escaped and then given its line breaks back. This text is
                     edited by the business, so it is never markup: a paste from
                     a word processor must not be able to put a script on their
                     own website. --}}
                <div class="prose">{!! nl2br(e($body)) !!}</div>
            </div>
        </section>
    </main>

    <footer class="foot">
        <div class="wrap">
            @include('sites.partials.legal-foot')
        </div>
    </footer>
</body>
</html>
