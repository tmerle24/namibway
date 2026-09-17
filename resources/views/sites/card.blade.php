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

    @include('sites.partials.icons')
    @include('sites.partials.styles')
    {{-- Only this page uses these, so only this page ships them: the shared
         stylesheet is inlined into every page of every site. --}}
@php ob_start(); @endphp
    /* ---- Digital business card ------------------------------------------
       One screen, thumb-height buttons: this page is opened by scanning a QR
       on a paper card, so it is a phone page first and a desktop page second. */

    .vcard { min-height: 100vh; display: flex; flex-direction: column; justify-content: center; padding: var(--s6) var(--s4); }
    .vcard__inner { width: 100%; max-width: 420px; margin: 0 auto; text-align: center; }
    .vcard__logo { max-width: 180px; max-height: 180px; width: auto; height: auto; margin: 0 auto var(--s4); object-fit: contain; }
    .vcard__name { font-family: var(--font-display); font-weight: 400; font-size: clamp(28px, 7vw, 36px); line-height: 1.15; margin: 0; }
    .vcard__org { margin: var(--s2) 0 0; font-size: 13px; letter-spacing: .14em; text-transform: uppercase; color: var(--accent); }
    .vcard__where { margin: var(--s3) 0 0; color: var(--slate); font-size: 15px; }
    .vcard__actions { display: grid; gap: var(--s3); margin-top: var(--s5); }
    .vcard__actions .btn {
        display: flex; align-items: center; justify-content: center; gap: 10px;
        padding: 16px 20px; font-size: 13px;
    }
    .vcard__icon { width: 18px; height: 18px; flex: none; }
    .vcard__foot { width: 100%; max-width: 420px; margin: var(--s6) auto 0; color: var(--slate); }
    .vcard__foot .foot__legal { border-top-color: var(--bone); color: var(--slate); }
    .vcard__foot .foot__row--copy { color: var(--slate); }
    .vcard__foot a { color: var(--slate); }

@php $cardCss = ob_get_clean(); @endphp
    <style>{!! \App\Sites\Rendering\InlineCss::minify((string) $cardCss) !!}</style>
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
