@php
    /** @var \App\Models\Site $site */
    /** @var \App\Sites\Rendering\BusinessCard $card */
    $logo = $site->logoUrl(400);
@endphp
<!DOCTYPE html>
<html lang="{{ $site->default_locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $card->name }} — {{ $site->name }}</title>

    {{-- A card is handed to somebody, not found in a search result — and it
         would compete with the site's own home page if it were. --}}
    <meta name="robots" content="noindex, follow">

    @include('sites.partials.styles')
</head>
<body>
<main class="vcard">
    <div class="vcard__inner">
        @if ($logo)
            <img class="vcard__logo" src="{{ $logo }}" alt="{{ $site->name }}" width="200" height="200">
        @endif

        <h1 class="vcard__name">{{ $card->name }}</h1>

        @if ($card->organisation)
            <p class="vcard__org">{{ $card->organisation }}</p>
        @endif

        @if ($card->address)
            <p class="vcard__where">{{ $card->address }}</p>
        @endif

        <div class="vcard__actions">
            @foreach ($card->actions as $action)
                {{-- The enquiry is the one filled button: it is what the card is
                     for. Everything else is a way to reach the same person. --}}
                <a class="btn {{ $action['key'] === 'enquiry' ? '' : 'btn--ghost' }}"
                   href="{{ $action['href'] }}"
                   @if ($action['external']) target="_blank" rel="noopener" @endif>
                    @include('sites.partials.action-icon', ['action' => $action['icon'], 'class' => 'vcard__icon'])
                    {{ $action['label'] }}
                </a>
            @endforeach
        </div>
    </div>

    <footer class="vcard__foot">
        @include('sites.partials.legal-foot')
    </footer>
</main>
</body>
</html>
