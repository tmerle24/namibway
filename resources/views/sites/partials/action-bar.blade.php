@php
    /**
     * @var \App\Sites\Rendering\SiteActions $actions
     *
     * The strip fixed to the foot of the page on a phone: call, WhatsApp, book.
     *
     * It exists because the top of a small screen is the worst place to put the
     * one thing a visitor came to do — the bar there has to hold the business's
     * name, and everything else went behind a burger. This is the same three
     * actions where a thumb already is, on every screen of the site, without
     * scrolling to the bottom to look for a number.
     *
     * Nothing is duplicated into it that is not already reachable: it is a
     * shortcut to the page's own contact details and its own booking section.
     * Above 1100px it is gone entirely (the bar carries the button there), and
     * a site with no phone, no WhatsApp and nothing to book gets no strip at
     * all rather than an empty one.
     */
@endphp
@if ($actions->any())
    {{-- Takes the strip's height out of the flow so the last thing on the page
         is not sitting underneath it. In the markup rather than as padding on
         the body, so the spacer and the thing it makes room for can never be
         present without each other. --}}
    <div class="bar__spacer" aria-hidden="true"></div>

    <div class="bar">
        @if ($actions->tel)
            <a class="bar__item" href="tel:{{ $actions->tel }}">
                @include('sites.partials.action-icon', ['action' => 'phone'])
                Call
            </a>
        @endif

        @if ($actions->whatsapp)
            <a class="bar__item" href="{{ $actions->whatsapp }}" target="_blank" rel="noopener">
                @include('sites.partials.action-icon', ['action' => 'whatsapp'])
                WhatsApp
            </a>
        @endif

        @if ($actions->showsPrimaryInBar())
            <a class="bar__item bar__item--primary" href="#{{ $actions->primaryAnchor }}">{{ $actions->primaryLabel }}</a>
        @endif
    </div>
@endif
