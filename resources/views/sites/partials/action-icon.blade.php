@php
    /**
     * The two marks the action buttons use, inline for the same reason the
     * highlight marks are (see partials/icon.blade.php): no second request, no
     * icon library, and only what a page actually uses is emitted.
     *
     * Kept apart from that file because these are not highlights — they carry
     * no `card__icon` class and the caller says how big they are. Drawn in
     * `currentColor`, so one path works on the accent button and on the cream
     * bar without a second copy.
     */
    $class = $class ?? 'bar__icon';
@endphp
<svg class="{{ $class }}" viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"
     aria-hidden="true" focusable="false">
    @if ($action === 'whatsapp')
        <path d="M20.5 11.8a8.5 8.5 0 0 1-12.7 7.4L3.5 20.5l1.4-4.2A8.5 8.5 0 1 1 20.5 11.8Z"/>
        <path d="M9.2 8.1h1.1l.9 2-1 .8a6 6 0 0 0 2.9 2.9l.8-1 2 .9v1.2c0 .5-.5.9-1 .8a7.9 7.9 0 0 1-6.5-6.5c0-.5.3-1 .8-1.1Z"/>
    @elseif ($action === 'map')
        <path d="M12 2C8.7 2 6 4.7 6 8c0 4.5 6 12 6 12s6-7.5 6-12c0-3.3-2.7-6-6-6Z"/>
        <circle cx="12" cy="8" r="2"/>
    @elseif ($action === 'mail')
        <rect x="3" y="5" width="18" height="14" rx="2"/>
        <path d="m3.5 6.5 8.5 6 8.5-6"/>
    @elseif ($action === 'save')
        <path d="M12 3v11"/>
        <path d="m7.5 10 4.5 4.5 4.5-4.5"/>
        <path d="M4 17.5V19a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-1.5"/>
    @elseif ($action === 'globe')
        <circle cx="12" cy="12" r="9"/>
        <path d="M3.5 9.5h17M3.5 14.5h17"/>
        <path d="M12 3c2.5 2.4 3.8 5.4 3.8 9s-1.3 6.6-3.8 9c-2.5-2.4-3.8-5.4-3.8-9S9.5 5.4 12 3Z"/>
    @elseif ($action === 'quote')
        <path d="M20 12.5a7 7 0 0 1-7.6 7 9.3 9.3 0 0 1-2.6-.5L4 20.5l1.5-4.3A6.8 6.8 0 0 1 4 12.5a7 7 0 0 1 7.4-7 7.2 7.2 0 0 1 8.6 7Z"/>
    @else
        <path d="M6.4 3h3.1l1.5 3.9-2 1.5a11 11 0 0 0 5 5l1.5-2 3.9 1.5v3.1a2 2 0 0 1-2.2 2A16.6 16.6 0 0 1 4.4 5.2 2 2 0 0 1 6.4 3Z"/>
    @endif
</svg>
