@php
    /**
     * @var \App\Sites\Rendering\SiteActions $actions
     *
     * The strip fixed to the foot of the screen: by default call, WhatsApp and
     * the enquiry button, on a phone.
     *
     * It exists because the top of a small screen is the worst place to put the
     * one thing a visitor came to do — the bar there has to hold the business's
     * name, and everything else went behind a burger. This is the same actions
     * where a thumb already is, on every screen of the site, without scrolling
     * to the bottom to look for a number.
     *
     * Nothing is duplicated into it that is not already reachable: it is a
     * shortcut to the page's own contact details and its own enquiry band. What
     * it holds, and on which screens, is the business's own choice — see
     * App\Sites\ActionButtons — and a site that has placed nothing here gets no
     * strip rather than an empty one.
     */
    $visibility = $actions->visibility('footer');
    $buttons = $actions->buttons('footer');
@endphp
@if ($visibility !== null)
    @php
        $barClass = match ($visibility) {
            'phone' => 'at-phone',
            'desktop' => 'at-desktop',
            default => '',
        };
    @endphp

    {{-- Takes the strip's height out of the flow so the last thing on the page
         is not sitting underneath it. In the markup rather than as padding on
         the body, so the spacer and the thing it makes room for can never be
         present without each other — including on the screens where the strip
         itself is hidden. --}}
    <div class="bar__spacer {{ $barClass }}" aria-hidden="true"></div>

    <div class="bar {{ $barClass }}">
        @foreach ($buttons as $button)
            <a class="bar__item {{ $button->isPrimary() ? 'bar__item--primary' : '' }} {{ $button->deviceClass() }}"
               href="{{ $button->href }}"
               @if ($button->external) target="_blank" rel="noopener" @endif>
                @if ($button->icon)
                    @include('sites.partials.action-icon', ['action' => $button->icon])
                @endif
                {{ $button->label }}
            </a>
        @endforeach
    </div>
@endif
