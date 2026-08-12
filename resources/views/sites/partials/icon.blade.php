@php
    /**
     * One 24px line mark, inline.
     *
     * Inline rather than a sprite or a font, for the same reason the stylesheet
     * is inline: a second request before the page can paint costs more on a
     * weak connection than these few hundred bytes do, and only the marks a
     * page actually uses are ever emitted. No icon library is loaded, so
     * nothing here can ever become a request to somebody else's CDN.
     *
     * Drawn in `currentColor` with no fill, so the card decides the colour and
     * the site's own accent is the only colour on the set — which is what keeps
     * one template from looking like a stock dashboard.
     *
     * `aria-hidden`: every mark sits directly above the words it illustrates, so
     * announcing it again would only repeat them.
     */
    $key = $icon ?? null;
@endphp
@if ($key)
    <svg class="card__icon" viewBox="0 0 24 24" width="24" height="24" fill="none"
         stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
         aria-hidden="true" focusable="false">
        @switch ($key)
            @case('wifi')
                <path d="M2 8.8a16 16 0 0 1 20 0M5 12.3a11 11 0 0 1 14 0M8.5 15.8a6 6 0 0 1 7 0"/><circle cx="12" cy="19.5" r=".6" fill="currentColor"/>
                @break
            @case('pool')
                <path d="M2 17c1.8 0 1.8 1.4 3.6 1.4S7.4 17 9.2 17s1.8 1.4 3.6 1.4S14.6 17 16.4 17s1.8 1.4 3.6 1.4M2 12.5c1.8 0 1.8 1.4 3.6 1.4S7.4 12.5 9.2 12.5s1.8 1.4 3.6 1.4 1.8-1.4 3.6-1.4 1.8 1.4 3.6 1.4M7 13V5.5A2.5 2.5 0 0 1 12 5M17 13V5.5A2.5 2.5 0 0 0 12 5M7 9h10"/>
                @break
            @case('restaurant')
                <path d="M5 3v7a2.5 2.5 0 0 0 5 0V3M7.5 10v11M18 3c-1.7 1-2.5 3-2.5 5.5S16.3 13 18 13v8"/>
                @break
            @case('breakfast')
                <path d="M4 9h13v5a5 5 0 0 1-5 5H9a5 5 0 0 1-5-5V9ZM17 10.5h1.5a2.5 2.5 0 0 1 0 5H17M4 22h13M8 2c-.7 1 .7 2 0 3M12 2c-.7 1 .7 2 0 3"/>
                @break
            @case('bar')
                <path d="M4 4h16l-8 8v8M4 4l8 8M8.5 20h7"/>
                @break
            @case('fire')
                <path d="M12 22a6 6 0 0 0 6-6c0-4-3.5-6.5-4.5-10-2 1.5-3 3.5-3 5.5-1-.8-1.5-2-1.5-3C6.8 10 6 12.6 6 16a6 6 0 0 0 6 6Z"/>
                @break
            @case('tent')
                <path d="M12 3 3 20h18L12 3ZM12 3v17M12 20l4.5-8.5M12 20 7.5 11.5"/>
                @break
            @case('bed')
                <path d="M3 18v-6h13a4 4 0 0 1 4 4v2M3 18v-8M3 18h18M6.5 12V9.5h5V12"/>
                @break
            @case('shower')
                <path d="M4 12h16M6 12V7a3 3 0 0 1 6 0M8 16v1M12 16v2M16 16v1M19 16v2"/>
                @break
            @case('snowflake')
                <path d="M12 2v20M4.5 6.5l15 11M19.5 6.5l-15 11M12 6l-2.5-2M12 6l2.5-2M12 18l-2.5 2M12 18l2.5 2"/>
                @break
            @case('car')
                <path d="M4 16v2.5M20 18.5V16M3 16h18v-3.2a2 2 0 0 0-.3-1L18.5 8H5.5L3.3 11.8a2 2 0 0 0-.3 1V16Z"/><circle cx="7" cy="16" r="1.3"/><circle cx="17" cy="16" r="1.3"/>
                @break
            @case('jeep')
                <path d="M2 15h20M4 15V9.5l3-4h10l3 4V15M4 15v2.5M20 15v2.5M8 5.5V15M16 5.5V15"/><circle cx="7" cy="18" r="1.6"/><circle cx="17" cy="18" r="1.6"/>
                @break
            @case('binoculars')
                <path d="M6 21a3 3 0 0 1-3-3V11l2-7h3v7h8V4h3l2 7v7a3 3 0 0 1-3 3 3 3 0 0 1-3-3v-7h-8v7a3 3 0 0 1-3 3ZM10 8h4"/>
                @break
            @case('boot')
                <path d="M6 3h4l1 7 5 3 3 2.5V21H6V3ZM6 16h13"/>
                @break
            @case('camera')
                <path d="M3 8.5h3.5L8 6h8l1.5 2.5H21v11H3v-11Z"/><circle cx="12" cy="13.5" r="3.5"/>
                @break
            @case('star')
                <path d="m12 3 2.7 5.7 6.3.9-4.5 4.4 1 6.2-5.5-2.9-5.5 2.9 1-6.2L3 9.6l6.3-.9L12 3Z"/>
                @break
            @case('sun')
                <circle cx="12" cy="12" r="4"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M5 5l1.8 1.8M17.2 17.2 19 19M19 5l-1.8 1.8M6.8 17.2 5 19"/>
                @break
            @case('tree')
                <path d="M12 22v-6M12 16a5 5 0 0 0 5-5 4 4 0 0 0-1.2-2.9A4.5 4.5 0 0 0 12 2a4.5 4.5 0 0 0-3.8 6.1A4 4 0 0 0 7 11a5 5 0 0 0 5 5ZM8.5 19h7"/>
                @break
            @case('water')
                <path d="M12 2.5c3.5 4.5 5.5 7.5 5.5 10.5A5.5 5.5 0 0 1 12 18.5a5.5 5.5 0 0 1-5.5-5.5C6.5 10 8.5 7 12 2.5ZM3 21.5c1.8 0 1.8-1.2 3.6-1.2s1.8 1.2 3.6 1.2 1.8-1.2 3.6-1.2 1.8 1.2 3.6 1.2"/>
                @break
            @case('spa')
                <path d="M12 21c0-5 2.5-9 7-11-1 5.5-3.5 9-7 11ZM12 21c0-5-2.5-9-7-11 1 5.5 3.5 9 7 11ZM12 21v-4c0-4 0-6 0-6"/>
                @break
            @case('family')
                <circle cx="8" cy="6" r="2.8"/><circle cx="17" cy="8" r="2.2"/><path d="M2.5 20v-1.5A5.5 5.5 0 0 1 8 13a5.5 5.5 0 0 1 5.5 5.5V20M15 20v-1a4 4 0 0 1 4-4 3 3 0 0 1 2.5 1.3"/>
                @break
            @case('pet')
                <path d="M12 14c-2.5 0-4.5 1.8-4.5 3.8S9 20.5 12 20.5s4.5-.7 4.5-2.7S14.5 14 12 14Z"/><ellipse cx="6" cy="11" rx="1.8" ry="2.3"/><ellipse cx="18" cy="11" rx="1.8" ry="2.3"/><ellipse cx="9.5" cy="6.5" rx="1.8" ry="2.5"/><ellipse cx="14.5" cy="6.5" rx="1.8" ry="2.5"/>
                @break
            @case('shield')
                <path d="M12 2.5 4.5 5.5V12c0 4.5 3.2 8 7.5 9.5 4.3-1.5 7.5-5 7.5-9.5V5.5L12 2.5ZM9 12l2.2 2.2L15.5 10"/>
                @break
            @case('clock')
                <circle cx="12" cy="12" r="9"/><path d="M12 6.5V12l3.5 2"/>
                @break
            @case('card')
                <rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 10h19M6 15h4"/>
                @break
        @endswitch
    </svg>
@endif
